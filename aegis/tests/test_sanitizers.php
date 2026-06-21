<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/Branding.php';

// ── Branding::sanitizeLogo — only http(s) and data:image are allowed ────────
it('allows http(s) logo URLs', function () {
    expect_eq('https://cdn.example.com/logo.png', Branding::sanitizeLogo('https://cdn.example.com/logo.png'));
    expect_eq('http://example.com/l.svg', Branding::sanitizeLogo('http://example.com/l.svg'));
});

it('allows data:image logo URIs', function () {
    $d = 'data:image/png;base64,iVBORw0KGgo=';
    expect_eq($d, Branding::sanitizeLogo($d));
});

it('rejects javascript: and non-image data: logo URLs', function () {
    expect_eq('', Branding::sanitizeLogo('javascript:alert(1)'));
    expect_eq('', Branding::sanitizeLogo('data:text/html;base64,PHNjcmlwdD4='));
    expect_eq('', Branding::sanitizeLogo('vbscript:msgbox(1)'));
});

it('rejects logo URLs containing markup-breakout characters', function () {
    expect_eq('', Branding::sanitizeLogo('https://x.com/a"onerror=alert(1)'));
    expect_eq('', Branding::sanitizeLogo('https://x.com/a> <script>'));
    expect_eq('', Branding::sanitizeLogo(''));
});

// ── Branding::sanitizeColor — normalized #RRGGBB or '' ──────────────────────
it('normalizes valid hex colors', function () {
    expect_eq('#aabbcc', Branding::sanitizeColor('#AABBCC'));
    expect_eq('#aabbcc', Branding::sanitizeColor('AABBCC'));
    expect_eq('#aabbcc', Branding::sanitizeColor('#abc'));
});

it('rejects invalid / injection color strings', function () {
    expect_eq('', Branding::sanitizeColor('red'));
    expect_eq('', Branding::sanitizeColor('#12'));
    expect_eq('', Branding::sanitizeColor('#aabbcc;background:url(x)'));
    expect_eq('', Branding::sanitizeColor('expression(alert(1))'));
});

// ── Security::h — output escaping ───────────────────────────────────────────
it('escapes HTML-significant characters', function () {
    expect_eq('&lt;script&gt;', Security::h('<script>'));
    $out = Security::h('"\'&');
    expect(!str_contains($out, '"') && !str_contains($out, "'"), 'quotes not escaped');
    expect(str_contains($out, '&amp;'), 'ampersand not escaped');
});

it('sanitizeInput strips tags, null bytes, and trims', function () {
    expect_eq('hello', Security::sanitizeInput("  <b>hello</b>\0 "));
});

// ── Security::validatePasswordPolicy — degrades to defaults without a DB ─────
it('rejects weak passwords against the default policy', function () {
    expect(Security::validatePasswordPolicy('short') !== [], 'too-short password accepted');
    expect(Security::validatePasswordPolicy('alllowercase1!!') !== [], 'missing uppercase accepted');
    expect(Security::validatePasswordPolicy('NoNumbersHere!!') !== [], 'missing number accepted');
    expect(Security::validatePasswordPolicy('NoSpecialChar123') !== [], 'missing special accepted');
});

it('accepts a strong password', function () {
    expect_eq([], Security::validatePasswordPolicy('Abcdef1!ghij'));
});
