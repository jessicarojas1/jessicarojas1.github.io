<?php

declare(strict_types=1);

namespace App\Settings\Controller;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\Permission;
use App\Identity\User\PalUser;
use App\Platform\Http\JsonBody;
use App\Settings\Branding;
use App\Settings\BrandingSanitizer;
use App\Settings\SettingsRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BrandingController
{
    private const KEY = 'branding';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Public: the sign-in screen shows the organisation's logo and name before sign-in.
     */
    #[Route('/api/branding', name: 'api_branding_get', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse(Branding::fromStorage($this->settings->get(self::KEY))->effective());
    }

    #[Route('/api/admin/branding', name: 'api_branding_update', methods: ['PUT'])]
    #[IsGranted(Permission::SYSTEM_CONFIG)]
    public function update(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        $branding = BrandingSanitizer::sanitize(JsonBody::decode($request, BrandingSanitizer::MAX_LOGO_DATA_URL_LENGTH + 4096));
        $before = Branding::fromStorage($this->settings->get(self::KEY));

        $this->connection->transactional(function () use ($branding, $before, $user): void {
            $this->settings->set(self::KEY, $branding->toStorage(), $user->id);
            $this->audit->record(new AuditEntry(
                'BRANDING_UPDATED',
                AuditActor::user($user->id, $user->displayName, $user->objectId),
                'org_setting',
                self::KEY,
                [
                    'displayName' => ['from' => $before->displayName, 'to' => $branding->displayName],
                    // Logos can be large data URLs: record a fingerprint, not the image.
                    'logo' => ['from' => self::fingerprint($before->logoUrl), 'to' => self::fingerprint($branding->logoUrl)],
                    'accentColor' => ['from' => $before->accentColor, 'to' => $branding->accentColor],
                ],
            ));
        });

        return new JsonResponse(['ok' => true, 'branding' => $branding->effective()]);
    }

    private static function fingerprint(?string $logo): ?string
    {
        if (null === $logo) {
            return null;
        }

        return str_starts_with($logo, 'data:') ? 'data-url sha256:'.hash('sha256', $logo) : $logo;
    }
}
