<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

use App\Identity\User\PalUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants organisation-wide permissions from the user's current global roles.
 *
 * @extends Voter<string, null>
 */
final class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null === $subject && \in_array($attribute, Permission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof PalUser) {
            return false;
        }

        return \in_array($attribute, RolePermissionMap::permissionsFor($user->globalRoles), true);
    }
}
