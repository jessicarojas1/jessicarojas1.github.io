<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Ssrf.php';

// IP-literal cases use no DNS, so these are deterministic and offline-safe.
it('blocks IPv4 loopback', fn() => expect(!Ssrf::isSafeUrl('http://127.0.0.1/x'), 'loopback allowed'));
it('blocks RFC1918 10/8', fn() => expect(!Ssrf::isSafeUrl('http://10.0.0.5/x'), '10/8 allowed'));
it('blocks RFC1918 172.16/12', fn() => expect(!Ssrf::isSafeUrl('http://172.16.5.5/x'), '172.16/12 allowed'));
it('blocks RFC1918 192.168/16', fn() => expect(!Ssrf::isSafeUrl('http://192.168.1.1/x'), '192.168/16 allowed'));
it('blocks CGNAT 100.64/10', fn() => expect(!Ssrf::isSafeUrl('http://100.64.0.1/x'), 'CGNAT allowed'));
it('blocks cloud metadata IP', fn() => expect(!Ssrf::isSafeUrl('http://169.254.169.254/latest/'), 'metadata allowed'));
it('blocks 0.0.0.0', fn() => expect(!Ssrf::isSafeUrl('http://0.0.0.0/x'), '0.0.0.0 allowed'));
it('blocks IPv6 loopback ::1', fn() => expect(!Ssrf::isSafeUrl('https://[::1]/x'), '::1 allowed'));
it('blocks IPv4-mapped IPv6 metadata', fn() => expect(!Ssrf::isSafeUrl('https://[::ffff:169.254.169.254]/x'), 'mapped metadata allowed'));

// Scheme / format hardening.
it('blocks non-http scheme', fn() => expect(!Ssrf::isSafeUrl('ftp://1.1.1.1/x'), 'ftp allowed'));
it('blocks file scheme', fn() => expect(!Ssrf::isSafeUrl('file:///etc/passwd'), 'file allowed'));
it('blocks embedded credentials', fn() => expect(!Ssrf::isSafeUrl('http://u:p@1.1.1.1/x'), 'creds allowed'));
it('blocks malformed url', fn() => expect(!Ssrf::isSafeUrl('not a url'), 'malformed allowed'));
it('requireHttps rejects http', fn() => expect(!Ssrf::isSafeUrl('http://1.1.1.1/x', true), 'http allowed under requireHttps'));

// Public IP literals must be allowed.
it('allows public IPv4 literal', fn() => expect(Ssrf::isSafeUrl('https://1.1.1.1/x'), 'public IP blocked'));

// curlResolve pins the validated IP.
it('curlResolve pins safe host', function () {
    $r = Ssrf::curlResolve('https://1.1.1.1:8443/x');
    expect($r !== null, 'safe host returned null');
    expect_eq('1.1.1.1:8443:1.1.1.1', $r[0], 'resolve entry');
});
it('curlResolve returns null for blocked host', fn() => expect(Ssrf::curlResolve('http://127.0.0.1/x') === null, 'blocked host returned entry'));

// isDangerousInfraHost — narrow guard for operator endpoints (SMTP/S3).
// Blocks loopback + cloud-metadata/link-local, but ALLOWS private ranges
// (on-prem mail relays / self-hosted MinIO must keep working).
it('infra guard blocks cloud metadata', fn() => expect(Ssrf::isDangerousInfraHost('169.254.169.254'), 'metadata allowed'));
it('infra guard blocks loopback v4', fn() => expect(Ssrf::isDangerousInfraHost('127.0.0.1'), 'loopback allowed'));
it('infra guard blocks localhost name', fn() => expect(Ssrf::isDangerousInfraHost('localhost'), 'localhost allowed'));
it('infra guard blocks loopback v6', fn() => expect(Ssrf::isDangerousInfraHost('::1'), '::1 allowed'));
it('infra guard blocks 0.0.0.0', fn() => expect(Ssrf::isDangerousInfraHost('0.0.0.0'), '0.0.0.0 allowed'));
it('infra guard blocks mapped metadata', fn() => expect(Ssrf::isDangerousInfraHost('::ffff:169.254.169.254'), 'mapped metadata allowed'));
it('infra guard blocks empty host', fn() => expect(Ssrf::isDangerousInfraHost(''), 'empty allowed'));
it('infra guard ALLOWS private 10/8', fn() => expect(!Ssrf::isDangerousInfraHost('10.0.0.5'), 'private 10/8 blocked'));
it('infra guard ALLOWS private 192.168/16', fn() => expect(!Ssrf::isDangerousInfraHost('192.168.1.50'), 'private 192.168 blocked'));
it('infra guard ALLOWS private 172.16/12', fn() => expect(!Ssrf::isDangerousInfraHost('172.16.0.10'), 'private 172.16 blocked'));
it('infra guard ALLOWS public IP', fn() => expect(!Ssrf::isDangerousInfraHost('8.8.8.8'), 'public IP blocked'));
