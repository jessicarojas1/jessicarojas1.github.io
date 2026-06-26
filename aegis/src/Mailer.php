<?php
declare(strict_types=1);

class Mailer {

    public static function sendFromSettings(string $to, string $toName, string $subject, string $htmlBody): bool {
        $rows = Database::fetchAll(
            "SELECT key, value FROM settings WHERE key LIKE 'smtp_%' OR key = 'email_notifications'"
        );
        $cfg = array_column($rows, 'value', 'key');

        if (empty($cfg['smtp_host'])) return false;
        if (($cfg['email_notifications'] ?? '0') === '0') return false;

        return self::send(
            $to, $toName, $subject, $htmlBody,
            $cfg['smtp_host'],
            (int)($cfg['smtp_port'] ?? 587),
            $cfg['smtp_user'] ?? '',
            Security::decryptSetting($cfg['smtp_pass'] ?? ''),
            $cfg['smtp_from'] ?? $cfg['smtp_user'] ?? '',
            $cfg['smtp_from_name'] ?? 'AEGIS GRC',
            (bool)($cfg['smtp_tls'] ?? true)
        );
    }

    /**
     * Strip CR/LF from a string to prevent header injection.
     */
    private static function sanitizeHeaderValue(string $value): string {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Validate an email address and strip it of any characters that could
     * break SMTP commands (angle brackets, CR/LF, NUL).
     * Returns the sanitized address or empty string if invalid.
     */
    private static function sanitizeAddress(string $addr): string {
        $addr = str_replace(["\r", "\n", "\0", '<', '>'], '', $addr);
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $addr;
    }

    public static function send(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        string $host,
        int    $port = 587,
        string $user = '',
        string $pass = '',
        string $from = '',
        string $fromName = 'AEGIS GRC',
        bool   $tls = true
    ): bool {
        // Sanitize addresses to prevent SMTP injection
        $to       = self::sanitizeAddress($to);
        $fromAddr = self::sanitizeAddress($from ?: $user);
        if (!$to || !$fromAddr) {
            error_log("[Mailer] Invalid or missing email address (to={$to}, from={$fromAddr})");
            return false;
        }

        // Sanitize free-text values used in headers
        $toName   = self::sanitizeHeaderValue($toName);
        $fromName = self::sanitizeHeaderValue($fromName);
        $subject  = self::sanitizeHeaderValue($subject);
        $host     = self::sanitizeHeaderValue($host);

        // SSRF guard: an SMTP relay may legitimately be a private host, but never
        // loopback or cloud-metadata/link-local (credential-exfil escalation).
        if (Ssrf::isDangerousInfraHost($host)) {
            error_log("[Mailer] Refusing SMTP connection to blocked host: {$host}");
            return false;
        }

        try {
            $errno  = 0;
            $errstr = '';
            $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
            if (!$socket) {
                error_log("[Mailer] Connection failed to {$host}:{$port} — {$errstr}");
                return false;
            }
            stream_set_timeout($socket, 15);

            self::readResponse($socket); // greeting

            self::command($socket, "EHLO " . (gethostname() ?: 'localhost'), 250);

            if ($tls) {
                self::command($socket, "STARTTLS", 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log("[Mailer] TLS handshake failed");
                    fclose($socket);
                    return false;
                }
                self::command($socket, "EHLO " . (gethostname() ?: 'localhost'), 250);
            }

            if ($user !== '') {
                self::command($socket, "AUTH LOGIN", 334);
                self::command($socket, base64_encode($user), 334);
                self::command($socket, base64_encode($pass), 235);
            }

            self::command($socket, "MAIL FROM:<{$fromAddr}>", 250);
            self::command($socket, "RCPT TO:<{$to}>", 250);
            self::command($socket, "DATA", 354);

            $msgId  = '<' . bin2hex(random_bytes(8)) . '@aegisgrc>';
            $date   = date('r');
            $encSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $encToName   = $toName ? '=?UTF-8?B?' . base64_encode($toName) . '?= ' : '';

            $message  = "Date: {$date}\r\n";
            $message .= "From: {$encFromName} <{$fromAddr}>\r\n";
            $message .= "To: {$encToName}<{$to}>\r\n";
            $message .= "Subject: {$encSubject}\r\n";
            $message .= "Message-ID: {$msgId}\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: quoted-printable\r\n";
            $message .= "\r\n";
            $message .= quoted_printable_encode($htmlBody);
            $message .= "\r\n.\r\n";

            fwrite($socket, $message);
            self::readResponse($socket); // 250

            self::command($socket, "QUIT", 221);
            fclose($socket);
            return true;

        } catch (Exception $e) {
            error_log("[Mailer] Send failed: " . $e->getMessage());
            return false;
        }
    }

    private static function command(mixed $socket, string $cmd, int $expectedCode): string {
        fwrite($socket, $cmd . "\r\n");
        [$code, $msg] = self::readResponse($socket);
        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP expected {$expectedCode}, got {$code}: {$msg}");
        }
        return $msg;
    }

    private static function readResponse(mixed $socket): array {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break; // last line of multi-line
        }
        $code = (int)substr($response, 0, 3);
        $msg  = ltrim(substr($response, 4));
        return [$code, $msg];
    }
}
