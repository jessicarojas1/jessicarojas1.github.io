<?php
/**
 * Upload — validates uploaded files against the configured allowlist
 * (extensions + max size) and a MIME sniff, then stores them via Storage
 * with a randomized filename. Returns a normalized result array.
 */
final class Upload {

    /** @return array{ok:bool,error:?string,key:?string,name:?string,mime:?string,size:int,hash:?string} */
    public static function handle(array $file, string $directory): array {
        $fail = fn(string $m) => ['ok' => false, 'error' => $m, 'key' => null, 'name' => null, 'mime' => null, 'size' => 0, 'hash' => null];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $fail('No file was uploaded.');
        }
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return $fail('File upload failed (code ' . (int)$file['error'] . ').');
        }

        $settings = [];
        try {
            foreach (Database::fetchAll("SELECT key, value FROM settings WHERE key IN ('upload_max_size_mb','upload_allowed_types')") as $r) {
                $settings[$r['key']] = $r['value'];
            }
        } catch (Throwable) {}
        $maxMb   = (int)($settings['upload_max_size_mb'] ?? 25);
        $allowed = array_filter(array_map('trim', explode(',', strtolower($settings['upload_allowed_types'] ?? 'pdf,docx,xlsx,png,jpg'))));

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) return $fail('Uploaded file is empty.');
        if ($size > $maxMb * 1024 * 1024) return $fail("File exceeds the {$maxMb} MB limit.");

        $original = (string)($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($allowed && !in_array($ext, $allowed, true)) {
            return $fail("File type '.{$ext}' is not allowed.");
        }

        $tmp  = (string)($file['tmp_name'] ?? '');
        $mime = function_exists('mime_content_type') && is_file($tmp) ? (mime_content_type($tmp) ?: 'application/octet-stream') : 'application/octet-stream';
        $hash = is_file($tmp) ? hash_file('sha256', $tmp) : null;

        try {
            $key = Storage::put($directory, $tmp, $original);
        } catch (Throwable $e) {
            return $fail('Could not store the uploaded file.');
        }

        return ['ok' => true, 'error' => null, 'key' => $key, 'name' => $original, 'mime' => $mime, 'size' => $size, 'hash' => $hash];
    }
}
