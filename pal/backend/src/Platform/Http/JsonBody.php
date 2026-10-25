<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Strict JSON request body decoding: correct content type, bounded size, object at the top level.
 */
final class JsonBody
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(Request $request, int $maxBytes = 65_536): array
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            throw new ApiProblem(415, 'unsupported_media_type', 'Send the request body as application/json.');
        }
        $raw = $request->getContent();
        if (\strlen($raw) > $maxBytes) {
            throw new ApiProblem(413, 'payload_too_large', 'The request body is too large.');
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'invalid_json', 'The request body is not valid JSON.');
        }
        if (!\is_array($decoded) || array_is_list($decoded) && [] !== $decoded) {
            throw new ApiProblem(400, 'invalid_json', 'The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
