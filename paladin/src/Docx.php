<?php
declare(strict_types=1);

/**
 * Docx — minimal, dependency-free generator of real Open XML (.docx) documents.
 *
 * Produces a valid WordprocessingML package (a ZIP of [Content_Types].xml,
 * _rels/.rels and word/document.xml) from a title + sanitised HTML body. This
 * is a *native* .docx — not HTML masquerading with a .doc extension — so it
 * opens as a proper editable Word document.
 *
 * Supported HTML subset (the common output of the editor): h1–h6, p, div,
 * ul/ol/li, blockquote, pre/code, table/tr/td/th, hr, br, and inline
 * b/strong, i/em, u, a (text kept). Anything else degrades to its text content.
 */
final class Docx {

    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    /** Heading font sizes in half-points (h1..h6). Body text is 22 (11pt). */
    private const HEADING_SZ = [1 => 36, 2 => 30, 3 => 26, 4 => 24, 5 => 22, 6 => 22];

    /**
     * Build a .docx and return its raw bytes.
     * @param array<string,string> $meta optional cover metadata (label => value)
     */
    public static function fromHtml(string $title, string $bodyHtml, array $meta = []): string {
        $body = '';
        // Title heading.
        $body .= self::para(self::run($title, true, false, 40), ['sz' => 40, 'spaceAfter' => 200]);
        // Metadata table (cover).
        if ($meta) {
            $rows = '';
            foreach ($meta as $k => $v) {
                $rows .= '<w:tr>'
                    . self::cell(self::para(self::run((string)$k, true, false, 18)), 2200, 'F2F2F2')
                    . self::cell(self::para(self::run((string)$v, false, false, 18)))
                    . '</w:tr>';
            }
            $body .= self::table($rows) . self::para('');
        }
        // Body content.
        $body .= self::convert($bodyHtml);

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::W_NS . '"><w:body>'
            . $body
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
            . '</w:body></w:document>';

        return self::zip($document);
    }

    /**
     * Build a multi-section .docx (e.g. a whole space): a cover + linked table
     * of contents, then each section starting on a new page.
     * @param array<int,array{title:string,html:string,meta?:string}> $sections
     * @param array<string,string> $meta optional cover metadata
     */
    public static function fromSections(string $title, array $sections, array $meta = []): string {
        $body = self::para(self::run($title, true, false, 40), ['sz' => 40, 'spaceAfter' => 120]);
        if ($meta) {
            foreach ($meta as $k => $v) {
                $body .= self::para(self::run((string)$k . ': ', true, false, 18) . self::run((string)$v, false, false, 18));
            }
        }
        // Contents.
        $body .= self::para(self::run('Contents', true, false, 28), ['spaceBefore' => 160, 'spaceAfter' => 80]);
        $i = 0;
        foreach ($sections as $s) {
            $i++;
            $body .= self::para(self::run($i . '. ' . (string)($s['title'] ?? 'Untitled')), ['indent' => 360]);
        }
        // Sections, each on its own page.
        foreach ($sections as $s) {
            $body .= self::para(self::run((string)($s['title'] ?? 'Untitled'), true, false, 36), ['sz' => 36, 'pageBreakBefore' => true, 'spaceAfter' => 80]);
            if (!empty($s['meta'])) { $body .= self::para(self::run((string)$s['meta'], false, true, 18)); }
            $body .= self::convert((string)($s['html'] ?? ''));
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::W_NS . '"><w:body>'
            . $body
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
            . '</w:body></w:document>';
        return self::zip($document);
    }

    /** Convert the HTML body to a string of block-level WordprocessingML. */
    private static function convert(string $html): string {
        if (trim($html) === '') { return self::para(''); }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__docx_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = $dom->getElementById('__docx_root');
        if (!$root) { return self::para(self::run(trim(strip_tags($html)))); }
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $node) { $out .= self::block($node); }
        return $out !== '' ? $out : self::para('');
    }

    /** Render one block-level node (recursing for containers). */
    private static function block(\DOMNode $n): string {
        if ($n->nodeType === XML_TEXT_NODE) {
            $t = trim((string)$n->nodeValue);
            return $t === '' ? '' : self::para(self::run($t));
        }
        if (!($n instanceof \DOMElement)) { return ''; }
        $tag = strtolower($n->nodeName);

        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $sz = self::HEADING_SZ[(int)$m[1]];
            return self::para(self::inline($n, true, false, $sz), ['sz' => $sz, 'spaceBefore' => 160, 'spaceAfter' => 80]);
        }
        switch ($tag) {
            case 'p':
            case 'div':
                $runs = self::inline($n);
                // A div that only contains block children produces no inline runs;
                // recurse so nested structure is preserved.
                if (trim($runs) === '' && self::hasBlockChild($n)) {
                    $out = ''; foreach (iterator_to_array($n->childNodes) as $c) { $out .= self::block($c); }
                    return $out;
                }
                return self::para($runs);
            case 'ul':
            case 'ol':
                $out = ''; $i = 0;
                foreach ($n->getElementsByTagName('li') as $li) {
                    if ($li->parentNode !== $n) { continue; } // direct children only
                    $i++;
                    $bullet = $tag === 'ol' ? ($i . '. ') : '• ';
                    $out .= self::para(self::run($bullet) . self::inline($li), ['indent' => 360]);
                }
                return $out;
            case 'blockquote':
                return self::para(self::inline($n, false, true), ['indent' => 480]);
            case 'pre':
            case 'code':
                return self::para(self::run($n->textContent, false, false, null, true));
            case 'table':
                return self::convertTable($n) . self::para('');
            case 'hr':
                return self::para('', ['rule' => true]);
            case 'br':
                return self::para('');
            default:
                if (self::hasBlockChild($n)) {
                    $out = ''; foreach (iterator_to_array($n->childNodes) as $c) { $out .= self::block($c); }
                    return $out;
                }
                $runs = self::inline($n);
                return trim($runs) === '' ? '' : self::para($runs);
        }
    }

    private static function hasBlockChild(\DOMNode $n): bool {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement &&
                preg_match('/^(h[1-6]|p|div|ul|ol|table|blockquote|pre|hr)$/', strtolower($c->nodeName))) {
                return true;
            }
        }
        return false;
    }

    /** Inline runs from a node's descendants, tracking bold/italic state. */
    private static function inline(\DOMNode $n, bool $b = false, bool $i = false, ?int $sz = null): string {
        $out = '';
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $t = (string)$c->nodeValue;
                if ($t !== '') { $out .= self::run($t, $b, $i, $sz); }
                continue;
            }
            if (!($c instanceof \DOMElement)) { continue; }
            $tag = strtolower($c->nodeName);
            if ($tag === 'br') { $out .= '<w:r><w:br/></w:r>'; continue; }
            if (in_array($tag, ['b', 'strong'], true))      { $out .= self::inline($c, true, $i, $sz); continue; }
            if (in_array($tag, ['i', 'em'], true))          { $out .= self::inline($c, $b, true, $sz); continue; }
            if ($tag === 'code')                            { $out .= self::run($c->textContent, $b, $i, $sz, true); continue; }
            // a, span, u and anything else: keep text with current formatting.
            $out .= self::inline($c, $b, $i, $sz);
        }
        return $out;
    }

    /** A single run. */
    private static function run(string $text, bool $bold = false, bool $italic = false, ?int $sz = null, bool $mono = false): string {
        if ($text === '') { return ''; }
        $rpr = '';
        if ($bold)   { $rpr .= '<w:b/>'; }
        if ($italic) { $rpr .= '<w:i/>'; }
        if ($mono)   { $rpr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>'; }
        if ($sz)     { $rpr .= '<w:sz w:val="' . (int)$sz . '"/>'; }
        $rprXml = $rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '';
        return '<w:r>' . $rprXml . '<w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r>';
    }

    /** A paragraph wrapping run XML, with optional properties. */
    private static function para(string $runs, array $opts = []): string {
        $ppr = '';
        if (!empty($opts['pageBreakBefore'])) { $ppr .= '<w:pageBreakBefore/>'; }
        if (!empty($opts['indent']))      { $ppr .= '<w:ind w:left="' . (int)$opts['indent'] . '"/>'; }
        $sb = $opts['spaceBefore'] ?? null; $sa = $opts['spaceAfter'] ?? null;
        if ($sb !== null || $sa !== null) {
            $ppr .= '<w:spacing' . ($sb !== null ? ' w:before="' . (int)$sb . '"' : '') . ($sa !== null ? ' w:after="' . (int)$sa . '"' : '') . '/>';
        }
        if (!empty($opts['rule'])) {
            $ppr .= '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="999999"/></w:pBdr>';
        }
        $pprXml = $ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '';
        return '<w:p>' . $pprXml . $runs . '</w:p>';
    }

    private static function convertTable(\DOMElement $table): string {
        $rows = '';
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = '';
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof \DOMElement)) { continue; }
                $tag = strtolower($td->nodeName);
                if ($tag !== 'td' && $tag !== 'th') { continue; }
                $header = $tag === 'th';
                $cells .= self::cell(self::para(self::inline($td, $header)), null, $header ? 'F2F2F2' : null);
            }
            if ($cells !== '') { $rows .= '<w:tr>' . $cells . '</w:tr>'; }
        }
        return $rows === '' ? '' : self::table($rows);
    }

    private static function table(string $rows): string {
        $borders = '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="BBBBBB"/><w:left w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="BBBBBB"/><w:right w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="BBBBBB"/><w:insideV w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '</w:tblBorders>';
        return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>' . $borders . '</w:tblPr>' . $rows . '</w:tbl>';
    }

    private static function cell(string $content, ?int $width = null, ?string $shade = null): string {
        $tcpr = '';
        if ($width) { $tcpr .= '<w:tcW w:w="' . (int)$width . '" w:type="dxa"/>'; }
        if ($shade) { $tcpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . preg_replace('/[^0-9A-Fa-f]/', '', $shade) . '"/>'; }
        $tcprXml = $tcpr !== '' ? '<w:tcPr>' . $tcpr . '</w:tcPr>' : '';
        return '<w:tc>' . $tcprXml . $content . '</w:tc>';
    }

    private static function esc(string $s): string {
        // Strip control chars invalid in XML 1.0, then entity-encode.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Zip the three required package parts into .docx bytes. */
    private static function zip(string $documentXml): string {
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'paldocx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
