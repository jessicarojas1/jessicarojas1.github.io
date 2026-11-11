<?php

declare(strict_types=1);

namespace App\Documents\Controller;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Documents\ReferenceRepository;
use App\Documents\RichTextDocument;
use App\Documents\TemplateRepository;
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
 * Document templates (ADR-0009). PAL administrators create and maintain them per document
 * type; authors list the active ones for a type when creating a document. Templates are
 * never deleted, only deactivated, so audit events that name one always resolve.
 */
final class TemplateController
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly ReferenceRepository $reference,
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/api/document-types/{id}/templates', name: 'api_type_templates', methods: ['GET'])]
    #[IsGranted(Permission::DOCUMENT_CREATE)]
    public function forType(string $id): JsonResponse
    {
        return new JsonResponse(['templates' => $this->templates->activeForType($id)]);
    }

    #[Route('/api/admin/templates', name: 'api_admin_templates', methods: ['GET'])]
    #[IsGranted(Permission::ADMIN_TEMPLATES)]
    public function list(): JsonResponse
    {
        return new JsonResponse(['templates' => $this->templates->listAll()]);
    }

    #[Route('/api/admin/templates/{id}', name: 'api_admin_template', methods: ['GET'])]
    #[IsGranted(Permission::ADMIN_TEMPLATES)]
    public function show(string $id): JsonResponse
    {
        $template = $this->templates->find($id);
        if (null === $template) {
            throw new ApiProblem(404, 'not_found', 'Template not found.');
        }

        return new JsonResponse([
            ...$this->templates->summary($template),
            'body' => RichTextDocument::decodeStored(Row::string($template, 'body_json')),
        ]);
    }

    #[Route('/api/admin/templates', name: 'api_admin_template_create', methods: ['POST'])]
    #[IsGranted(Permission::ADMIN_TEMPLATES)]
    public function create(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        $input = JsonBody::decode($request, 2 * RichTextDocument::MAX_BYTES, 128);
        $errors = [];
        $type = $this->reference->findActiveType(\is_string($input['documentTypeId'] ?? null) ? $input['documentTypeId'] : '');
        if (null === $type) {
            $errors['documentTypeId'] = 'Choose an active document type.';
        }
        [$name, $description] = $this->validateText($input, $errors, null);
        $body = $this->validateBody($input, $errors);
        if ([] !== $errors || null === $type || null === $body) {
            throw ApiProblem::validation($errors);
        }

        try {
            $id = $this->connection->transactional(function () use ($user, $type, $name, $description, $body): string {
                $id = Row::scalarString($this->connection->fetchOne(
                    'INSERT INTO document_template (document_type_id, name, description, body_json, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                    [Row::string($type, 'id'), $name, $description, $body['json'], $user->id, $user->id],
                ));
                $this->audit->record(new AuditEntry('TEMPLATE_CREATED', $user->auditActor(), 'document_template', $id, [
                    'document_type' => Row::string($type, 'code'), 'name' => $name, 'description' => $description, 'body_sha256' => $body['sha256'],
                ]));

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::validation(['name' => 'This document type already has a template with that name.']);
        }

        return new JsonResponse(['id' => $id, 'rowVersion' => 1], 201);
    }

    /**
     * Updates any of name, description, body and active flag. The document type is fixed
     * once created. Optimistic concurrency via rowVersion.
     */
    #[Route('/api/admin/templates/{id}', name: 'api_admin_template_update', methods: ['PATCH'])]
    #[IsGranted(Permission::ADMIN_TEMPLATES)]
    public function update(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        $input = JsonBody::decode($request, 2 * RichTextDocument::MAX_BYTES, 128);
        $rowVersion = $input['rowVersion'] ?? null;
        if (!\is_int($rowVersion)) {
            throw ApiProblem::validation(['rowVersion' => 'Reload the page and try again.']);
        }

        try {
            $next = $this->connection->transactional(function () use ($user, $id, $input, $rowVersion): int {
                $current = $this->templates->find($id, true);
                if (null === $current) {
                    throw new ApiProblem(404, 'not_found', 'Template not found.');
                }
                if (Row::int($current, 'row_version') !== $rowVersion) {
                    throw new ApiProblem(409, 'edit_conflict', 'Someone else changed this template. Reload to see their changes.');
                }
                $errors = [];
                [$name, $description] = $this->validateText($input, $errors, $current);
                $body = \array_key_exists('body', $input) ? $this->validateBody($input, $errors) : null;
                $active = \is_bool($input['isActive'] ?? null) ? $input['isActive'] : Row::bool($current, 'is_active');
                if ([] !== $errors) {
                    throw ApiProblem::validation($errors);
                }
                $bodyJson = $body['json'] ?? Row::string($current, 'body_json');

                $changes = [];
                foreach (['name' => [Row::string($current, 'name'), $name], 'description' => [Row::nullableString($current, 'description'), $description], 'is_active' => [Row::bool($current, 'is_active'), $active]] as $field => [$from, $to]) {
                    if ($from !== $to) {
                        $changes[$field] = ['from' => $from, 'to' => $to];
                    }
                }
                if ($bodyJson !== Row::string($current, 'body_json')) {
                    $changes['body'] = ['from_sha256' => hash('sha256', Row::string($current, 'body_json')), 'to_sha256' => hash('sha256', $bodyJson)];
                }
                if ([] === $changes) {
                    return $rowVersion;
                }
                $this->connection->executeStatement(
                    'UPDATE document_template SET name = ?, description = ?, body_json = ?, is_active = ?, updated_by = ?, updated_at = now(), row_version = row_version + 1 WHERE id = ?',
                    [$name, $description, $bodyJson, $active ? 'true' : 'false', $user->id, $id],
                );
                $this->audit->record(new AuditEntry('TEMPLATE_UPDATED', $user->auditActor(), 'document_template', $id, [
                    'document_type' => Row::string($current, 'type_code'), ...$changes,
                ]));

                return $rowVersion + 1;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::validation(['name' => 'This document type already has a template with that name.']);
        }

        return new JsonResponse(['ok' => true, 'rowVersion' => $next]);
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, string>     $errors
     * @param array<string, mixed>|null $current
     *
     * @return array{0: string, 1: string|null}
     */
    private function validateText(array $input, array &$errors, ?array $current): array
    {
        $name = \is_string($input['name'] ?? null)
            ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $input['name']))
            : (null === $current ? '' : Row::string($current, 'name'));
        if ('' === $name || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name (up to 120 characters).';
        }
        if (\array_key_exists('description', $input)) {
            $description = \is_string($input['description']) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input['description'])) : '';
        } else {
            $description = null === $current ? '' : (Row::nullableString($current, 'description') ?? '');
        }
        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'Keep the description under 1,000 characters.';
        }

        return [$name, '' === $description ? null : $description];
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return array{json: string, text: string, sha256: string}|null
     */
    private function validateBody(array $input, array &$errors): ?array
    {
        try {
            $body = RichTextDocument::normalize($input['body'] ?? null);
        } catch (ApiProblem $problem) {
            $errors['body'] = $problem->fieldErrors['body'] ?? 'The template content is not valid.';

            return null;
        }
        if (null === $body) {
            $errors['body'] = 'Add some content to the template, such as its section headings.';
        }

        return $body;
    }
}
