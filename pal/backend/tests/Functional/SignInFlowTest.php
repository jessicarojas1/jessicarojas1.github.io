<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\DatabaseReset;
use App\Tests\Support\FakeEntraId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The complete Entra ID sign-in, end to end, against the in-process fake provider.
 */
final class SignInFlowTest extends WebTestCase
{
    use DatabaseReset;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
        FakeEntraId::reset();
    }

    /**
     * @return array<string, string> the authorization request parameters
     */
    private function startSignIn(string $returnTo = '/library'): array
    {
        $this->client->request('GET', '/auth/login', ['returnTo' => $returnTo]);
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(FakeEntraId::AUTHORITY.'/'.FakeEntraId::TENANT.'/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return array_map('strval', $query);
    }

    public function testAuthorizationRequestUsesPkceStateNonceAndTheConfiguredRedirect(): void
    {
        $query = $this->startSignIn();

        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(43, \strlen($query['code_challenge']));
        self::assertSame('http://localhost/auth/callback', $query['redirect_uri']);
        self::assertSame(FakeEntraId::CLIENT, $query['client_id']);
        self::assertNotSame($query['state'], $query['nonce']);
    }

    public function testSuccessfulSignInProvisionsAuditsAndReturnsToTheRequestedPage(): void
    {
        $query = $this->startSignIn('/library?tab=recent');
        FakeEntraId::$nextIdTokenClaims = ['nonce' => $query['nonce']];

        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);

        self::assertResponseRedirects('/library?tab=recent');
        $user = $this->db->fetchAssociative('SELECT * FROM app_user');
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $user['idp_object_id']);
        self::assertSame('Ada Author', $user['display_name']);
        self::assertSame(['USER_PROVISIONED', 'USER_SIGNED_IN'], $this->db->fetchFirstColumn('SELECT event_type FROM audit_event ORDER BY id'));

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Ada Author', $me['user']['displayName']);
        self::assertSame(['READER'], $me['roles']);
        self::assertSame('America/Denver', $me['defaultTimezone']);
        self::assertNotEmpty($me['csrfToken']);
    }

    public function testStateCanOnlyBeUsedOnce(): void
    {
        $query = $this->startSignIn();
        FakeEntraId::$nextIdTokenClaims = ['nonce' => $query['nonce']];
        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);
        self::assertResponseRedirects('/library');
        $this->client->request('POST', '/api/session/logout', server: ['HTTP_X_CSRF_TOKEN' => $this->csrfToken()]);

        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);

        self::assertResponseRedirects('/?auth_error=sign_in_expired');
    }

    public function testUnknownStateIsRejectedAndAudited(): void
    {
        $this->client->request('GET', '/auth/callback', ['code' => 'x', 'state' => 'forged-state']);

        self::assertResponseRedirects('/?auth_error=sign_in_expired');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM app_user'));
        self::assertSame('USER_SIGN_IN_FAILED', $this->db->fetchOne('SELECT event_type FROM audit_event'));
    }

    public function testNonceMismatchIsRejected(): void
    {
        $query = $this->startSignIn();
        FakeEntraId::$nextIdTokenClaims = ['nonce' => 'not-the-one-we-sent'];

        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);

        self::assertResponseRedirects('/?auth_error=sign_in_failed');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM app_user'));
    }

    public function testProviderErrorIsReportedWithoutLeakingDetails(): void
    {
        $query = $this->startSignIn();
        FakeEntraId::$nextTokenError = 'invalid_grant';

        $this->client->request('GET', '/auth/callback', ['code' => 'expired-code', 'state' => $query['state']]);

        self::assertResponseRedirects('/?auth_error=sign_in_failed');
    }

    public function testUserCancellationAtTheProvider(): void
    {
        $this->client->request('GET', '/auth/callback', ['error' => 'access_denied', 'error_description' => '<script>x</script>']);

        self::assertResponseRedirects('/?auth_error=sign_in_cancelled');
    }

    public function testDisabledUsersCannotSignIn(): void
    {
        self::createUser($this->db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Ada Author', active: false);
        $query = $this->startSignIn();
        FakeEntraId::$nextIdTokenClaims = ['nonce' => $query['nonce']];

        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);

        self::assertResponseRedirects('/?auth_error=account_disabled');
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testOpenRedirectAttemptsLandOnHome(): void
    {
        $query = $this->startSignIn('//evil.example/phish');
        FakeEntraId::$nextIdTokenClaims = ['nonce' => $query['nonce']];

        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);

        self::assertResponseRedirects('/');
    }

    public function testSignInRefreshesGroupMembershipAndGrantsMappedRoles(): void
    {
        $groupObjectId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $groupId = (string) $this->db->fetchOne("INSERT INTO directory_group (idp_tenant_id, idp_object_id, display_name) VALUES (?, ?, 'PAL Publishers') RETURNING id", [FakeEntraId::TENANT, $groupObjectId]);
        $this->db->executeStatement("INSERT INTO global_role_mapping (role, group_id) VALUES ('PUBLISHER', ?)", [$groupId]);
        FakeEntraId::$userGroups['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'] = [$groupObjectId, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'];
        $query = $this->startSignIn();
        FakeEntraId::$nextIdTokenClaims = ['nonce' => $query['nonce']];

        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);
        $this->client->request('GET', '/api/me');

        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['READER', 'PUBLISHER'], $me['roles']);
        self::assertContains('publication.release', $me['permissions']);
    }

    public function testLogoutRequiresCsrfAndEndsTheSession(): void
    {
        $query = $this->startSignIn();
        FakeEntraId::$nextIdTokenClaims = ['nonce' => $query['nonce']];
        $this->client->request('GET', '/auth/callback', ['code' => 'auth-code', 'state' => $query['state']]);

        $this->client->request('POST', '/api/session/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/session/logout', server: ['HTTP_X_CSRF_TOKEN' => $this->csrfToken()]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringStartsWith(FakeEntraId::AUTHORITY.'/'.FakeEntraId::TENANT.'/oauth2/v2.0/logout?post_logout_redirect_uri=', $body['endSessionUrl']);

        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        self::assertContains('USER_SIGNED_OUT', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/me');

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];
    }
}
