<?php
/**
 * APEX - JSON response helpers.
 *
 * All API endpoints converge on Response::json(...) so we have a single
 * place that handles headers, status codes, and graceful error envelopes.
 */

declare(strict_types=1);

namespace Apex;

final class Response
{
    public static function json(mixed $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Wrap a single record. */
    public static function ok(mixed $data, array $meta = []): never
    {
        $body = ['data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }
        self::json($body, 200);
    }

    /** Wrap a list of records with optional meta (count, filters, etc). */
    public static function list(array $items, array $meta = []): never
    {
        $meta = array_merge(['count' => count($items)], $meta);
        self::json(['data' => $items, 'meta' => $meta], 200);
    }

    public static function created(mixed $data): never
    {
        self::json(['data' => $data], 201);
    }

    public static function error(string $message, int $status = 400, string $code = 'BAD_REQUEST'): never
    {
        self::json(['error' => $message, 'code' => $code], $status);
    }

    public static function unauthorized(string $message = 'Authentication required'): never
    {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403, 'FORBIDDEN');
    }

    public static function notFound(string $message = 'Not found'): never
    {
        self::error($message, 404, 'NOT_FOUND');
    }

    public static function serverError(string $message = 'Server error'): never
    {
        self::error($message, 500, 'SERVER_ERROR');
    }

    /** Decode the JSON request body, returning [] on empty / invalid. */
    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
