<?php
declare(strict_types=1);

// AIAdvisor pulls in Database/Auth at call time, but redact() and the DISCLAIMER
// constant are pure/static and safe to test in isolation. We avoid touching
// methods that hit the database.
require_once __DIR__ . '/../src/AIAdvisor.php';

it('redacts email addresses', function () {
    $out = AIAdvisor::redact('Contact admin@example.com for access.');
    expect(!str_contains($out, 'admin@example.com'), 'email leaked');
    expect(str_contains($out, '[redacted-email]'), 'no email marker');
});

it('redacts IPv4 addresses', function () {
    $out = AIAdvisor::redact('Server at 10.20.30.40 is exposed.');
    expect(!str_contains($out, '10.20.30.40'), 'ip leaked');
    expect(str_contains($out, '[redacted-ip]'), 'no ip marker');
});

it('redacts API-key-shaped tokens', function () {
    $out = AIAdvisor::redact('key is sk-ABCDEF0123456789abcdef now');
    expect(!str_contains($out, 'sk-ABCDEF0123456789abcdef'), 'key leaked');
    expect(str_contains($out, '[redacted-key]'), 'no key marker');
});

it('redacts AWS access key ids', function () {
    $out = AIAdvisor::redact('AKIAIOSFODNN7EXAMPLE in config');
    expect(!str_contains($out, 'AKIAIOSFODNN7EXAMPLE'), 'aws key leaked');
});

it('redacts bearer tokens', function () {
    $out = AIAdvisor::redact('Authorization: Bearer abc.def.ghi-123');
    expect(!str_contains($out, 'abc.def.ghi-123'), 'bearer token leaked');
    expect(str_contains($out, 'Bearer [redacted-token]'), 'no bearer marker');
});

it('redacts long hex secrets', function () {
    $out = AIAdvisor::redact('hash 0123456789abcdef0123456789abcdef done');
    expect(str_contains($out, '[redacted-secret]'), 'hex secret not redacted');
});

it('leaves ordinary control text intact', function () {
    $in  = 'Implement multi-factor authentication for privileged accounts.';
    expect_eq($in, AIAdvisor::redact($in), 'benign text was altered');
});

it('exposes a non-empty human-review disclaimer', function () {
    expect(strlen(AIAdvisor::DISCLAIMER) > 40, 'disclaimer too short');
    expect(stripos(AIAdvisor::DISCLAIMER, 'reviewed') !== false, 'disclaimer lacks review language');
});
