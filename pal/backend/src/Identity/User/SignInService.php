<?php

declare(strict_types=1);

namespace App\Identity\User;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Directory\DirectoryException;
use App\Identity\Directory\DirectorySynchronizer;
use App\Identity\Oidc\IdTokenClaims;
use App\Identity\Oidc\OidcException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Turns validated ID-token claims into a PAL user: upsert by immutable (tid, oid),
 * refuse disabled accounts, audit the sign-in, then refresh group memberships.
 */
final class SignInService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DirectorySynchronizer $directory,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string the PAL user ID
     */
    public function signIn(IdTokenClaims $claims): string
    {
        $userId = $this->connection->transactional(function () use ($claims): string {
            $user = $this->users->upsertFromSignIn($claims, $this->clock->now());
            $actor = AuditActor::user($user['id'], $claims->displayName, $claims->objectId);
            if (!$user['is_active']) {
                throw new OidcException('account_disabled');
            }
            if ($user['inserted']) {
                $this->audit->record(new AuditEntry('USER_PROVISIONED', $actor, 'app_user', $user['id'], ['source' => 'sign_in']));
            }
            $this->audit->record(new AuditEntry('USER_SIGNED_IN', $actor, 'app_user', $user['id']));

            return $user['id'];
        });

        // Best effort: a Graph outage must not block sign-in. The last synced memberships
        // stay in force and the scheduled sync catches up.
        if ($this->directory->isConfigured()) {
            try {
                $this->directory->refreshUser($userId, $claims->objectId, AuditActor::system('sign_in_refresh'));
            } catch (DirectoryException $e) {
                $this->logger->warning('Could not refresh group memberships at sign-in', ['user_id' => $userId, 'exception' => $e]);
            }
        }

        return $userId;
    }
}
