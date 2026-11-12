<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\AuditActor;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Local\LocalAccountService;
use App\Identity\Local\PasswordPolicy;
use App\Identity\Security\PalUserProvider;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LocalSignInTest extends WebTestCase
{
    use DatabaseReset;

    private const PASSWORD = 'correct horse battery staple';

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
    }

    /**
     * Creates a local account with a known, already-changed password.
     *
     * @param list<GlobalRole> $roles
     */
    private function account(string $username, array $roles = [], bool $mustChange = false): string
    {
        $created = self::getContainer()->get(LocalAccountService::class)->create(AuditActor::system('test'), null, $username, ucfirst($username), null, $roles);
        $this->db->executeStatement('UPDATE local_credential SET password_hash = ?, must_change = ? WHERE user_id = ?', [PasswordPolicy::hash(self::PASSWORD), $mustChange ? 'true' : 'false', $created['id']]);

        return $created['id'];
    }

    private function loginCsrf(): string
    {
        $this->client->request('GET', '/auth/methods');

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];
    }

    private function signIn(string $username, string $password, ?string $csrf = null): int
    {
        $csrf ??= $this->loginCsrf();
        $this->client->request('POST', '/auth/password', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode(['username' => $username, 'password' => $password]));

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    private function apiCsrf(): string
    {
        $this->client->request('GET', '/api/me');

        return (string) $this->json()['csrfToken'];
    }

    public function testSignInMethodsExposeStatusButNoConfiguration(): void
    {
        $this->client->request('GET', '/auth/methods');
        $body = $this->json();

        self::assertNotEmpty($body['csrfToken']);
        $methods = array_column($body['methods'], 'connected', 'id');
        self::assertSame(['password' => true, 'entra' => true, 'okta' => false, 'google' => false, 'saml' => false], $methods);
        self::assertStringNotContainsString('11111111-1111-4111-8111-111111111111', (string) $this->client->getResponse()->getContent(), 'tenant IDs are not exposed');
    }

    public function testSuccessfulSignInRotatesTheSessionAndAudits(): void
    {
        $this->account('ada');
        $csrf = $this->loginCsrf();
        $before = $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();

        self::assertSame(200, $this->signIn('ADA ', self::PASSWORD, $csrf), 'usernames are case-insensitive and trimmed');

        self::assertNotSame($before, $this->client->getCookieJar()->get('MOCKSESSID')?->getValue(), 'session id must change at sign-in');
        $this->client->request('GET', '/api/me');
        self::assertSame('ada', $this->json()['user']['username']);
        self::assertSame('password', $this->json()['user']['signInMethod']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'USER_SIGNED_IN' AND changes->>'method' = 'password'"));
    }

    public function testFailuresAreIndistinguishableWhetherOrNotTheAccountExists(): void
    {
        $this->account('ada');

        $this->signIn('ada', 'wrong password entirely');
        $wrongPassword = $this->client->getResponse()->getContent();
        $this->signIn('nobody-here', 'wrong password entirely');
        $unknownUser = $this->client->getResponse()->getContent();

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame($wrongPassword, $unknownUser);
    }

    public function testRepeatedFailuresLockTheAccountEvenAgainstTheRightPassword(): void
    {
        $this->account('ada');
        for ($i = 0; $i < LocalAccountService::MAX_FAILED_ATTEMPTS; ++$i) {
            $this->signIn('ada', 'not the password '.$i);
        }

        self::assertSame(401, $this->signIn('ada', self::PASSWORD));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'USER_LOCKED_OUT'"));
        self::assertNotNull($this->db->fetchOne('SELECT locked_until FROM local_credential'));

        // Once the lock expires, the right password works again and counters reset.
        $this->db->executeStatement("UPDATE local_credential SET locked_until = now() - interval '1 second'");
        self::assertSame(200, $this->signIn('ada', self::PASSWORD));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT failed_attempts FROM local_credential'));
    }

    public function testLoginCsrfIsRequired(): void
    {
        $id = $this->account('ada');

        self::assertSame(401, $this->signIn('ada', self::PASSWORD, 'forged-token'));
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        // A refused request leaves no trace of a successful sign-in.
        self::assertNotContains('USER_SIGNED_IN', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));
        self::assertNull($this->db->fetchOne('SELECT last_login_at FROM app_user WHERE id = ?', [$id]));
    }

    public function testASystemAdministratorMayAssignDocumentAuthority(): void
    {
        // Decision #30: a System Administrator is the instance's role manager and may assign
        // any role, including document authority, directly or to an account they control.
        $this->account('root', [GlobalRole::SystemAdministrator]);
        $publisherId = $this->account('pat', [GlobalRole::Publisher]);
        $plainId = $this->account('plain');
        $this->signIn('root', self::PASSWORD);
        $csrf = $this->apiCsrf();
        $send = fn (string $method, string $uri, array $body = []) => $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body));

        // Creating a document-authority account.
        $send('POST', '/api/admin/users', ['username' => 'sock', 'displayName' => 'Sock', 'roles' => ['PUBLISHER']]);
        self::assertResponseStatusCodeSame(201);
        self::assertNotFalse($this->db->fetchOne("SELECT 1 FROM app_user WHERE username = 'sock'"));
        // Promoting an existing account.
        $send('PUT', '/api/admin/users/'.$plainId.'/roles', ['roles' => ['AUTHOR']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['AUTHOR'], $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$plainId]));
        // Resetting an account that already has authority.
        $send('POST', '/api/admin/users/'.$publisherId.'/reset-password');
        self::assertResponseIsSuccessful();
    }

    public function testTheLastActiveSystemAdministratorIsProtected(): void
    {
        $adminId = $this->account('root', [GlobalRole::SystemAdministrator]);
        $this->signIn('root', self::PASSWORD);
        $csrf = $this->apiCsrf();

        $this->client->request('PUT', '/api/admin/users/'.$adminId.'/roles', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode(['roles' => []]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['SYSTEM_ADMINISTRATOR'], $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$adminId]));
    }

    public function testUnlockCommandClearsALockoutAndIsAudited(): void
    {
        $id = $this->account('ada');
        $this->db->executeStatement("UPDATE local_credential SET failed_attempts = 5, locked_until = now() + interval '15 minutes' WHERE user_id = ?", [$id]);
        self::assertSame(401, $this->signIn('ada', self::PASSWORD));

        $tester = new CommandTester((new Application(self::$kernel))->find('pal:users:unlock'));
        self::assertSame(0, $tester->execute(['username' => 'ADA']));
        self::assertSame(1, $tester->execute(['username' => 'nobody']));

        self::assertSame(200, $this->signIn('ada', self::PASSWORD));
        self::assertContains('USER_UNLOCKED', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));
    }

    public function testInactiveAccountsCannotSignIn(): void
    {
        $id = $this->account('ada');
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$id]);

        self::assertSame(401, $this->signIn('ada', self::PASSWORD));
    }

    public function testTemporaryPasswordsMustBeChangedBeforeAnythingElse(): void
    {
        $this->account('ada', [GlobalRole::Author], mustChange: true);

        self::assertSame(200, $this->signIn('ada', self::PASSWORD));
        self::assertTrue($this->json()['mustChangePassword']);
        $csrf = $this->apiCsrf();
        $this->client->request('GET', '/api/library');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('password_change_required', $this->json()['error']);

        $change = fn (string $current, string $new) => $this->client->request('POST', '/api/me/password', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode(['currentPassword' => $current, 'newPassword' => $new]));
        $change('wrong current', 'a brand new passphrase');
        self::assertResponseStatusCodeSame(422);
        $change(self::PASSWORD, 'short');
        self::assertResponseStatusCodeSame(422);
        $change(self::PASSWORD, 'ada-is-my-name-here');
        self::assertResponseStatusCodeSame(422);
        $change(self::PASSWORD, self::PASSWORD);
        self::assertResponseStatusCodeSame(422);
        $change(self::PASSWORD, 'a brand new passphrase');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/library');
        self::assertResponseIsSuccessful();
    }

    /** Age a credential so a temporary password is older than the 24h test window. */
    private function ageCredential(string $userId, string $interval, bool $mustChange = true): void
    {
        $this->db->executeStatement(
            'UPDATE local_credential SET must_change = ?, password_changed_at = now() - ?::interval WHERE user_id = ?',
            [$mustChange ? 'true' : 'false', $interval, $userId],
        );
    }

    public function testExpiredTemporaryPasswordsAreRefusedWithADistinctError(): void
    {
        $id = $this->account('ivy', mustChange: true);
        $this->ageCredential($id, '48 hours'); // the test window is 24h

        self::assertSame(403, $this->signIn('ivy', self::PASSWORD), 'a correct but expired temporary password is refused');
        self::assertSame('password_expired', $this->json()['error']);
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT count(*) FROM audit_event WHERE subject_id = ? AND event_type = 'USER_SIGN_IN_FAILED' AND reason = 'temporary_password_expired'",
            [$id],
        ));
        self::assertSame(0, (int) $this->db->fetchOne(
            "SELECT count(*) FROM audit_event WHERE subject_id = ? AND event_type = 'USER_SIGNED_IN'",
            [$id],
        ), 'an expired sign-in is never recorded as a success');
    }

    public function testPermanentPasswordsNeverExpire(): void
    {
        $id = $this->account('hank');
        $this->ageCredential($id, '1000 hours', mustChange: false);

        self::assertSame(200, $this->signIn('hank', self::PASSWORD), 'only must-change passwords expire');
    }

    public function testResettingAnExpiredTemporaryPasswordRestoresSignIn(): void
    {
        $id = $this->account('nora', mustChange: true);
        $this->ageCredential($id, '48 hours');
        self::assertSame(403, $this->signIn('nora', self::PASSWORD));

        // An administrator reset (or a bootstrap re-run) stamps a fresh password_changed_at.
        $this->ageCredential($id, '0 hours');
        self::assertSame(200, $this->signIn('nora', self::PASSWORD), 'a fresh temporary password signs in again');
        self::assertTrue($this->json()['mustChangePassword']);
    }

    public function testPasswordChangeRevokesOtherSessionsAndAuditsWrongCurrentPassword(): void
    {
        $id = $this->account('eve');
        self::assertSame(200, $this->signIn('eve', self::PASSWORD));
        $csrf = $this->apiCsrf();

        // A wrong current password is rejected and audited, so a hijacked session cannot
        // brute-force it silently.
        $this->client->request('POST', '/api/me/password', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode(['currentPassword' => 'not my password', 'newPassword' => 'a completely new passphrase']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'USER_PASSWORD_CHANGE_FAILED' AND subject_id = ?", [$id]));

        // The current session is still valid.
        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        // Another session (or an admin reset) moves the credential version. A session still
        // holding the previous version must be revoked on its next request.
        $this->db->executeStatement("UPDATE local_credential SET password_changed_at = password_changed_at + interval '1 second' WHERE user_id = ?", [$id]);
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdministratorsManageLocalAccountsAndRoles(): void
    {
        $adminId = $this->account('root', [GlobalRole::SystemAdministrator, GlobalRole::PalAdministrator]);
        $this->signIn('root', self::PASSWORD);
        $csrf = $this->apiCsrf();
        $send = fn (string $method, string $uri, array $body = []) => $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body));

        $send('POST', '/api/admin/users', ['username' => 'bea', 'displayName' => 'Bea Author', 'roles' => ['AUTHOR']]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{5}(-[A-Za-z0-9]{5}){3}$/', $created['temporaryPassword']);
        self::assertNotSame($created['temporaryPassword'], $this->db->fetchOne('SELECT password_hash FROM local_credential WHERE user_id = ?', [$created['id']]), 'only a hash is stored');

        $send('POST', '/api/admin/users', ['username' => 'bea', 'displayName' => 'Duplicate']);
        self::assertResponseStatusCodeSame(422);

        // Decision #30: a System Administrator may grant themselves document authority.
        $send('PUT', '/api/admin/users/'.$adminId.'/roles', ['roles' => ['SYSTEM_ADMINISTRATOR', 'PUBLISHER']]);
        self::assertResponseIsSuccessful();
        self::assertContains('PUBLISHER', $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$adminId]));
        $send('PUT', '/api/admin/users/'.$created['id'].'/roles', ['roles' => ['AUTHOR', 'PUBLISHER']]);
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(['AUTHOR', 'PUBLISHER'], $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$created['id']]));

        $send('POST', '/api/admin/users/'.$created['id'].'/reset-password');
        self::assertResponseIsSuccessful();
        self::assertTrue((bool) $this->db->fetchOne('SELECT must_change FROM local_credential WHERE user_id = ?', [$created['id']]));

        $send('PUT', '/api/admin/users/'.$adminId.'/active', ['isActive' => false]);
        self::assertResponseStatusCodeSame(409);

        $this->client->request('GET', '/api/admin/users?q=bea');
        self::assertSame(['Bea Author'], array_column($this->json()['users'], 'displayName'));
        self::assertContains('USER_ROLES_CHANGED', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));
    }

    public function testNonAdministratorsCannotManageUsers(): void
    {
        $this->account('ada', [GlobalRole::PalAdministrator]);
        $this->signIn('ada', self::PASSWORD);

        $this->client->request('GET', '/api/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEntraAndLocalUsersShareTheRoleModel(): void
    {
        $entraUser = self::createUser($this->db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Eve Entra');
        $this->db->executeStatement("INSERT INTO user_role (user_id, role) VALUES (?, 'PUBLISHER')", [$entraUser]);

        $user = self::getContainer()->get(PalUserProvider::class)->loadUserByIdentifier($entraUser);

        self::assertSame([GlobalRole::Reader, GlobalRole::Publisher], $user->globalRoles);
        self::assertFalse($user->isLocal());
    }

    public function testBootstrapCreatesTheFirstAdministratorOnlyOnce(): void
    {
        $run = function (string $password): CommandTester {
            $_ENV['PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME'] = $_SERVER['PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME'] = 'admin';
            $_ENV['PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD'] = $_SERVER['PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD'] = $password;
            self::ensureKernelShutdown();
            $tester = new CommandTester((new Application(self::bootKernel()))->find('pal:users:bootstrap-admin'));
            $tester->execute([]);

            return $tester;
        };

        try {
            self::assertSame(1, $run('weak')->getStatusCode(), 'weak bootstrap passwords are refused');
            self::assertSame(0, $run('a strong bootstrap passphrase')->getStatusCode());
            self::assertStringContainsString('already exists', $run('another strong passphrase')->getDisplay());
            $db = self::getContainer()->get(Connection::class);
            self::assertSame(1, (int) $db->fetchOne("SELECT count(*) FROM app_user WHERE source = 'LOCAL'"));
            self::assertTrue((bool) $db->fetchOne('SELECT must_change FROM local_credential'));
            // The bootstrap account is the Owner (superuser): the account's root of trust.
            self::assertEqualsCanonicalizing(['OWNER'], $db->fetchFirstColumn('SELECT role FROM user_role'));
        } finally {
            unset($_ENV['PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME'], $_SERVER['PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME'], $_ENV['PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD'], $_SERVER['PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD']);
        }
    }
}
