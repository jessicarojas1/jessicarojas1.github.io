<?php
/**
 * PAL Storage Abstraction
 *
 * Supports two drivers configured via the `settings` table:
 *   storage_driver  = 'local' (default) | 's3'
 *   s3_bucket       = my-pal-uploads
 *   s3_region       = us-east-1
 *   s3_access_key   = AKIA...
 *   s3_secret_key   = ...
 *   s3_endpoint     = (optional — for MinIO, Cloudflare R2, etc.)
 *   s3_public_url   = (optional CDN base URL for public files)
 *
 * Usage:
 *   $path = Storage::put('uploads/evidence', $tmpFile, $originalName);
 *   $url  = Storage::url($path);
 *   Storage::delete($path);
 */
class Storage {

    private static ?array $_cfg = null;

    private static function cfg(): array {
        if (self::$_cfg !== null) return self::$_cfg;
        $keys = ['storage_driver','s3_bucket','s3_region','s3_access_key','s3_secret_key','s3_endpoint','s3_public_url'];
        $rows = Database::fetchAll(
            "SELECT key, value FROM settings WHERE key = ANY(?::text[])",
            ['{' . implode(',', $keys) . '}']
        );
        $cfg = [];
        foreach ($rows as $r) { $cfg[$r['key']] = $r['value']; }
        // Decrypt sensitive values at rest (NIST 800-53 SC-28)
        if (isset($cfg['s3_secret_key'])) {
            $cfg['s3_secret_key'] = Security::decryptSetting($cfg['s3_secret_key']);
        }
        self::$_cfg = $cfg;
        return $cfg;
    }

    /** Store a file from a temp path. Returns the stored path (relative key). */
    public static function put(string $directory, string $tmpPath, string $originalName): string {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeName = bin2hex(random_bytes(16)) . ($ext ? ".{$ext}" : '');
        $key      = rtrim($directory, '/') . '/' . $safeName;
        $cfg      = self::cfg();

        if (($cfg['storage_driver'] ?? 'local') === 's3') {
            self::s3Put($key, $tmpPath, $cfg);
        } else {
            $dest = PAL_ROOT . '/' . $key;
            $dir  = dirname($dest);
            if (!is_dir($dir)) { mkdir($dir, 0750, true); }
            if (!move_uploaded_file($tmpPath, $dest)) {
                // Fallback for non-upload temp files (e.g. tests)
                copy($tmpPath, $dest);
            }
        }
        return $key;
    }

    /** Read file contents. */
    public static function get(string $key): string|false {
        $cfg = self::cfg();
        if (($cfg['storage_driver'] ?? 'local') === 's3') {
            return self::s3Get($key, $cfg);
        }
        return file_get_contents(PAL_ROOT . '/' . $key);
    }

    /** Delete a stored file. */
    public static function delete(string $key): void {
        $cfg = self::cfg();
        if (($cfg['storage_driver'] ?? 'local') === 's3') {
            self::s3Delete($key, $cfg);
        } else {
            $path = PAL_ROOT . '/' . $key;
            if (file_exists($path)) { unlink($path); }
        }
    }

    /**
     * Presigned download URL for private files, or direct path for local.
     * $expiresIn: seconds until URL expires (S3 only, default 15 min).
     */
    public static function url(string $key, int $expiresIn = 900): string {
        $cfg = self::cfg();
        if (($cfg['storage_driver'] ?? 'local') === 's3') {
            if (!empty($cfg['s3_public_url'])) {
                return rtrim($cfg['s3_public_url'], '/') . '/' . ltrim($key, '/');
            }
            return self::s3PresignedUrl($key, $expiresIn, $cfg);
        }
        return '/' . ltrim($key, '/');
    }

    // ── S3 implementation (AWS Signature Version 4) ───────────────────────────

    private static function s3Put(string $key, string $tmpPath, array $cfg): void {
        $body        = file_get_contents($tmpPath);
        $contentType = mime_content_type($tmpPath) ?: 'application/octet-stream';
        self::s3Request('PUT', $key, $cfg, $body, ['Content-Type' => $contentType]);
    }

    private static function s3Get(string $key, array $cfg): string|false {
        $result = self::s3Request('GET', $key, $cfg);
        return $result['body'] ?? false;
    }

    private static function s3Delete(string $key, array $cfg): void {
        self::s3Request('DELETE', $key, $cfg);
    }

    private static function s3PresignedUrl(string $key, int $expiresIn, array $cfg): string {
        $region    = $cfg['s3_region'] ?? 'us-east-1';
        $bucket    = $cfg['s3_bucket'] ?? '';
        $accessKey = $cfg['s3_access_key'] ?? '';
        $secretKey = $cfg['s3_secret_key'] ?? '';
        $endpoint  = $cfg['s3_endpoint'] ?? "https://s3.{$region}.amazonaws.com";
        $endpoint  = rtrim($endpoint, '/');

        $now        = new DateTimeImmutable('UTC');
        $date       = $now->format('Ymd');
        $datetime   = $now->format('Ymd\THis\Z');
        $credScope  = "{$date}/{$region}/s3/aws4_request";
        $credential = "{$accessKey}/{$credScope}";
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $host       = parse_url($endpoint, PHP_URL_HOST);

        $queryParams = [
            'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date'       => $datetime,
            'X-Amz-Expires'    => (string)$expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [
            'GET',
            "/{$encodedKey}",
            $queryString,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $signingKey = self::s3DeriveKey($secretKey, $date, $region);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credScope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$endpoint}/{$bucket}/{$encodedKey}?{$queryString}&X-Amz-Signature={$signature}";
    }

    private static function s3Request(
        string $method, string $key, array $cfg,
        string $body = '', array $extraHeaders = []
    ): array {
        $region    = $cfg['s3_region'] ?? 'us-east-1';
        $bucket    = $cfg['s3_bucket'] ?? '';
        $accessKey = $cfg['s3_access_key'] ?? '';
        $secretKey = $cfg['s3_secret_key'] ?? '';
        $endpoint  = $cfg['s3_endpoint'] ?? "https://s3.{$region}.amazonaws.com";
        $endpoint  = rtrim($endpoint, '/');

        $now         = new DateTimeImmutable('UTC');
        $date        = $now->format('Ymd');
        $datetime    = $now->format('Ymd\THis\Z');
        $bodyHash    = hash('sha256', $body);
        $encodedKey  = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $host        = parse_url($endpoint, PHP_URL_HOST);
        $url         = "{$endpoint}/{$bucket}/{$encodedKey}";

        $headers = array_merge([
            'Host'                 => $host,
            'x-amz-date'          => $datetime,
            'x-amz-content-sha256'=> $bodyHash,
        ], $extraHeaders);
        if ($body !== '') { $headers['Content-Length'] = (string)strlen($body); }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders    = '';
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            $canonicalHeaders .= "{$lk}:" . trim($v) . "\n";
            $signedHeaders    .= ($signedHeaders ? ';' : '') . $lk;
        }

        $canonicalRequest = implode("\n", [
            $method,
            "/{$bucket}/{$encodedKey}",
            '',
            $canonicalHeaders,
            $signedHeaders,
            $bodyHash,
        ]);

        $credScope  = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = self::s3DeriveKey($secretKey, $date, $region);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = [];
        foreach ($headers as $k => $v) { $curlHeaders[] = "{$k}: {$v}"; }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $responseBody = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseCode >= 400) {
            error_log("PAL Storage S3 error {$responseCode} on {$method} {$key}: {$responseBody}");
        }
        return ['code' => $responseCode, 'body' => $responseBody];
    }

    private static function s3DeriveKey(string $secret, string $date, string $region): string {
        $kDate    = hash_hmac('sha256', $date,         'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region,       $kDate, true);
        $kService = hash_hmac('sha256', 's3',          $kRegion, true);
        return     hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** Flush the config cache (useful after admin updates settings). */
    public static function clearCache(): void { self::$_cfg = null; }
}
