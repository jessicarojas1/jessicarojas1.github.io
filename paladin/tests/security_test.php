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

echo "— Output escaping (Security::h) —\n";
check(Security::h('<b>&"') === '&lt;b&gt;&amp;&quot;', 'h() encodes < > & "');

echo "\n$tests checks, $fails failure(s)\n";
exit($fails === 0 ? 0 : 1);
