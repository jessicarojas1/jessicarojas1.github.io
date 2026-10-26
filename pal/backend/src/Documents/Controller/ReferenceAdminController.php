<?php

declare(strict_types=1);

namespace App\Documents\Controller;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Documents\Ids;
use App\Documents\ReferenceRepository;
use App\Identity\Authorization\Permission;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Document types and departments. Codes are immutable once created (they appear inside
 * document numbers); names and the active flag can change. Nothing is ever deleted.
 */
final class ReferenceAdminController
{
    public function __construct(
        private readonly ReferenceRepository $reference,
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/api/admin/reference', name: 'api_admin_reference', methods: ['GET'])]
    #[IsGranted(Permission::ADMIN_TYPES)]
    public function list(): JsonResponse
    {
        return new JsonResponse(['documentTypes' => $this->reference->documentTypes(false), 'departments' => $this->reference->departments(false)]);
    }

    #[Route('/api/admin/departments', name: 'api_admin_department_create', methods: ['POST'])]
    #[IsGranted(Permission::ADMIN_DEPARTMENTS)]
    public function createDepartment(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        return $this->create($user, $request, 'org_unit', '/^[A-Z0-9]{2,10}$/', 'Use 2–10 capital letters or digits, e.g. QA.', 'DEPARTMENT_CREATED');
    }

    #[Route('/api/admin/document-types', name: 'api_admin_type_create', methods: ['POST'])]
    #[IsGranted(Permission::ADMIN_TYPES)]
    public function createType(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        return $this->create($user, $request, 'document_type', '/^[A-Z]{2,6}$/', 'Use 2–6 capital letters, e.g. SOP.', 'DOCUMENT_TYPE_CREATED');
    }

    #[Route('/api/admin/departments/{id}', name: 'api_admin_department_update', methods: ['PATCH'])]
    #[IsGranted(Permission::ADMIN_DEPARTMENTS)]
    public function updateDepartment(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        return $this->update($user, $id, $request, 'org_unit', 'DEPARTMENT_UPDATED');
    }

    #[Route('/api/admin/document-types/{id}', name: 'api_admin_type_update', methods: ['PATCH'])]
    #[IsGranted(Permission::ADMIN_TYPES)]
    public function updateType(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        return $this->update($user, $id, $request, 'document_type', 'DOCUMENT_TYPE_UPDATED');
    }

    private function create(PalUser $user, Request $request, string $table, string $codePattern, string $codeHint, string $event): JsonResponse
    {
        $body = JsonBody::decode($request);
        $code = \is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
        $name = \is_string($body['name'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $body['name'])) : '';
        $errors = [];
        if (1 !== preg_match($codePattern, $code)) {
            $errors['code'] = $codeHint;
        }
        if ('' === $name || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name (up to 120 characters).';
        }
        if ([] !== $errors) {
            throw ApiProblem::validation($errors);
        }
        try {
            $id = $this->connection->transactional(function () use ($table, $code, $name, $user, $event): string {
                $id = Row::scalarString($this->connection->fetchOne('INSERT INTO '.$table.' (code, name) VALUES (?, ?) RETURNING id', [$code, $name]));
                $this->audit->record(new AuditEntry($event, $user->auditActor(), $table, $id, ['code' => $code, 'name' => $name]));

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::validation(['code' => 'That code is already used.']);
        }

        return new JsonResponse(['id' => $id], 201);
    }

    private function update(PalUser $user, string $id, Request $request, string $table, string $event): JsonResponse
    {
        $body = JsonBody::decode($request);
        if (!Ids::isUuid($id)) {
            throw new ApiProblem(404, 'not_found', 'Not found.');
        }
        $this->connection->transactional(function () use ($user, $id, $body, $table, $event): void {
            $current = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ? FOR UPDATE', [$id]);
            if (false === $current) {
                throw new ApiProblem(404, 'not_found', 'Not found.');
            }
            $name = \is_string($body['name'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $body['name'])) : Row::string($current, 'name');
            $active = \is_bool($body['isActive'] ?? null) ? $body['isActive'] : Row::bool($current, 'is_active');
            if ('' === $name || mb_strlen($name) > 120) {
                throw ApiProblem::validation(['name' => 'Enter a name (up to 120 characters).']);
            }
            $this->connection->executeStatement('UPDATE '.$table.' SET name = ?, is_active = ?, updated_at = now() WHERE id = ?', [$name, $active ? 'true' : 'false', $id]);
            $this->audit->record(new AuditEntry($event, $user->auditActor(), $table, $id, [
                'name' => ['from' => Row::string($current, 'name'), 'to' => $name],
                'is_active' => ['from' => Row::bool($current, 'is_active'), 'to' => $active],
            ]));
        });

        return new JsonResponse(['ok' => true]);
    }
}
