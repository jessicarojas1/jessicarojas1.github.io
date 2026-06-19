<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';   // referenced only at call time
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/Storage.php';

it('flags server-executable extensions as dangerous', function () {
    foreach (['shell.php', 'x.PHP5', 'a.phtml', 'b.phar', 'c.pht', 'evil.cgi',
              'run.sh', 'x.py', 'm.exe', 'lib.dll', 'a.bat', 'x.jsp', 'y.aspx',
              'config.htaccess'] as $name) {
        expect(Storage::isDangerousExtension($name), "should flag: {$name}");
    }
});

it('flags stored-XSS-capable markup types', function () {
    foreach (['logo.svg', 'page.html', 'p.htm', 'd.xhtml', 's.shtml', 'data.xml'] as $name) {
        expect(Storage::isDangerousExtension($name), "should flag: {$name}");
    }
});

it('allows ordinary document/image types', function () {
    foreach (['report.pdf', 'evidence.png', 'photo.jpg', 'photo.jpeg', 'scan.gif',
              'sheet.xlsx', 'doc.docx', 'notes.txt', 'data.csv', 'pic.webp'] as $name) {
        expect(!Storage::isDangerousExtension($name), "should allow: {$name}");
    }
});

it('is case-insensitive and tolerant of no extension', function () {
    expect(Storage::isDangerousExtension('X.PhP'), 'case sensitivity');
    expect(!Storage::isDangerousExtension('noextension'), 'no-ext should be allowed here');
    expect(!Storage::isDangerousExtension(''), 'empty should be allowed here');
});

it('is not fooled by a dangerous extension as the final one', function () {
    // pathinfo uses the last extension — a real upload validator must also handle
    // double extensions, but the floor here correctly catches the effective type.
    expect(Storage::isDangerousExtension('invoice.pdf.php'), 'double-extension .php not caught');
    expect(!Storage::isDangerousExtension('archive.php.pdf'), 'effective .pdf wrongly flagged');
});
