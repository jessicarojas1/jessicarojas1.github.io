<?php
declare(strict_types=1);

/**
 * Mailer — records every outbound message in mail_outbox and, when an SMTP
 * transport is configured via environment variables, delivers it. With no mail
 * config the message simply stays 'queued' in the outbox, so the whole pipeline
 * is functional and auditable without credentials (and an admin can inspect it).
 *
 * Env:
 *   MAIL_TRANSPORT = smtp | queued (default: queued)
 *   MAIL_FROM, MAIL_FROM_NAME
 *   SMTP_HOST, SMTP_PORT (default 587), SMTP_USER, SMTP_PASS,
 *   SMTP_SECURE = tls | none (default tls)
 */
final class Mailer {

    private static function env(string $k, string $default = ''): string {
        return (string)($_ENV[$k] ?? getenv($k) ?: $default);
    }

    public static function transport(): string {
        $t = strtolower(self::env('MAIL_TRANSPORT', 'queued'));
        return $t === 'smtp' ? 'smtp' : 'queued';
    }

    /**
     * Queue (and, if SMTP is configured, send) a message. Always returns the
     * outbox row id. Never throws — delivery failures are recorded on the row.
     */
    public static function send(string $toEmail, string $subject, string $html, string $text, ?int $userId = null): int {
        $id = Database::insert('mail_outbox', [
            'user_id'   => $userId,
            'to_email'  => $toEmail,
            'subject'   => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'transport' => self::transport(),
            'status'    => 'queued',
        ]);

        if (self::transport() === 'smtp') {
            try {
                self::smtpSend($toEmail, $subject, $html, $text);
                Database::update('mail_outbox', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            } catch (\Throwable $e) {
                Database::update('mail_outbox', ['status' => 'failed', 'error' => substr($e->getMessage(), 0, 480)], 'id = ?', [$id]);
            }
        }
        return $id;
    }

    /** Minimal SMTP (with optional STARTTLS + AUTH LOGIN) over a socket. */
    private static function smtpSend(string $to, string $subject, string $html, string $text): void {
        $host = self::env('SMTP_HOST');
        if ($host === '') { throw new \RuntimeException('SMTP_HOST not set'); }
        $port = (int)(self::env('SMTP_PORT', '587'));
        $secure = strtolower(self::env('SMTP_SECURE', 'tls'));
        $user = self::env('SMTP_USER');
        $pass = self::env('SMTP_PASS');
        $from = self::env('MAIL_FROM', self::env('SMTP_FROM', $user ?: 'no-reply@localhost'));
        $fromName = self::env('MAIL_FROM_NAME', self::env('SMTP_FROM_NAME', 'PALADIN'));

        $transport = $secure === 'ssl' ? "ssl://{$host}" : $host;
        $fp = @fsockopen($transport, $port, $errno, $errstr, 12);
        if (!$fp) { throw new \RuntimeException("connect failed: {$errstr}"); }
        stream_set_timeout($fp, 12);

        $expect = function (int ...$codes) use ($fp): string {
            $data = ''; $line = '';
            do {
                $line = fgets($fp, 515);
                if ($line === false) { throw new \RuntimeException('SMTP read timeout'); }
                $data .= $line;
            } while (isset($line[3]) && $line[3] === '-');
            $code = (int)substr($data, 0, 3);
            if (!in_array($code, $codes, true)) { throw new \RuntimeException('SMTP: ' . trim($data)); }
            return $data;
        };
        $send = function (string $cmd) use ($fp): void { fwrite($fp, $cmd . "\r\n"); };

        $expect(220);
        $send('EHLO paladin'); $expect(250);
        if ($secure === 'tls') {
            $send('STARTTLS'); $expect(220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed');
            }
            $send('EHLO paladin'); $expect(250);
        }
        if ($user !== '') {
            $send('AUTH LOGIN'); $expect(334);
            $send(base64_encode($user)); $expect(334);
            $send(base64_encode($pass)); $expect(235);
        }
        $send('MAIL FROM:<' . $from . '>'); $expect(250);
        $send('RCPT TO:<' . $to . '>'); $expect(250, 251);
        $send('DATA'); $expect(354);

        $boundary = 'pal-' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeName($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . self::dotStuff($text) . "\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . self::dotStuff($html) . "\r\n"
            . "--{$boundary}--\r\n.\r\n";
        fwrite($fp, $body);
        $expect(250);
        $send('QUIT');
        fclose($fp);
    }

    private static function dotStuff(string $s): string {
        // RFC 5321: a line starting with '.' must be escaped as '..'
        return preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $s)) ?? $s;
    }

    private static function encodeName(string $s): string {
        return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
}
