<?php

declare(strict_types=1);

namespace App\Documents\Controller;

use App\Documents\DocumentAccess;
use App\Documents\DocumentRepository;
use App\Documents\LifecycleService;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use App\Storage\BlobStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RevisionController
{
    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly DocumentRepository $documents,
        private readonly DocumentAccess $access,
        private readonly BlobStore $blobs,
        #[Autowire(service: 'monolog.logger.pal_audit')]
        private readonly LoggerInterface $auditLog,
    ) {
    }

    #[Route('/api/revisions/{id}', name: 'api_revision_update', methods: ['PATCH'])]
    public function update(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'rowVersion' => $this->lifecycle->updateDraft($user, $id, JsonBody::decode($request))]);
    }

    #[Route('/api/revisions/{id}/file', name: 'api_revision_attach', methods: ['PUT'])]
    public function attach(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $this->lifecycle->attachPrimaryFile($user, $id, \is_string($body['uploadId'] ?? null) ? $body['uploadId'] : '');

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/revisions/{id}/submit', name: 'api_revision_submit', methods: ['POST'])]
    public function submit(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $this->lifecycle->submit($user, $id, \is_string($body['approverId'] ?? null) ? $body['approverId'] : '', \is_string($body['comment'] ?? null) ? $body['comment'] : null);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/revisions/{id}/reopen', name: 'api_revision_reopen', methods: ['POST'])]
    public function reopen(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $this->lifecycle->reopen($user, $id);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/revisions/{id}/withdraw', name: 'api_revision_withdraw', methods: ['POST'])]
    public function withdraw(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $this->lifecycle->withdraw($user, $id, \is_string($body['reason'] ?? null) ? $body['reason'] : null);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/revisions/{id}/release', name: 'api_revision_release', methods: ['POST'])]
    public function release(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        return new JsonResponse(['ok' => true, ...$this->lifecycle->release($user, $id)]);
    }

    #[Route('/api/tasks/{id}/decision', name: 'api_task_decide', methods: ['POST'])]
    public function decide(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $this->lifecycle->decide($user, $id, \is_string($body['decision'] ?? null) ? $body['decision'] : '', \is_string($body['comment'] ?? null) ? $body['comment'] : null);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Streams a revision's file after checking the user may read that revision: readers get
     * the current published version; drafts and superseded versions need work-in-progress
     * access. Anything else is a 404. Storage keys are never exposed.
     */
    #[Route('/api/revisions/{id}/file', name: 'api_revision_download', methods: ['GET'])]
    public function download(#[CurrentUser] PalUser $user, string $id): StreamedResponse
    {
        $revision = $this->documents->findRevision($id);
        $document = null === $revision ? null : $this->documents->findDocument(Row::string($revision, 'document_id'));
        $file = null === $revision ? null : $this->documents->primaryFile($id);
        if (null === $revision || null === $document || null === $file) {
            throw new ApiProblem(404, 'not_found', 'File not found.');
        }
        $visible = $this->documents->visibleRevision(Row::string($document, 'id'));
        $isCurrent = null !== $visible && Row::string($visible, 'id') === $id;
        $allowed = $isCurrent
            ? $this->access->canReadPublished($user, $document)
            : $this->access->canReadWorkInProgress($user, $document, $id);
        if (!$allowed) {
            throw new ApiProblem(404, 'not_found', 'File not found.');
        }

        $sha = Row::string($file, 'sha256');
        // Checked here because a failure inside the stream would arrive after the headers.
        if (!$this->blobs->exists($sha)) {
            throw new ApiProblem(410, 'file_unavailable', 'This file is no longer in storage.');
        }
        $filename = Row::string($file, 'original_filename');
        $this->auditLog->info('document_downloaded', [
            'user_id' => $user->id, 'document_id' => Row::string($document, 'id'), 'document_number' => Row::string($document, 'document_number'),
            'revision_id' => $id, 'sha256' => $sha, 'current' => $isCurrent,
        ]);

        $response = new StreamedResponse(function () use ($sha): void {
            $handle = $this->blobs->openRead($sha);
            fpassthru($handle);
            fclose($handle);
        });
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'file';
        $response->headers->set('Content-Type', Row::string($file, 'detected_mime'));
        $response->headers->set('Content-Length', (string) Row::int($file, 'size_bytes'));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('ETag', '"'.$sha.'"');

        return $response;
    }
}
