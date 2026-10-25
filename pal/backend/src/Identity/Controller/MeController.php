<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\RolePermissionMap;
use App\Identity\Security\CsrfHeaderSubscriber;
use App\Identity\Security\SessionLifetimeSubscriber;
use App\Identity\User\PalUser;
use App\Identity\User\UserRepository;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use App\Settings\SettingsRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly SettingsRepository $settings,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
        #[Autowire(env: 'int:PAL_SESSION_IDLE_SECONDS')]
        private readonly int $idleSeconds,
        #[Autowire(env: 'int:PAL_SESSION_ABSOLUTE_SECONDS')]
        private readonly int $absoluteSeconds,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        $authTime = $request->getSession()->get(SessionLifetimeSubscriber::AUTH_TIME_KEY);

        return new JsonResponse([
            'user' => [
                'id' => $user->id,
                'displayName' => $user->displayName,
                'email' => $user->email,
                'timezone' => $user->timezone,
            ],
            'roles' => array_map(fn (GlobalRole $role): string => $role->value, $user->globalRoles),
            'permissions' => RolePermissionMap::permissionsFor($user->globalRoles),
            'csrfToken' => $this->csrfTokens->getToken(CsrfHeaderSubscriber::TOKEN_ID)->getValue(),
            'defaultTimezone' => $this->settings->defaultTimezone(),
            'session' => [
                'idleTimeoutSeconds' => $this->idleSeconds,
                'absoluteExpiresAt' => \is_int($authTime) ? gmdate('Y-m-d\TH:i:s\Z', $authTime + $this->absoluteSeconds) : null,
            ],
        ]);
    }

    #[Route('/api/me/preferences', name: 'api_me_preferences', methods: ['PATCH'])]
    public function updatePreferences(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        if (!\array_key_exists('timezone', $body)) {
            throw ApiProblem::validation(['timezone' => 'Provide a timezone, or null to use the organisation default.']);
        }
        $timezone = $body['timezone'];
        if (null !== $timezone && (!\is_string($timezone) || !\in_array($timezone, \DateTimeZone::listIdentifiers(), true))) {
            throw ApiProblem::validation(['timezone' => 'Use an IANA timezone name such as America/Denver.']);
        }

        if ($timezone !== $user->timezone) {
            $this->connection->transactional(function () use ($user, $timezone): void {
                $this->users->updateTimezone($user->id, $timezone);
                $this->audit->record(new AuditEntry(
                    'USER_PREFERENCES_CHANGED',
                    AuditActor::user($user->id, $user->displayName, $user->objectId),
                    'app_user',
                    $user->id,
                    ['timezone' => ['from' => $user->timezone, 'to' => $timezone]],
                ));
            });
        }

        return new JsonResponse(['ok' => true, 'timezone' => $timezone]);
    }
}
