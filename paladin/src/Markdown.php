<?php
declare(strict_types=1);

/**
 * Markdown — a small, safe CommonMark-subset → HTML converter for importing
 * pages. Supports ATX headings, bold/italic, inline code, fenced code blocks,
 * links/images, blockquotes, ordered/unordered lists, and horizontal rules.
 *
 * All text is HTML-escaped during conversion, and callers should still run the
 * output through Security::sanitizeHtml() for defense in depth (URL-scheme
 * allowlisting, attribute stripping).
 */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $n = count($lines);
        $out = [];
        $i = 0;

        while ($i < $n) {
            $line = $lines[$i];

            // Fenced code block ```
            if (preg_match('/^\s*```/', $line)) {
                $buf = [];
                $i++;
                while ($i < $n && !preg_match('/^\s*```/', $lines[$i])) { $buf[] = $lines[$i]; $i++; }
                $i++; // consume closing fence
                $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                continue;
            }

            // Blank line
            if (trim($line) === '') { $i++; continue; }

            // Horizontal rule
            if (preg_match('/^ {0,3}([-*_]) *(?:\1 *){2,}$/', $line)) { $out[] = '<hr>'; $i++; continue; }

            // ATX heading
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $lvl = strlen($m[1]);
                $out[] = "<h{$lvl}>" . self::inline($m[2]) . "</h{$lvl}>";
                $i++;
                continue;
            }

            // Blockquote (consecutive '>' lines)
            if (preg_match('/^\s*>\s?(.*)$/', $line)) {
                $buf = [];
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $mm)) { $buf[] = $mm[1]; $i++; }
                $out[] = '<blockquote>' . self::inline(implode(' ', $buf)) . '</blockquote>';
                continue;
            }

            // Unordered list
            if (preg_match('/^\s*[-*+]\s+/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $mm)) { $items[] = '<li>' . self::inline($mm[1]) . '</li>'; $i++; }
                $out[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            // Ordered list
            if (preg_match('/^\s*\d+\.\s+/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^\s*\d+\.\s+(.*)$/', $lines[$i], $mm)) { $items[] = '<li>' . self::inline($mm[1]) . '</li>'; $i++; }
                $out[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }

            // Paragraph — gather until a blank line or a new block starts
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== ''
                   && !preg_match('/^(\s*```|#{1,6}\s|\s*>|\s*[-*+]\s|\s*\d+\.\s| {0,3}[-*_] *(?:[-*_] *){2,}$)/', $lines[$i])) {
                $buf[] = $lines[$i];
                $i++;
            }
            if ($buf) $out[] = '<p>' . self::inline(implode(' ', $buf)) . '</p>';
        }

        return implode("\n", $out);
    }

    /** Inline-level conversion. Escapes text, protects code spans, then emphasis/links. */
    private static function inline(string $text): string
    {
        // 1. Protect inline code spans before escaping the rest.
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $key = "\x00C" . count($codes) . "\x00";
            $codes[$key] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return $key;
        }, $text);

        // 2. Escape everything else.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 3. Images ![alt](url) then links [text](url).
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function ($m) {
            $url = self::safeUrl($m[2]);
            return $url !== '' ? '<img src="' . $url . '" alt="' . $m[1] . '">' : $m[1];
        }, $text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            $url = self::safeUrl($m[2]);
            return $url !== '' ? '<a href="' . $url . '">' . $m[1] . '</a>' : $m[1];
        }, $text);

        // 4. Emphasis (bold before italic).
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![A-Za-z0-9_])_(.+?)_(?![A-Za-z0-9_])/', '<em>$1</em>', $text);

        // 5. Restore code spans.
        return strtr($text, $codes);
    }

    /** Allow only http(s), mailto, or relative URLs; '' rejects. Output is HTML-escaped. */
    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
}
