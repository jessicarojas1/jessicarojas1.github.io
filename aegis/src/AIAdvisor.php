<?php
declare(strict_types=1);

/**
 * AIAdvisor — AI-assisted control gap analysis.
 *
 * Supports Claude (claude-haiku-4-5-20251001) and OpenAI (gpt-4o-mini).
 * Provider and API key are loaded from the `settings` table.
 * No Composer — raw cURL calls only.
 */
class AIAdvisor {

    /**
     * Returns an array of actionable remediation suggestion strings for the given
     * compliance package, or [] if AI is disabled / no API key is configured.
     *
     * @param  int   $packageId  compliance_packages.id
     * @return string[]
     */
    public static function suggestControlGaps(int $packageId): array {
        $config = self::getConfig();
        if (empty($config['api_key']) || empty($config['provider'])) {
            return [];
        }

        // Load package info
        $package = Database::fetchOne(
            "SELECT cp.id, cp.name,
                    cs.name AS standard_name
             FROM compliance_packages cp
             LEFT JOIN compliance_standards cs ON cs.id = cp.standard_id
             WHERE cp.id = ?",
            [$packageId]
        );
        if (!$package) {
            return [];
        }

        // Load non-compliant / not-started controls
        $gaps = Database::fetchAll(
            "SELECT co.code, co.title, co.description, ci.status, ci.notes
             FROM control_implementations ci
             JOIN compliance_objectives co ON co.id = ci.objective_id
             WHERE co.package_id = ?
               AND ci.status IN ('non_compliant','not_started')
             ORDER BY co.code
             LIMIT 30",
            [$packageId]
        );

        if (empty($gaps)) {
            return [];
        }

        $prompt = self::buildGapPrompt($package, $gaps);

        $raw = '';
        try {
            if ($config['provider'] === 'openai') {
                $raw = self::callOpenAI($prompt);
            } else {
                $raw = self::callClaude($prompt);
            }
        } catch (\Throwable $e) {
            // Log silently — return empty on error
            error_log('AIAdvisor::suggestControlGaps error: ' . $e->getMessage());
            return [];
        }

        if (empty($raw)) {
            return [];
        }

        // Extract JSON array from response (model may add prose before/after)
        $json = self::extractJsonArray($raw);
        $suggestions = json_decode($json, true);

        if (!is_array($suggestions)) {
            return [];
        }

        // Ensure all elements are strings, limit to 10
        $out = [];
        foreach ($suggestions as $s) {
            if (is_string($s) && trim($s) !== '') {
                $out[] = trim($s);
                if (count($out) >= 10) break;
            }
        }

        return $out;
    }

    /**
     * Returns a plain-text compliance narrative paragraph for the given package.
     * Returns empty string if AI is disabled or on error.
     *
     * @param  int    $packageId  compliance_packages.id
     * @return string
     */
    public static function generateNarrative(int $packageId): string {
        $config = self::getConfig();
        if (empty($config['api_key']) || empty($config['provider'])) {
            return '';
        }

        $package = Database::fetchOne(
            "SELECT cp.id, cp.name, cp.objectives_count,
                    cs.name AS standard_name,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'compliant')      AS compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant')  AS non_compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'partial')        AS partial,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'not_started')    AS not_started
             FROM compliance_packages cp
             LEFT JOIN compliance_standards cs ON cs.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 3
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.id = ?
             GROUP BY cp.id, cp.name, cp.objectives_count, cs.name",
            [$packageId]
        );

        if (!$package) {
            return '';
        }

        $totalImplemented = (int)($package['compliant'] ?? 0);
        $totalControls    = (int)($package['objectives_count'] ?? 1);
        $compliancePct    = $totalControls > 0
            ? round($totalImplemented / $totalControls * 100)
            : 0;

        $standardName = $package['standard_name'] ?? $package['name'];

        $prompt  = "You are a GRC compliance analyst. Write a concise executive-level narrative paragraph (3-5 sentences) ";
        $prompt .= "describing the current compliance posture for the {$standardName} framework. ";
        $prompt .= "Key metrics: {$compliancePct}% overall compliance ({$totalImplemented}/{$totalControls} controls implemented), ";
        $prompt .= (int)($package['non_compliant'] ?? 0) . " non-compliant controls, ";
        $prompt .= (int)($package['partial'] ?? 0) . " partially compliant, ";
        $prompt .= (int)($package['not_started'] ?? 0) . " not started. ";
        $prompt .= "The narrative should be professional, factual, and appropriate for a board-level audience. ";
        $prompt .= "Return only the narrative paragraph — no headings, bullet points, or JSON.";

        try {
            if ($config['provider'] === 'openai') {
                return self::callOpenAI($prompt);
            }
            return self::callClaude($prompt);
        } catch (\Throwable $e) {
            error_log('AIAdvisor::generateNarrative error: ' . $e->getMessage());
            return '';
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Load AI configuration from settings table.
     * Tries a single JSON blob key `ai_settings` first, then falls back to
     * individual `ai_provider` / `ai_api_key` rows.
     *
     * @return array{provider: string, api_key: string}
     */
    private static function getConfig(): array {
        // Try JSON blob first
        $blob = Database::fetchOne(
            "SELECT value FROM settings WHERE key = 'ai_settings' LIMIT 1"
        );
        if ($blob && !empty($blob['value'])) {
            $parsed = json_decode($blob['value'], true);
            if (is_array($parsed) && !empty($parsed['api_key'])) {
                return [
                    'provider' => $parsed['provider'] ?? 'claude',
                    'api_key'  => Security::decryptSetting((string)$parsed['api_key']),
                ];
            }
        }

        // Fall back to individual rows
        $rows = Database::fetchAll(
            "SELECT key, value FROM settings WHERE key IN ('ai_provider','ai_api_key')"
        );
        $config = ['provider' => 'claude', 'api_key' => ''];
        foreach ($rows as $row) {
            if ($row['key'] === 'ai_provider') $config['provider'] = (string)$row['value'];
            if ($row['key'] === 'ai_api_key')  $config['api_key']  = Security::decryptSetting((string)$row['value']);
        }

        return $config;
    }

    /**
     * Call Anthropic Claude API (claude-haiku-4-5-20251001).
     *
     * @throws \RuntimeException on curl/HTTP error
     */
    private static function callClaude(string $prompt): string {
        $config  = self::getConfig();
        $apiKey  = $config['api_key'];

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $t0 = microtime(true);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($curlErr) {
            self::logInference('claude', 'claude-haiku-4-5-20251001', $prompt, 0, $ms, false, $curlErr);
            throw new \RuntimeException('Claude cURL error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            self::logInference('claude', 'claude-haiku-4-5-20251001', $prompt, 0, $ms, false, 'HTTP ' . $httpCode);
            throw new \RuntimeException('Claude HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
        }

        $data   = json_decode((string)$response, true);
        $tokens = (int)(($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0));
        self::logInference('claude', 'claude-haiku-4-5-20251001', $prompt, $tokens, $ms, true, null);
        return (string)($data['content'][0]['text'] ?? '');
    }

    private static function logInference(string $provider, string $model, string $prompt,
                                          int $tokens, int $ms, bool $success, ?string $error): void {
        try {
            $userId = class_exists('Auth') ? Auth::id() : null;
            Database::query(
                "INSERT INTO ai_inference_log (user_id, provider, model, action, input_hash, tokens_used, duration_ms, success, error_msg)
                 VALUES (?, ?, ?, 'compliance_analysis', ?, ?, ?, ?, ?)",
                [$userId, $provider, $model, hash('sha256', $prompt), $tokens, $ms, $success, $error]
            );
        } catch (\Throwable) {}
    }

    /**
     * Call OpenAI Chat Completions API (gpt-4o-mini).
     *
     * @throws \RuntimeException on curl/HTTP error
     */
    private static function callOpenAI(string $prompt): string {
        $config = self::getConfig();
        $apiKey = $config['api_key'];

        $payload = json_encode([
            'model'    => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 1024,
        ], JSON_UNESCAPED_UNICODE);

        $t0 = microtime(true);
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($curlErr) {
            self::logInference('openai', 'gpt-4o-mini', $prompt, 0, $ms, false, $curlErr);
            throw new \RuntimeException('OpenAI cURL error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            self::logInference('openai', 'gpt-4o-mini', $prompt, 0, $ms, false, 'HTTP ' . $httpCode);
            throw new \RuntimeException('OpenAI HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
        }

        $data   = json_decode((string)$response, true);
        $tokens = (int)(($data['usage']['total_tokens'] ?? 0));
        self::logInference('openai', 'gpt-4o-mini', $prompt, $tokens, $ms, true, null);
        return (string)($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Build the prompt for control gap analysis.
     *
     * @param  array $package  Row from compliance_packages JOIN compliance_standards
     * @param  array $gaps     Rows of non-compliant/not-started controls
     * @return string
     */
    private static function buildGapPrompt(array $package, array $gaps): string {
        $standardName = $package['standard_name'] ?? $package['name'] ?? 'Unknown Standard';

        $controlList = '';
        foreach ($gaps as $i => $gap) {
            $code   = $gap['code']   ?? ('Control ' . ($i + 1));
            $title  = $gap['title']  ?? '';
            $status = $gap['status'] ?? 'not_started';
            $controlList .= "- [{$code}] {$title} (status: {$status})\n";
        }

        return <<<PROMPT
You are a GRC expert specializing in {$standardName} compliance.

The following compliance controls are currently non-compliant or not started:

{$controlList}

Provide a JSON array of up to 10 specific, actionable remediation suggestions to address these gaps.
Each element should be a plain string describing one concrete step the organization can take.
Focus on practical actions — policies to create, technical controls to implement, processes to establish, or training to conduct.
Return ONLY the JSON array with no additional text, explanation, or markdown formatting.

Example format:
["Implement multi-factor authentication for all privileged accounts.", "Establish a formal asset inventory process with quarterly reviews."]
PROMPT;
    }

    /**
     * Extract the first JSON array from a potentially prose-wrapped response.
     */
    private static function extractJsonArray(string $raw): string {
        $raw = trim($raw);

        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $raw, $m)) {
            $raw = $m[1];
        }

        // Find first [...] block
        $start = strpos($raw, '[');
        $end   = strrpos($raw, ']');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($raw, $start, $end - $start + 1);
        }

        return $raw;
    }
}
