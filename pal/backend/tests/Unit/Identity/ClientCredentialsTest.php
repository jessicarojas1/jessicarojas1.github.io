<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Oidc\ClientCredentials;
use App\Identity\Oidc\OidcSettings;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ClientCredentialsTest extends TestCase
{
    private const TENANT = '11111111-1111-4111-8111-111111111111';
    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    public function testSecretIsSentWhenConfigured(): void
    {
        $settings = new OidcSettings('https://login.microsoftonline.com', self::TENANT, self::CLIENT, 's3cret', '', '', 'http://localhost');

        self::assertSame(['client_secret' => 's3cret'], (new ClientCredentials($settings, new MockClock()))->tokenRequestParameters('https://token'));
    }

    public function testCertificateAssertionIsAValidRs256JwtWithThumbprints(): void
    {
        $dir = sys_get_temp_dir().'/pal-cert-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'pal-test'], $key);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($cert);
        openssl_x509_export_to_file($cert, $dir.'/cert.pem');
        openssl_pkey_export_to_file($key, $dir.'/key.pem');

        try {
            $settings = new OidcSettings('https://login.microsoftonline.com', self::TENANT, self::CLIENT, '', $dir.'/cert.pem', $dir.'/key.pem', 'http://localhost');
            $params = (new ClientCredentials($settings, new MockClock()))->tokenRequestParameters('https://login.microsoftonline.com/token');

            self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $params['client_assertion_type']);
            [$headerB64] = explode('.', $params['client_assertion']);
            $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
            self::assertSame('RS256', $header['alg']);
            self::assertArrayHasKey('x5t', $header);
            self::assertArrayHasKey('x5t#S256', $header);

            JWT::$timestamp = (new MockClock())->now()->getTimestamp();
            $claims = (array) JWT::decode($params['client_assertion'], new Key(openssl_pkey_get_details($key)['key'], 'RS256'));
            JWT::$timestamp = null;
            self::assertSame(self::CLIENT, $claims['iss']);
            self::assertSame(self::CLIENT, $claims['sub']);
            self::assertSame('https://login.microsoftonline.com/token', $claims['aud']);
            self::assertLessThanOrEqual(300, $claims['exp'] - $claims['iat']);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function testSettingsRejectAmbiguousOrMissingCredentials(): void
    {
        $both = new OidcSettings('https://login.microsoftonline.com', self::TENANT, self::CLIENT, 'secret', '/c.pem', '/k.pem', 'http://localhost');
        $neither = new OidcSettings('https://login.microsoftonline.com', self::TENANT, self::CLIENT, '', '', '', 'http://localhost');
        $placeholder = new OidcSettings('https://login.microsoftonline.com', '00000000-0000-0000-0000-000000000000', self::CLIENT, 'secret', '', '', 'http://localhost');

        self::assertFalse($both->isConfigured());
        self::assertFalse($neither->isConfigured());
        self::assertFalse($placeholder->isConfigured());
    }
}
