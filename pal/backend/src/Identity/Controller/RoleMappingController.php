<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\Permission;
use App\Identity\Directory\DirectoryException;
use App\Identity\Directory\DirectorySynchronizer;
use App\Identity\Directory\RoleMappingRepository;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Maps directory groups to global roles. The group is resolved and tracked through the
 * directory first, so a mapping can never point at a group PAL cannot sync.
 */
#[IsGranted(Permission::ADMIN_ROLES)]
final class RoleMappingController
{
    public function __construct(
        private readonly RoleMappingRepository $mappings,
        private readonly DirectorySynchronizer $directory,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/admin/role-mappings', name: 'api_role_mappings_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'roles' => GlobalRole::mappableValues(),
            'directoryConfigured' => $this->directory->isConfigured(),
            'mappings' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'role' => $row['role'],
                'group' => [
                    'objectId' => $row['idp_object_id'],
                    'displayName' => $row['display_name'],
                    'memberCount' => Row::int($row, 'member_count'),
                    'syncedAt' => $row['synced_at'],
                    'syncError' => $row['sync_error'],
                ],
                'createdAt' => $row['created_at'],
            ], $this->mappings->all()),
        ]);
    }

    #[Route('/api/admin/role-mappings', name: 'api_role_mappings_add', methods: ['POST'])]
    public function add(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $role = \is_string($body['role'] ?? null) ? GlobalRole::tryFrom($body['role']) : null;
        $objectId = \is_string($body['groupObjectId'] ?? null) ? strtolower(trim($body['groupObjectId'])) : '';
        $errors = [];
        if (null === $role || !$role->isMappable()) {
            $errors['role'] = 'Choose one of: '.implode(', ', GlobalRole::mappableValues()).'.';
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $objectId)) {
            $errors['groupObjectId'] = 'Enter the group\'s Entra object ID (a GUID).';
        }
        if ([] !== $errors || null === $role) {
            throw ApiProblem::validation($errors);
        }
        if (!$this->directory->isConfigured()) {
            throw new ApiProblem(503, 'directory_unavailable', 'Directory sync is not configured, so groups cannot be resolved.');
        }

        $actor = AuditActor::user($user->id, $user->displayName, $user->objectId);
        try {
            $group = $this->directory->trackGroup($objectId, $actor);
        } catch (DirectoryException $e) {
            $this->logger->warning('Could not resolve directory group', ['exception' => $e]);
            throw new ApiProblem(502, 'directory_lookup_failed', 'The group could not be found in the directory.');
        }

        $mappingId = $this->connection->transactional(function () use ($role, $group, $user, $actor, $objectId): ?string {
            $id = $this->mappings->add($role->value, $group['id'], $user->id);
            if (null !== $id) {
                $this->audit->record(new AuditEntry('ROLE_MAPPING_ADDED', $actor, 'global_role_mapping', $id, [
                    'role' => $role->value,
                    'group_object_id' => $objectId,
                    'group_display_name' => $group['display_name'],
                ]));
            }

            return $id;
        });
        if (null === $mappingId) {
            throw new ApiProblem(409, 'mapping_exists', 'This group is already mapped to that role.');
        }

        try {
            $this->directory->syncGroup($group['id'], $objectId, $actor);
        } catch (DirectoryException $e) {
            // The mapping is saved; membership arrives with the next scheduled sync.
            $this->logger->warning('Initial membership sync failed for a new role mapping', ['exception' => $e]);
        }

        return new JsonResponse(['ok' => true, 'id' => $mappingId], 201);
    }

    #[Route('/api/admin/role-mappings/{id}', name: 'api_role_mappings_remove', methods: ['DELETE'])]
    public function remove(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $mapping = $this->mappings->find($id);
        if (null === $mapping) {
            throw new ApiProblem(404, 'not_found', 'Role mapping not found.');
        }
        $this->connection->transactional(function () use ($mapping, $user, $id): void {
            $this->mappings->remove($id);
            $this->audit->record(new AuditEntry('ROLE_MAPPING_REMOVED', AuditActor::user($user->id, $user->displayName, $user->objectId), 'global_role_mapping', $id, [
                'role' => $mapping['role'],
                'group_object_id' => $mapping['idp_object_id'],
                'group_display_name' => $mapping['display_name'],
            ]));
        });

        return new JsonResponse(['ok' => true]);
    }
}
