<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Oidc\IdTokenValidator;
use App\Identity\Oidc\OidcException;
use App\Identity\Oidc\OidcProviderMetadata;
use App\Identity\Oidc\OidcSettings;
use App\Tests\Support\FakeEntraId;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class IdTokenValidatorTest extends TestCase
{
    private const NONCE = 'expected-nonce';

    private function validator(?MockClock $clock = null): IdTokenValidator
    {
        $settings = new OidcSettings(FakeEntraId::AUTHORITY, FakeEntraId::TENANT, FakeEntraId::CLIENT, 'secret', '', '', 'http://localhost');
        $metadata = new OidcProviderMetadata(new MockHttpClient(new FakeEntraId()), new ArrayAdapter(), $settings);

        return new IdTokenValidator($metadata, $settings, $clock ?? new MockClock());
    }

    public function testAValidTokenYieldsClaims(): void
    {
        $claims = $this->validator()->validate(FakeEntraId::mintIdToken(['nonce' => self::NONCE]), self::NONCE);

        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $claims->objectId);
        self::assertSame(FakeEntraId::TENANT, $claims->tenantId);
        self::assertSame('Ada Author', $claims->displayName);
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('rejected')]
    public function testInvalidTokensAreRejected(array $claims): void
    {
        $this->expectException(OidcException::class);
        $this->validator()->validate(FakeEntraId::mintIdToken(['nonce' => self::NONCE, ...$claims]), self::NONCE);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejected(): iterable
    {
        yield 'other issuer' => [['iss' => 'https://login.microsoftonline.com/33333333-3333-4333-8333-333333333333/v2.0']];
        yield 'other audience' => [['aud' => '44444444-4444-4444-8444-444444444444']];
        yield 'other tenant' => [['tid' => '33333333-3333-4333-8333-333333333333']];
        yield 'wrong nonce' => [['nonce' => 'replayed']];
        yield 'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200, 'nbf' => time() - 7200]];
        yield 'not yet valid' => [['nbf' => time() + 3600]];
        yield 'missing exp' => [['exp' => null]];
        yield 'missing oid' => [['oid' => null]];
        yield 'multi-audience without azp' => [['aud' => [FakeEntraId::CLIENT, 'other']]];
    }

    public function testATokenSignedByAnotherKeyIsRejected(): void
    {
        $attacker = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($attacker);

        $this->expectException(OidcException::class);
        $this->validator()->validate(FakeEntraId::mintIdToken(['nonce' => self::NONCE], $attacker), self::NONCE);
    }

    public function testAnHs256TokenUsingThePublicKeyAsSecretIsRejected(): void
    {
        // Classic algorithm-confusion attack: keys are pinned to RS256, so this must fail.
        $public = openssl_pkey_get_details(FakeEntraId::key())['key'];
        $forged = JWT::encode(['iss' => FakeEntraId::issuer(), 'aud' => FakeEntraId::CLIENT, 'tid' => FakeEntraId::TENANT, 'oid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'nonce' => self::NONCE, 'iat' => time(), 'exp' => time() + 60], $public, 'HS256', 'test-key-1');

        $this->expectException(OidcException::class);
        $this->validator()->validate($forged, self::NONCE);
    }

    public function testUnsignedTokensAreRejected(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none","kid":"test-key-1"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['iss' => FakeEntraId::issuer(), 'nonce' => self::NONCE])), '+/', '-_'), '=');

        $this->expectException(OidcException::class);
        $this->validator()->validate($header.'.'.$payload.'.', self::NONCE);
    }

    public function testUnknownKeyIdTriggersOneRefreshThenFails(): void
    {
        $this->expectException(OidcException::class);
        $this->validator()->validate(FakeEntraId::mintIdToken(['nonce' => self::NONCE], null, 'rotated-key'), self::NONCE);
    }
}
