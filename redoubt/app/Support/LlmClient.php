<?php

declare(strict_types=1);

namespace Redoubt\Support;

use Throwable;

/**
 * Minimal client for a self-hosted, in-enclave LLM (Ollama-compatible
 * /api/generate). Used only to compose answers from already-permission-trimmed
 * context — the model never receives anything the asker could not see. Entirely
 * optional: if not configured or the call fails, callers fall back to retrieval.
 */
final class LlmClient
{
    /** Generate a completion. Returns the text, or null on any failure. */
    public static function generate(string $prompt): ?string
    {
        $url = Config::assistantLlmUrl();
        if ($url === null) {
            return null;
        }
        $payload = json_encode([
            'model'   => Config::assistantLlmModel(),
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => ['temperature' => 0.2],
        ], JSON_UNESCAPED_SLASHES);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($resp === false || $code < 200 || $code >= 300) {
                return null;
            }
            $data = json_decode((string) $resp, true);
            $text = is_array($data) ? ($data['response'] ?? ($data['message']['content'] ?? null)) : null;
            $text = is_string($text) ? trim($text) : null;
            return ($text !== null && $text !== '') ? $text : null;
        } catch (Throwable $e) {
            error_log('[LLM] generate failed: ' . $e->getMessage());
            return null;
        }
    }
}
