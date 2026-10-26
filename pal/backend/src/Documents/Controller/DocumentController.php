<?php

declare(strict_types=1);

namespace App\Documents\Controller;

use App\Documents\DocumentAccess;
use App\Documents\DocumentPresenter;
use App\Documents\DocumentRepository;
use App\Documents\GrantService;
use App\Documents\LibraryQuery;
use App\Documents\LifecycleService;
use App\Documents\ReferenceRepository;
use App\Identity\Authorization\Permission;
use App\Identity\User\PalUser;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DocumentController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentAccess $access,
        private readonly DocumentPresenter $presenter,
        private readonly LifecycleService $lifecycle,
        private readonly LibraryQuery $library,
        private readonly ReferenceRepository $reference,
        private readonly GrantService $grants,
    ) {
    }

    #[Route('/api/reference', name: 'api_reference', methods: ['GET'])]
    public function reference(): JsonResponse
    {
        return new JsonResponse(['documentTypes' => $this->reference->documentTypes(true), 'departments' => $this->reference->departments(true)]);
    }

    #[Route('/api/library', name: 'api_library', methods: ['GET'])]
    public function library(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->library->search(
            $user,
            (string) $request->query->get('q', ''),
            $request->query->get('type'),
            $request->query->get('department'),
            max(1, $request->query->getInt('page', 1)),
        ));
    }

    #[Route('/api/workspace', name: 'api_workspace', methods: ['GET'])]
    public function workspace(#[CurrentUser] PalUser $user): JsonResponse
    {
        return new JsonResponse([
            'documents' => $this->library->workspace($user),
            'tasks' => $this->library->myTasks($user),
            'awaitingRelease' => DocumentAccess::can($user, Permission::PUBLICATION_RELEASE) ? $this->library->awaitingRelease() : null,
        ]);
    }

    #[Route('/api/documents', name: 'api_document_create', methods: ['POST'])]
    public function create(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->lifecycle->createDocument($user, JsonBody::decode($request)), 201);
    }

    #[Route('/api/documents/{id}', name: 'api_document_show', methods: ['GET'])]
    public function show(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $document = $this->documents->findDocument($id);
        if (null === $document || !$this->access->canSeeDocument($user, $document, null !== $this->documents->visibleRevision($id))) {
            throw new ApiProblem(404, 'not_found', 'Document not found.');
        }

        return new JsonResponse($this->presenter->present($user, $document));
    }

    #[Route('/api/documents/{id}/approvers', name: 'api_document_approvers', methods: ['GET'])]
    public function approvers(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $document = $this->documents->findDocument($id);
        if (null === $document || !$this->access->isOwnerOrAuthor($user, $document)) {
            throw new ApiProblem(404, 'not_found', 'Document not found.');
        }

        return new JsonResponse(['approvers' => $this->lifecycle->eligibleApprovers($id, $user->id)]);
    }

    #[Route('/api/documents/{id}/revisions', name: 'api_document_new_revision', methods: ['POST'])]
    public function newRevision(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        return new JsonResponse(['revisionId' => $this->lifecycle->newRevision($user, $id)], 201);
    }

    #[Route('/api/documents/{id}/grants', name: 'api_document_grant_add', methods: ['POST'])]
    public function addGrant(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        return new JsonResponse(['id' => $this->grants->add($user, $id, JsonBody::decode($request))], 201);
    }

    #[Route('/api/documents/{id}/grants/{grantId}', name: 'api_document_grant_revoke', methods: ['DELETE'])]
    public function revokeGrant(#[CurrentUser] PalUser $user, string $id, string $grantId): JsonResponse
    {
        $this->grants->revoke($user, $id, $grantId);

        return new JsonResponse(['ok' => true]);
    }
}
