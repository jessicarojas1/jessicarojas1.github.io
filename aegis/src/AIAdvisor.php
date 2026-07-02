<?php
declare(strict_types=1);

/**
 * AIAdvisor — AI-assisted control gap analysis.
 *
 * Providers (selected by the `provider` field of the `ai_settings` blob or the
 * individual `ai_provider` setting):
 *   - `claude`            → Anthropic Messages API (hosted HTTPS).
 *   - `openai`            → OpenAI Chat Completions API (hosted HTTPS).
 *   - `ollama`            → self-hosted, OpenAI-compatible endpoint (air-gapped).
 *   - `openai_compatible` → any OpenAI-compatible base URL (proxy / gateway).
 *
 * The last two are config-driven by a `base_url` (e.g. an internal Ollama host
 * `http://ollama.internal:11434`) so air-gapped sites can run the AI Advisor
 * with no public egress. The base URL is vetted with the same SSRF *infra*
 * guard used for SMTP/S3 (`Ssrf::isDangerousInfraHost`): RFC-1918 internal
 * hosts are allowed, but loopback / link-local / cloud-metadata are refused.
 *
 * Provider, key, base URL, and model are loaded from the `settings` table.
 * No Composer — raw cURL calls only.
 */
class AIAdvisor {

    /**
     * Standard human-review disclosure shown wherever AI output is presented.
     * AIAdvisor never writes records — it only returns suggestions for a human
     * to review and act on (ISO 42001 human-oversight principle).
     */
    public const DISCLAIMER = 'AI-generated suggestions are for informational purposes only and do not '
        . 'constitute legal, regulatory, or compliance advice. Results may be inaccurate or incomplete. '
        . 'All recommendations must be reviewed by qualified personnel before implementation (ISO 42001).';

    /**
     * Global, admin-controlled kill-switch. Returns false when the `ai_enabled`
     * setting is explicitly off, regardless of whether an API key is configured.
     * Absent setting ⇒ enabled (backward compatible with existing installs).
     */
    public static function globallyEnabled(): bool {
        try {
            $row = Database::fetchOne("SELECT value FROM settings WHERE key = 'ai_enabled' LIMIT 1");
        } catch (\Throwable) {
            return true;
        }
        if (!$row) return true;
        $v = strtolower(trim((string)($row['value'] ?? '')));
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** True only when a provider is fully configured AND the global switch is on. */
    public static function isEnabled(): bool {
        return self::providerConfigured(self::getConfig()) && self::globallyEnabled();
    }

    /**
     * Pure check that a provider config is usable. Hosted providers (claude,
     * openai) require an API key; self-hosted OpenAI-compatible providers
     * (ollama, openai_compatible) require a base URL instead — Ollama accepts
     * an empty key. No DB / network access — safe to unit-test.
     *
     * @param array{provider?:string,api_key?:string,base_url?:string} $cfg
     */
    public static function providerConfigured(array $cfg): bool {
        $provider = trim((string)($cfg['provider'] ?? ''));
        if ($provider === '') {
            return false;
        }
        if (in_array($provider, ['ollama', 'openai_compatible'], true)) {
            return trim((string)($cfg['base_url'] ?? '')) !== '';
        }
        return trim((string)($cfg['api_key'] ?? '')) !== '';
    }

    /**
     * Build the chat-completions endpoint for an OpenAI-compatible base URL.
     * Idempotent: respects a base URL that already includes `/v1` or the full
     * `/chat/completions` path. Pure function — safe to unit-test.
     */
    public static function openAiCompatEndpoint(string $baseUrl): string {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        if (preg_match('#/chat/completions$#', $baseUrl)) {
            return $baseUrl;
        }
        if (preg_match('#/v1$#', $baseUrl)) {
            return $baseUrl . '/chat/completions';
        }
        return $baseUrl . '/v1/chat/completions';
    }

    /**
     * Redact obvious secrets/PII from text before it is sent to an external LLM.
     * Opt-in AI still shouldn't leak emails, IPs, API keys, or bearer tokens that
     * happen to appear in control titles or descriptions. Pure function.
     */
    public static function redact(string $text): string {
        $patterns = [
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/'  => '[redacted-email]',
            '/\bBearer\s+[A-Za-z0-9._\-]+/i'                      => 'Bearer [redacted-token]',
            '/\b(?:sk|pk|rk)-[A-Za-z0-9]{16,}\b/'                 => '[redacted-key]',
            '/\bAKIA[0-9A-Z]{16}\b/'                               => '[redacted-key]',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/'                        => '[redacted-ip]',
            '/\b[A-Fa-f0-9]{32,}\b/'                              => '[redacted-secret]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    /**
     * Returns an array of actionable remediation suggestion strings for the given
     * compliance package, or [] if AI is disabled / no API key is configured.
     *
     * @param  int   $packageId  compliance_packages.id
     * @return string[]
     */
    public static function suggestControlGaps(int $packageId): array {
        $config = self::getConfig();
        if (!self::providerConfigured($config) || !self::globallyEnabled()) {
            return [];
        }
        // Tamper-evident audit event for AI use (NIST AI RMF / ISO 42001 traceability).
        Auth::log('ai.gap_analysis', 'compliance_package', $packageId);

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
            $raw = self::complete($prompt, $config);
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
        if (!self::providerConfigured($config) || !self::globallyEnabled()) {
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

        $standardName = self::redact((string)($package['standard_name'] ?? $package['name'] ?? ''));

        $prompt  = "You are a GRC compliance analyst. Write a concise executive-level narrative paragraph (3-5 sentences) ";
        $prompt .= "describing the current compliance posture for the {$standardName} framework. ";
        $prompt .= "Key metrics: {$compliancePct}% overall compliance ({$totalImplemented}/{$totalControls} controls implemented), ";
        $prompt .= (int)($package['non_compliant'] ?? 0) . " non-compliant controls, ";
        $prompt .= (int)($package['partial'] ?? 0) . " partially compliant, ";
        $prompt .= (int)($package['not_started'] ?? 0) . " not started. ";
        $prompt .= "The narrative should be professional, factual, and appropriate for a board-level audience. ";
        $prompt .= "Return only the narrative paragraph — no headings, bullet points, or JSON.";

        try {
            return self::complete($prompt, $config);
        } catch (\Throwable $e) {
            error_log('AIAdvisor::generateNarrative error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Dispatch a single completion to the configured provider.
     * @param array|null $config pre-loaded config to avoid a second settings read.
     */
    private static function complete(string $prompt, ?array $config = null): string {
        $config ??= self::getConfig();
        return match ($config['provider']) {
            'openai'                        => self::callOpenAI($prompt, $config),
            'ollama', 'openai_compatible'   => self::callOpenAICompatible($prompt, $config),
            default                         => self::callClaude($prompt, $config),
        };
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Load AI configuration from settings table.
     * Tries a single JSON blob key `ai_settings` first, then falls back to
     * individual `ai_provider` / `ai_api_key` / `ai_base_url` / `ai_model` rows.
     *
     * @return array{provider: string, api_key: string, base_url: string, model: string}
     */
    private static function getConfig(): array {
        // Try JSON blob first
        $blob = Database::fetchOne(
            "SELECT value FROM settings WHERE key = 'ai_settings' LIMIT 1"
        );
        if ($blob && !empty($blob['value'])) {
            $parsed = json_decode($blob['value'], true);
            // A blob is "usable" when it carries a key (hosted providers) OR a
            // base URL (self-hosted OpenAI-compatible providers like Ollama).
            if (is_array($parsed) && (!empty($parsed['api_key']) || !empty($parsed['base_url']))) {
                return [
                    'provider' => (string)($parsed['provider'] ?? 'claude') ?: 'claude',
                    'api_key'  => !empty($parsed['api_key'])
                        ? Security::decryptSetting((string)$parsed['api_key'])
                        : '',
                    'base_url' => trim((string)($parsed['base_url'] ?? '')),
                    'model'    => trim((string)($parsed['model'] ?? '')),
                ];
            }
        }

        // Fall back to individual rows
        $rows = Database::fetchAll(
            "SELECT key, value FROM settings WHERE key IN ('ai_provider','ai_api_key','ai_base_url','ai_model')"
        );
        $config = ['provider' => 'claude', 'api_key' => '', 'base_url' => '', 'model' => ''];
        foreach ($rows as $row) {
            switch ($row['key']) {
                case 'ai_provider': $config['provider'] = (string)$row['value'] ?: 'claude';        break;
                case 'ai_api_key':  $config['api_key']  = Security::decryptSetting((string)$row['value']); break;
                case 'ai_base_url': $config['base_url'] = trim((string)$row['value']);               break;
                case 'ai_model':    $config['model']    = trim((string)$row['value']);               break;
            }
        }

        return $config;
    }

    /**
     * Call Anthropic Claude API (claude-haiku-4-5-20251001).
     *
     * @throws \RuntimeException on curl/HTTP error
     */
    private static function callClaude(string $prompt, ?array $config = null): string {
        $config  = $config ?? self::getConfig();
        $apiKey  = $config['api_key'];
        $model   = ($config['model'] ?? '') !== '' ? (string)$config['model'] : 'claude-haiku-4-5-20251001';

        $payload = json_encode([
            'model'      => $model,
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
            self::logInference('claude', $model, $prompt, 0, $ms, false, $curlErr);
            throw new \RuntimeException('Claude cURL error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            self::logInference('claude', $model, $prompt, 0, $ms, false, 'HTTP ' . $httpCode);
            throw new \RuntimeException('Claude HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
        }

        $data   = json_decode((string)$response, true);
        $tokens = (int)(($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0));
        self::logInference('claude', $model, $prompt, $tokens, $ms, true, null);
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
    private static function callOpenAI(string $prompt, ?array $config = null): string {
        $config = $config ?? self::getConfig();
        $apiKey = $config['api_key'];
        $model  = ($config['model'] ?? '') !== '' ? (string)$config['model'] : 'gpt-4o-mini';

        $payload = json_encode([
            'model'    => $model,
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
            self::logInference('openai', $model, $prompt, 0, $ms, false, $curlErr);
            throw new \RuntimeException('OpenAI cURL error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            self::logInference('openai', $model, $prompt, 0, $ms, false, 'HTTP ' . $httpCode);
            throw new \RuntimeException('OpenAI HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
        }

        $data   = json_decode((string)$response, true);
        $tokens = (int)(($data['usage']['total_tokens'] ?? 0));
        self::logInference('openai', $model, $prompt, $tokens, $ms, true, null);
        return (string)($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Call a self-hosted, OpenAI-compatible endpoint (Ollama or any gateway
     * that speaks the `/v1/chat/completions` contract). The base URL comes from
     * config so air-gapped sites route AI to an internal inference host instead
     * of a public API. Ollama needs no API key; when one is set it is sent as a
     * bearer token (proxies/gateways that require auth).
     *
     * Security: the operator-configured host is vetted with the SSRF *infra*
     * guard (loopback / link-local / cloud-metadata refused, RFC-1918 allowed),
     * and the resolved IP is pinned into the connection (CURLOPT_RESOLVE) to
     * defeat DNS-rebinding between validation and fetch.
     *
     * @throws \RuntimeException on misconfiguration or curl/HTTP error
     */
    private static function callOpenAICompatible(string $prompt, ?array $config = null): string {
        $config   = $config ?? self::getConfig();
        $provider = (string)($config['provider'] ?? 'ollama');
        $baseUrl  = trim((string)($config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new \RuntimeException(ucfirst($provider) . ' base URL is not configured');
        }

        $endpoint = self::openAiCompatEndpoint($baseUrl);

        // SSRF: block only the ranges that are never a valid inference host
        // (loopback, link-local/metadata, unspecified). RFC-1918 stays allowed
        // because the internal Ollama host legitimately lives there.
        $host = parse_url($endpoint, PHP_URL_HOST) ?? '';
        if ($host === '' || Ssrf::isDangerousInfraHost($host)) {
            throw new \RuntimeException('Blocked AI endpoint host: ' . ($host ?: '(none)'));
        }

        $model  = ($config['model'] ?? '') !== '' ? (string)$config['model'] : 'llama3.1';
        $apiKey = (string)($config['api_key'] ?? '');

        $payload = json_encode([
            'model'      => $model,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 1024,
            'stream'     => false,
        ], JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $t0 = microtime(true);
        $ch = curl_init($endpoint);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        // Pin the validated IP to prevent DNS rebinding (best-effort).
        $resolve = Ssrf::curlResolve($endpoint);
        if ($resolve !== null) {
            $opts[CURLOPT_RESOLVE] = $resolve;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($curlErr) {
            self::logInference($provider, $model, $prompt, 0, $ms, false, $curlErr);
            throw new \RuntimeException(ucfirst($provider) . ' cURL error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            self::logInference($provider, $model, $prompt, 0, $ms, false, 'HTTP ' . $httpCode);
            throw new \RuntimeException(ucfirst($provider) . ' HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
        }

        $data   = json_decode((string)$response, true);
        $tokens = (int)(($data['usage']['total_tokens'] ?? 0));
        self::logInference($provider, $model, $prompt, $tokens, $ms, true, null);
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
        // Redact secrets/PII that may appear in control text before it leaves the org.
        $controlList  = self::redact($controlList);
        $standardName = self::redact($standardName);

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
