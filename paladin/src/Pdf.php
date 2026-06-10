<?php
declare(strict_types=1);

/**
 * Pdf — a tiny, dependency-free PDF document writer.
 *
 * Produces a valid PDF 1.4 file using the standard Helvetica/Helvetica-Bold
 * core fonts (no embedding, no external libraries). It flows text — titles,
 * headings, paragraphs, key/value meta and rules — into Letter-size pages with
 * automatic word wrapping and pagination. It is deliberately text-oriented:
 * rich HTML/CSS layout still belongs to the browser print view; this gives a
 * real server-rendered application/pdf download for archival/records use.
 */
final class Pdf {

    private float $pageW = 612.0;   // US Letter, points
    private float $pageH = 792.0;
    private float $margin = 54.0;   // 0.75"
    private float $x;
    private float $y;
    /** @var array<int,array{x:float,y:float,size:float,font:string,text:string}> */
    private array $items = [];      // positioned text items for the current page
    /** @var array<int,array> finished pages (each a list of items) */
    private array $pages = [];
    private string $title;

    public function __construct(string $title = 'Document') {
        $this->title = $title;
        $this->x = $this->margin;
        $this->y = $this->pageH - $this->margin;
    }

    private function usableWidth(): float { return $this->pageW - 2 * $this->margin; }

    /** Approximate Helvetica string width in points (avg advance ≈ 0.5em, bold ≈ 0.53em). */
    private function textWidth(string $s, float $size, bool $bold): float {
        return strlen($s) * $size * ($bold ? 0.53 : 0.5);
    }

    private function newPage(): void {
        if ($this->items) { $this->pages[] = $this->items; }
        $this->items = [];
        $this->y = $this->pageH - $this->margin;
    }

    private function ensureSpace(float $lineHeight): void {
        if ($this->y - $lineHeight < $this->margin) { $this->newPage(); }
    }

    /** Convert UTF-8 to WinAnsi-ish bytes and escape PDF string metacharacters. */
    private function esc(string $s): string {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($s === false) { $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? ''; }
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
    }

    /** Wrap a single logical line into rendered lines at the given font size. */
    private function wrap(string $text, float $size, bool $bold): array {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') { return ['']; }
        $words = explode(' ', $text);
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->textWidth($try, $size, $bold) > $this->usableWidth() && $cur !== '') {
                $lines[] = $cur; $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }

    private function writeLines(string $text, float $size, bool $bold, float $leadAfter): void {
        $lineH = $size * 1.35;
        foreach ($this->wrap($text, $size, $bold) as $ln) {
            $this->ensureSpace($lineH);
            $this->y -= $size;          // baseline
            $this->items[] = ['x' => $this->x, 'y' => $this->y, 'size' => $size, 'font' => $bold ? 'F2' : 'F1', 'text' => $ln];
            $this->y -= ($lineH - $size);
        }
        $this->y -= $leadAfter;
    }

    public function title(string $s): void      { $this->writeLines($s, 20, true, 10); }
    public function heading(string $s): void     { $this->ensureSpace(40); $this->writeLines($s, 14, true, 4); }
    public function paragraph(string $s): void   { $this->writeLines($s, 11, false, 8); }
    public function meta(string $k, string $v): void { $this->writeLines($k . ': ' . $v, 9, false, 2); }

    public function rule(): void {
        $this->ensureSpace(12);
        $this->y -= 6;
        // A thin rule drawn as a full-width underscore run keeps the writer text-only.
        $this->items[] = ['x' => $this->x, 'y' => $this->y, 'size' => 8, 'font' => 'F1', 'text' => str_repeat('_', (int)($this->usableWidth() / 4)), 'rule' => true];
        $this->y -= 10;
    }

    /** Render the accumulated content to PDF bytes. */
    public function output(): string {
        $this->newPage(); // flush current page

        $objects = [];
        $fontRegular = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $fontBold    = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Object numbering: 1=Catalog, 2=Pages, 3=FontRegular, 4=FontBold, then per page: content + page.
        $catalogId = 1; $pagesId = 2; $fr = 3; $fb = 4;
        $nextId = 5;
        $pageIds = []; $contentIds = [];
        $bodyObjs = [];

        foreach ($this->pages as $pageItems) {
            $stream = "BT\n";
            $curFont = '';
            foreach ($pageItems as $it) {
                if ($it['font'] !== $curFont) {
                    $stream .= "/{$it['font']} {$it['size']} Tf\n";
                    $curFont = $it['font'];
                } else {
                    $stream .= "/{$it['font']} {$it['size']} Tf\n";
                }
                $stream .= sprintf("1 0 0 1 %.2f %.2f Tm\n", $it['x'], $it['y']);
                $stream .= '(' . $this->esc($it['text']) . ") Tj\n";
            }
            $stream .= "ET";

            $contentId = $nextId++;
            $pageId = $nextId++;
            $contentIds[] = $contentId;
            $pageIds[] = $pageId;

            $bodyObjs[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $bodyObjs[$pageId] =
                "<< /Type /Page /Parent {$pagesId} R /MediaBox [0 0 {$this->pageW} {$this->pageH}] " .
                "/Resources << /Font << /F1 {$fr} 0 R /F2 {$fb} 0 R >> >> /Contents {$contentId} 0 R >>";
        }

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId]   = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . " >>";
        $objects[$fr]        = $fontRegular;
        $objects[$fb]        = $fontBold;
        foreach ($bodyObjs as $id => $body) { $objects[$id] = $body; }

        // Assemble with a cross-reference table.
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
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root {$catalogId} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }

    /** Build a PDF from an HTML body: title + flattened block text. */
    public static function fromHtml(string $title, string $html, array $meta = []): string {
        $pdf = new self($title);
        $pdf->title($title);
        foreach ($meta as $k => $v) { $pdf->meta((string)$k, (string)$v); }
        if ($meta) { $pdf->rule(); }
        foreach (self::htmlBlocks($html) as $block) {
            if ($block['type'] === 'h') { $pdf->heading($block['text']); }
            else { $pdf->paragraph($block['text']); }
        }
        return $pdf->output();
    }

    /** Flatten HTML into ordered heading/paragraph blocks (no tags). */
    private static function htmlBlocks(?string $html): array {
        $s = (string)$html;
        // Mark headings so we can style them, then split paragraphs on block ends.
        $s = preg_replace('#<h[1-6][^>]*>#i', "\x01H\x01", $s);
        $s = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|pre)>#i', "\x01P\x01", $s);
        $s = preg_replace('#<br\s*/?>#i', "\x01P\x01", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $blocks = [];
        foreach (preg_split('/\x01P\x01/', $s) as $chunk) {
            $isH = str_contains($chunk, "\x01H\x01");
            $text = trim(str_replace("\x01H\x01", '', $chunk));
            if ($text === '') { continue; }
            $blocks[] = ['type' => $isH ? 'h' : 'p', 'text' => $text];
        }
        return $blocks ?: [['type' => 'p', 'text' => '(No content.)']];
    }
}
