<?php
declare(strict_types=1);

/**
 * Pdf — a tiny, dependency-free PDF document writer (PDF 1.4, core Helvetica
 * fonts). Beyond plain text it draws vector graphics (a filled accent title
 * band, ruled separators), repeats a header and a "Page N of M" footer on every
 * page, and renders bullet lists — all without embedding fonts or pulling in a
 * library. Rich CSS layout still belongs to the browser print view; this is a
 * real server-rendered application/pdf for archival/records use.
 */
final class Pdf {

    private float $pageW = 612.0;   // US Letter, points
    private float $pageH = 792.0;
    private float $margin = 54.0;
    private float $headerY;         // baseline for the running header
    private float $footerY;         // baseline for the running footer
    private float $contentTop;
    private float $contentBottom;
    private float $x;
    private float $y;

    // Brand accent (#2563eb) and a light tint for the title band.
    private array $accent = [0.145, 0.388, 0.921];
    private array $accentLight = [0.92, 0.95, 0.995];
    private array $ink = [0.10, 0.12, 0.16];
    private array $muted = [0.45, 0.50, 0.58];

    /** @var array<int,array> items for the current page (text/line/rect) */
    private array $items = [];
    /** @var array<int,array> finished pages */
    private array $pages = [];
    private string $title;

    public function __construct(string $title = 'Document') {
        $this->title = $title;
        $this->headerY = $this->pageH - 38;
        $this->footerY = 30;
        $this->contentTop = $this->pageH - 70;
        $this->contentBottom = 58;
        $this->x = $this->margin;
        $this->y = $this->contentTop;
    }

    private function usableWidth(): float { return $this->pageW - 2 * $this->margin; }

    private function textWidth(string $s, float $size, bool $bold): float {
        return strlen($s) * $size * ($bold ? 0.53 : 0.5);
    }

    private function newPage(): void {
        if ($this->items) { $this->pages[] = $this->items; }
        $this->items = [];
        $this->y = $this->contentTop;
    }

    private function ensureSpace(float $lineHeight): void {
        if ($this->y - $lineHeight < $this->contentBottom) { $this->newPage(); }
    }

    private function esc(string $s): string {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($s === false) { $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? ''; }
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
    }

    private function wrap(string $text, float $size, bool $bold, float $width): array {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') { return ['']; }
        $words = explode(' ', $text);
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->textWidth($try, $size, $bold) > $width && $cur !== '') { $lines[] = $cur; $cur = $w; }
            else { $cur = $try; }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }

    private function emitText(float $x, float $size, bool $bold, string $text, array $color, float $leadAfter, float $indent = 0.0, ?string $bullet = null): void {
        $lineH = $size * 1.35;
        $width = $this->usableWidth() - $indent;
        $lines = $this->wrap($text, $size, $bold, $width);
        foreach ($lines as $i => $ln) {
            $this->ensureSpace($lineH);
            $this->y -= $size;
            if ($i === 0 && $bullet !== null) {
                $this->items[] = ['t' => 'text', 'x' => $x, 'y' => $this->y, 'size' => $size, 'font' => 'F1', 'text' => $bullet, 'color' => $this->accent];
            }
            $this->items[] = ['t' => 'text', 'x' => $x + $indent, 'y' => $this->y, 'size' => $size, 'font' => $bold ? 'F2' : 'F1', 'text' => $ln, 'color' => $color];
            $this->y -= ($lineH - $size);
        }
        $this->y -= $leadAfter;
    }

    /** Document title with a filled accent band behind it. */
    public function title(string $s): void {
        $size = 20; $lineH = $size * 1.5;
        $this->ensureSpace($lineH + 6);
        $top = $this->y; $bandH = $lineH;
        $this->items[] = ['t' => 'rect', 'x' => $this->margin - 8, 'y' => $top - $bandH + 4, 'w' => $this->usableWidth() + 16, 'h' => $bandH, 'color' => $this->accentLight];
        $this->items[] = ['t' => 'rect', 'x' => $this->margin - 8, 'y' => $top - $bandH + 4, 'w' => 4, 'h' => $bandH, 'color' => $this->accent];
        $this->y = $top;
        $this->emitText($this->margin + 6, $size, true, $s, $this->ink, 12);
    }

    public function heading(string $s): void { $this->ensureSpace(40); $this->emitText($this->x, 14, true, $s, $this->accent, 4); }
    public function paragraph(string $s): void { $this->emitText($this->x, 11, false, $s, $this->ink, 8); }
    public function bullet(string $s): void { $this->emitText($this->x + 6, 11, false, $s, $this->ink, 4, 14, "\xe2\x80\xa2"); }
    public function meta(string $k, string $v): void { $this->emitText($this->x, 9, false, $k . ': ' . $v, $this->muted, 2); }

    /** A drawn horizontal rule. */
    public function rule(): void {
        $this->ensureSpace(14);
        $this->y -= 6;
        $this->items[] = ['t' => 'line', 'x1' => $this->margin, 'y1' => $this->y, 'x2' => $this->pageW - $this->margin, 'y2' => $this->y, 'lw' => 0.6, 'color' => [0.80, 0.84, 0.90]];
        $this->y -= 10;
    }

    /** Render the accumulated content to PDF bytes (with header/footer per page). */
    public function output(): string {
        $this->newPage();
        $total = max(1, count($this->pages));
        $genDate = date('M j, Y');

        // Decorate every page with a running header and footer.
        foreach ($this->pages as $i => &$pageItems) {
            $num = $i + 1;
            $pageItems[] = ['t' => 'text', 'x' => $this->margin, 'y' => $this->headerY, 'size' => 8, 'font' => 'F2', 'text' => mb_substr($this->title, 0, 90), 'color' => $this->muted];
            $pageItems[] = ['t' => 'line', 'x1' => $this->margin, 'y1' => $this->headerY - 6, 'x2' => $this->pageW - $this->margin, 'y2' => $this->headerY - 6, 'lw' => 0.6, 'color' => $this->accent];
            $pageItems[] = ['t' => 'line', 'x1' => $this->margin, 'y1' => $this->footerY + 12, 'x2' => $this->pageW - $this->margin, 'y2' => $this->footerY + 12, 'lw' => 0.5, 'color' => [0.85, 0.88, 0.92]];
            $pageItems[] = ['t' => 'text', 'x' => $this->margin, 'y' => $this->footerY, 'size' => 8, 'font' => 'F1', 'text' => 'Generated ' . $genDate, 'color' => $this->muted];
            $pageLabel = 'Page ' . $num . ' of ' . $total;
            $pw = $this->textWidth($pageLabel, 8, false);
            $pageItems[] = ['t' => 'text', 'x' => $this->pageW - $this->margin - $pw, 'y' => $this->footerY, 'size' => 8, 'font' => 'F1', 'text' => $pageLabel, 'color' => $this->muted];
        }
        unset($pageItems);

        $fr = 3; $fb = 4; $nextId = 5;
        $pageIds = []; $bodyObjs = [];
        foreach ($this->pages as $pageItems) {
            $g = ''; $t = "BT\n";
            foreach ($pageItems as $it) {
                if ($it['t'] === 'rect') {
                    [$r, $gc, $b] = $it['color'];
                    $g .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $r, $gc, $b, $it['x'], $it['y'], $it['w'], $it['h']);
                } elseif ($it['t'] === 'line') {
                    [$r, $gc, $b] = $it['color'];
                    $g .= sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $r, $gc, $b, $it['lw'], $it['x1'], $it['y1'], $it['x2'], $it['y2']);
                }
            }
            foreach ($pageItems as $it) {
                if ($it['t'] !== 'text') { continue; }
                [$r, $gc, $b] = $it['color'];
                $t .= "/{$it['font']} {$it['size']} Tf\n";
                $t .= sprintf("%.3f %.3f %.3f rg\n", $r, $gc, $b);
                $t .= sprintf("1 0 0 1 %.2f %.2f Tm\n", $it['x'], $it['y']);
                $t .= '(' . $this->esc($it['text']) . ") Tj\n";
            }
            $t .= "ET";
            $stream = $g . $t;

            $contentId = $nextId++; $pageId = $nextId++;
            $pageIds[] = $pageId;
            $bodyObjs[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $bodyObjs[$pageId] =
                "<< /Type /Page /Parent 2 R /MediaBox [0 0 {$this->pageW} {$this->pageH}] " .
                "/Resources << /Font << /F1 {$fr} 0 R /F2 {$fb} 0 R >> >> /Contents {$contentId} 0 R >>";
        }

        $objects = [];
        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . " >>";
        $objects[$fr] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$fb] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        foreach ($bodyObjs as $id => $body) { $objects[$id] = $body; }

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0); }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }

    /** Build a PDF from an HTML body: title + flattened block text + bullet lists. */
    public static function fromHtml(string $title, string $html, array $meta = []): string {
        $pdf = new self($title);
        $pdf->title($title);
        foreach ($meta as $k => $v) { $pdf->meta((string)$k, (string)$v); }
        if ($meta) { $pdf->rule(); }
        foreach (self::htmlBlocks($html) as $block) {
            match ($block['type']) {
                'h'  => $pdf->heading($block['text']),
                'li' => $pdf->bullet($block['text']),
                default => $pdf->paragraph($block['text']),
            };
        }
        return $pdf->output();
    }

    /** Flatten HTML into ordered heading/paragraph/list-item blocks (no tags). */
    private static function htmlBlocks(?string $html): array {
        $s = (string)$html;
        $s = preg_replace('#<h[1-6][^>]*>#i', "\x01H\x01", $s);
        $s = preg_replace('#<li[^>]*>#i', "\x01L\x01", $s);
        $s = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|pre)>#i', "\x01P\x01", $s);
        $s = preg_replace('#<br\s*/?>#i', "\x01P\x01", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $blocks = [];
        foreach (preg_split('/\x01P\x01/', $s) as $chunk) {
            $type = 'p';
            if (str_contains($chunk, "\x01H\x01")) { $type = 'h'; }
            elseif (str_contains($chunk, "\x01L\x01")) { $type = 'li'; }
            $text = trim(str_replace(["\x01H\x01", "\x01L\x01"], '', $chunk));
            if ($text === '') { continue; }
            $blocks[] = ['type' => $type, 'text' => $text];
        }
        return $blocks ?: [['type' => 'p', 'text' => '(No content.)']];
    }
}
