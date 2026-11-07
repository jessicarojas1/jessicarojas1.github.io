<?php

declare(strict_types=1);

namespace App\Identity\Local;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Local (username/password) accounts: verification with lockout, creation, password
 * changes and resets, and explicit role assignment.
 */
final class LocalAccountService
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    /** A real hash of a random value: verified when the user does not exist, to equalise timing. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$RnNWc1hIQXFkeDJSNmNpWg$rcZszA0PEE7acISDiloJOHaCYKz8RIkuXKK9oNrq108';

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.login_username')]
        private readonly RateLimiterFactory $usernameLimiter,
    ) {
    }

    /**
     * @return string|null the user ID when the password is correct and the account usable
     */
    public function verify(string $username, string $password): ?string
    {
        $username = self::normalizeUsername($username);
        $row = '' === $username ? false : $this->connection->fetchAssociative(<<<'SQL'
            SELECT u.id, u.display_name, u.username, u.is_active, c.password_hash, c.failed_attempts, c.locked_until
            FROM app_user u JOIN local_credential c ON c.user_id = u.id
            WHERE u.username = ?
            SQL, [$username]);
        if (false === $row) {
            password_verify($password, self::DUMMY_HASH);

            return null;
        }
        $userId = Row::string($row, 'id');
        $now = $this->clock->now();
        $locked = null !== $row['locked_until'] && new \DateTimeImmutable(Row::string($row, 'locked_until')) > $now;
        $correct = password_verify($password, Row::string($row, 'password_hash'));
        $actor = AuditActor::localUser($userId, Row::string($row, 'display_name'), Row::string($row, 'username'));

        if ($locked || !$correct || !Row::bool($row, 'is_active')) {
            $this->connection->transactional(function () use ($userId, $locked, $correct, $row, $now, $actor): void {
                if (!$correct && !$locked) {
                    $attempts = Row::int($row, 'failed_attempts') + 1;
                    $lockUntil = $attempts >= self::MAX_FAILED_ATTEMPTS ? $now->modify('+'.self::LOCKOUT_MINUTES.' minutes') : null;
                    $this->connection->executeStatement(
                        'UPDATE local_credential SET failed_attempts = ?, locked_until = COALESCE(?::timestamptz, locked_until), updated_at = now() WHERE user_id = ?',
                        [null === $lockUntil ? $attempts : 0, $lockUntil?->format('Y-m-d H:i:s.uP'), $userId],
                    );
                    if (null !== $lockUntil) {
                        $this->audit->record(new AuditEntry('USER_LOCKED_OUT', $actor, 'app_user', $userId, ['minutes' => self::LOCKOUT_MINUTES]));
                    }
                }
                $this->audit->record(new AuditEntry('USER_SIGN_IN_FAILED', $actor, 'app_user', $userId, ['method' => 'password'], $locked ? 'locked' : ($correct ? 'inactive' : 'wrong_password')));
            });

            return null;
        }

        $this->connection->transactional(function () use ($userId, $row, $password, $actor): void {
            $rehash = PasswordPolicy::needsRehash(Row::string($row, 'password_hash')) ? PasswordPolicy::hash($password) : null;
            $this->connection->executeStatement(
                'UPDATE local_credential SET failed_attempts = 0, locked_until = NULL, password_hash = COALESCE(?, password_hash), updated_at = now() WHERE user_id = ?',
                [$rehash, $userId],
            );
            $this->connection->executeStatement('UPDATE app_user SET last_login_at = now(), first_login_at = COALESCE(first_login_at, now()) WHERE id = ?', [$userId]);
            $this->audit->record(new AuditEntry('USER_SIGNED_IN', $actor, 'app_user', $userId, ['method' => 'password']));
        });

        return $userId;
    }

    /**
     * @param list<GlobalRole> $roles
     *
     * @return array{id: string, temporaryPassword: string}
     */
    public function create(AuditActor $actor, ?string $actingUserId, string $username, string $displayName, ?string $email, array $roles): array
    {
        $username = self::normalizeUsername($username);
        $displayName = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $displayName));
        $errors = [];
        $usernameReason = self::usernameRejectionReason($username);
        if (null !== $usernameReason) {
            $errors['username'] = $usernameReason;
        }
        if ('' === $displayName || mb_strlen($displayName) > 256) {
            $errors['displayName'] = 'Enter the person\'s name.';
        }
        if (null !== $email && '' !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address, or leave it empty.';
        }
        if ([] !== $errors) {
            throw ApiProblem::validation($errors);
        }
        $temporary = PasswordPolicy::temporary();

        try {
            $id = $this->connection->transactional(function () use ($actor, $actingUserId, $username, $displayName, $email, $roles, $temporary): string {
                $id = Row::scalarString($this->connection->fetchOne(
                    "INSERT INTO app_user (username, display_name, email, source) VALUES (?, ?, ?, 'LOCAL') RETURNING id",
                    [$username, $displayName, '' === $email ? null : $email],
                ));
                $this->connection->executeStatement('INSERT INTO local_credential (user_id, password_hash, must_change) VALUES (?, ?, true)', [$id, PasswordPolicy::hash($temporary)]);
                foreach ($roles as $role) {
                    $this->connection->executeStatement('INSERT INTO user_role (user_id, role, granted_by) VALUES (?, ?, ?)', [$id, $role->value, $actingUserId]);
                }
                $this->audit->record(new AuditEntry('LOCAL_USER_CREATED', $actor, 'app_user', $id, [
                    'username' => $username, 'display_name' => $displayName,
                    'roles' => array_map(fn (GlobalRole $r): string => $r->value, $roles),
                ]));

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::validation(['username' => 'That username is already taken.']);
        }

        return ['id' => $id, 'temporaryPassword' => $temporary];
    }

    public function changePassword(PalUser $user, string $current, string $new): void
    {
        if (!$user->isLocal()) {
            throw new ApiProblem(409, 'not_local_account', 'This account signs in with single sign-on; its password is managed there.');
        }
        $hash = $this->connection->fetchOne('SELECT password_hash FROM local_credential WHERE user_id = ?', [$user->id]);
        if (!\is_string($hash) || !password_verify($current, $hash)) {
            // Rate-limit and audit a wrong current password, so a hijacked session cannot
            // brute-force it silently. The username limiter is shared with sign-in.
            $username = (string) $user->username;
            $allowed = '' === $username || $this->usernameLimiter->create($username)->consume()->isAccepted();
            $this->connection->transactional(fn () => $this->audit->record(
                new AuditEntry('USER_PASSWORD_CHANGE_FAILED', $user->auditActor(), 'app_user', $user->id),
            ));
            if (!$allowed) {
                throw new ApiProblem(429, 'too_many_attempts', 'Too many password attempts. Please wait and try again.');
            }
            throw ApiProblem::validation(['currentPassword' => 'Your current password is not correct.']);
        }
        $reason = PasswordPolicy::rejectionReason($new, $user->username);
        if (null === $reason && password_verify($new, $hash)) {
            $reason = 'Choose a password different from your current one.';
        }
        if (null !== $reason) {
            throw ApiProblem::validation(['newPassword' => $reason]);
        }
        $this->connection->transactional(function () use ($user, $new): void {
            $this->connection->executeStatement(
                'UPDATE local_credential SET password_hash = ?, must_change = false, password_changed_at = now(), failed_attempts = 0, locked_until = NULL, updated_at = now() WHERE user_id = ?',
                [PasswordPolicy::hash($new), $user->id],
            );
            $this->audit->record(new AuditEntry('USER_PASSWORD_CHANGED', $user->auditActor(), 'app_user', $user->id));
        });
    }

    /**
     * @return string the new temporary password (shown once to the administrator)
     */
    public function resetPassword(PalUser $admin, string $userId): string
    {
        $temporary = PasswordPolicy::temporary();
        $this->connection->transactional(function () use ($admin, $userId, $temporary): void {
            $updated = $this->connection->executeStatement(
                'UPDATE local_credential SET password_hash = ?, must_change = true, failed_attempts = 0, locked_until = NULL, password_changed_at = now(), updated_at = now() WHERE user_id = ?',
                [PasswordPolicy::hash($temporary), $userId],
            );
            if (1 !== $updated) {
                throw new ApiProblem(404, 'not_found', 'Local account not found.');
            }
            $this->audit->record(new AuditEntry('USER_PASSWORD_RESET', $admin->auditActor(), 'app_user', $userId));
        });

        return $temporary;
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    /**
     * The local-username format rule, shared by account creation and the bootstrap-admin command.
     * Returns a human-readable reason when the (normalised) username is not acceptable, or null.
     * A username is NOT an email address: `@` and uppercase are rejected.
     */
    public static function usernameRejectionReason(string $username): ?string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', self::normalizeUsername($username))) {
            return 'Use 3–64 lowercase letters, digits, dots, hyphens or underscores (not an email address).';
        }

        return null;
    }
}
