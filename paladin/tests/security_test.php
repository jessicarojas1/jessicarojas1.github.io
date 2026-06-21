<?php
/**
 * PALADIN — framework-free security regression tests.
 *
 * Pure, database-free assertions guarding the security-critical logic that has
 * caused (and been fixed for) real issues: CSV/formula injection, SSRF target
 * validation, rich-text sanitisation and output escaping. No PHPUnit, no DB, no
 * network (SSRF cases use IP literals so DNS is never consulted).
 *
 * Run:  php paladin/tests/security_test.php   (exit 0 = pass, 1 = failure)
 */
declare(strict_types=1);

define('PALADIN_ROOT', dirname(__DIR__));
require PALADIN_ROOT . '/src/Security.php';
require PALADIN_ROOT . '/src/Csv.php';

$tests = 0; $fails = 0;
function check(bool $cond, string $msg): void {
    global $tests, $fails; $tests++;
    if ($cond) { echo "  ok    $msg\n"; }
    else       { $fails++; echo "  FAIL  $msg\n"; }
}

echo "— CSV / formula injection guard (Csv::cell) —\n";
check(Csv::cell('=cmd')        === "'=cmd",      'leading = is neutralised');
check(Csv::cell('+1+1')        === "'+1+1",      'leading + is neutralised');
check(Csv::cell('-2+3+cmd')    === "'-2+3+cmd",  'formula-shaped - is neutralised');
check(Csv::cell('@SUM(A1)')    === "'@SUM(A1)",  'leading @ is neutralised');
check(Csv::cell("\tTAB")       === "'\tTAB",     'leading tab is neutralised');
check(Csv::cell('-5')          === '-5',         'negative number preserved');
check(Csv::cell('+44')         === '+44',        'plus number preserved');
check(Csv::cell('3.14')        === '3.14',       'decimal preserved');
check(Csv::cell('Normal text') === 'Normal text','plain text untouched');
check(Csv::cell('')            === '',           'empty string untouched');
check(Csv::row(['=a', 'b'])    === ["'=a", 'b'], 'row() guards each cell');
check(Csv::cell("\rCR")        === "'\rCR",      'leading carriage-return is neutralised');
check(Csv::cell('=1+1')        === "'=1+1",      'classic =1+1 formula neutralised');
check(Csv::cell('a=b')         === 'a=b',        'internal = (not leading) left untouched');
check(Csv::cell('0')           === '0',          'zero left untouched');

echo "— SSRF outbound-URL guard (Security::safeOutboundIp) —\n";
check(Security::safeOutboundIp('http://127.0.0.1/x')             === null,     'loopback blocked');
check(Security::safeOutboundIp('http://169.254.169.254/latest')  === null,     'cloud metadata blocked');
check(Security::safeOutboundIp('http://10.0.0.1/x')              === null,     'private 10/8 blocked');
check(Security::safeOutboundIp('http://192.168.1.1/x')           === null,     'private 192.168 blocked');
check(Security::safeOutboundIp('http://172.16.0.1/x')            === null,     'private 172.16/12 blocked');
check(Security::safeOutboundIp('http://[::1]/x')                 === null,     'ipv6 loopback blocked');
check(Security::safeOutboundIp('http://0.0.0.0/x')               === null,     'reserved 0.0.0.0 blocked');
check(Security::safeOutboundIp('ftp://8.8.8.8/x')                === null,     'non-http scheme blocked');
check(Security::safeOutboundIp('file:///etc/passwd')            === null,     'file scheme blocked');
check(Security::safeOutboundIp('https://8.8.8.8/x')             === '8.8.8.8', 'public IP allowed (pins to IP)');
check(Security::safeOutboundIp('http://[fd00::1]/x')            === null,     'ipv6 unique-local (fc00::/7) blocked');
check(Security::safeOutboundIp('http://[fe80::1]/x')           === null,     'ipv6 link-local (fe80::/10) blocked');
check(Security::safeOutboundIp('http://127.5.5.5/x')            === null,     'loopback /8 (not just .0.1) blocked');
check(Security::safeOutboundIp('http://169.254.1.1/x')         === null,     'link-local /16 (not just metadata) blocked');
check(Security::safeOutboundIp('https://1.1.1.1:8443/x')       === '1.1.1.1','public IP with explicit port allowed');
check(Security::safeOutboundIp('HTTP://8.8.4.4/x')             === '8.8.4.4','uppercase scheme handled');
check(Security::safeOutboundIp('http://')                      === null,     'missing host rejected');
check(Security::safeOutboundIp('not a url')                    === null,     'garbage rejected');

echo "— Rich-text sanitiser (Security::sanitizeHtml) —\n";
$a = Security::sanitizeHtml('<p>hello</p><script>alert(1)</script>');
check(stripos($a, '<script') === false, '<script> stripped');
check(strpos($a, '<p>hello</p>') !== false, 'safe markup preserved');
$b = Security::sanitizeHtml('<img src=x onerror="alert(1)">');
check(stripos($b, 'onerror') === false, 'on* event handler stripped');
$c = Security::sanitizeHtml('<a href="javascript:alert(1)">x</a>');
check(stripos($c, 'javascript:') === false, 'javascript: URI stripped');
$d = Security::sanitizeHtml('<a href="https://ok.example/p">x</a>');
check(strpos($d, 'https://ok.example/p') !== false, 'safe http(s) href preserved');
// SVG-based XSS bypasses (xlink:href, set/animate href, foreignObject).
check(stripos(Security::sanitizeHtml('<svg><a xlink:href="javascript:alert(1)">x</a></svg>'), 'javascript:') === false, 'svg xlink:href javascript: stripped');
check(stripos(Security::sanitizeHtml('<svg><a><set attributeName="href" to="javascript:alert(1)"/></a></svg>'), 'javascript:') === false, 'svg <set> href javascript: stripped');
check(stripos(Security::sanitizeHtml('<svg><a><animate attributeName="href" values="javascript:alert(1)"/></a></svg>'), 'javascript:') === false, 'svg <animate> href javascript: stripped');
check(stripos(Security::sanitizeHtml('<svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg>'), '<svg') === false, 'svg/foreignObject element removed');

echo "— Output escaping (Security::h) —\n";
check(Security::h('<b>&"') === '&lt;b&gt;&amp;&quot;', 'h() encodes < > & "');

echo "— Native .docx generator (Docx) — well-formedness & XML escaping —\n";
if (class_exists('ZipArchive')) {
    require_once PALADIN_ROOT . '/src/Docx.php';
    // Body deliberately contains XML-hostile characters and a <script> element.
    $bytes = Docx::fromHtml('Title & <Tag>', '<h1>5 < 6 & "q"</h1><p>ok <strong>bold</strong></p><script>alert(1)</script>');
    check(substr($bytes, 0, 2) === 'PK', 'docx is a ZIP (PK magic)');

    $tmp = tempnam(sys_get_temp_dir(), 'palt');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $docXml = '';
    if ($zip->open($tmp) === true) {
        $docXml = (string)$zip->getFromName('word/document.xml');
        $hasCt  = $zip->getFromName('[Content_Types].xml') !== false;
        $hasRel = $zip->getFromName('_rels/.rels') !== false;
        $zip->close();
        check($hasCt && $hasRel, 'docx contains [Content_Types].xml and _rels/.rels');
    } else {
        check(false, 'docx package opens as a ZIP');
    }
    @unlink($tmp);

    // word/document.xml must be well-formed XML (proves all & < > are escaped).
    $prev = libxml_use_internal_errors(true);
    $parsed = $docXml !== '' ? simplexml_load_string($docXml) : false;
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    check($parsed !== false, 'word/document.xml is well-formed XML');
    // No raw <script> markup survives into the document part.
    check(stripos($docXml, '<script') === false, 'no <script> element in document.xml');
    // XML-hostile characters are entity-escaped, not raw.
    check(strpos($docXml, '&lt;') !== false && strpos($docXml, '&amp;') !== false, 'special chars are entity-escaped');
} else {
    echo "  (skipped — ZipArchive unavailable)\n";
}

echo "— Markdown import pipeline (Markdown::toHtml -> sanitizeHtml) —\n";
require_once PALADIN_ROOT . '/src/Markdown.php';
$imp = fn(string $md): string => Security::sanitizeHtml(Markdown::toHtml($md));
// Raw <script> embedded in markdown must not survive as a live element.
check(preg_match('/<script/i', $imp("text\n\n<script>alert(1)</script>")) === 0, 'raw <script> not live after import');
// Raw event-handler HTML is escaped to inert text (no live tag).
check(preg_match('/<img/i', $imp('<img src=x onerror=alert(1)>')) === 0, 'raw <img onerror> not a live tag after import');
// A javascript: link target is stripped entirely.
check(stripos($imp('[x](javascript:alert(1))'), 'javascript:') === false, 'javascript: link neutralised on import');
// Legitimate markdown is preserved.
check(stripos($imp('[ok](https://example.com/p)'), 'href="https://example.com/p"') !== false, 'safe http(s) link preserved on import');
check((bool)preg_match('/<(strong|b)>/i', $imp('**bold**')), 'bold markdown preserved on import');

echo "\n$tests checks, $fails failure(s)\n";
exit($fails === 0 ? 0 : 1);
