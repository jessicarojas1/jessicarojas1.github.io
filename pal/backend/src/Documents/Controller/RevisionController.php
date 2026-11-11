<?php

declare(strict_types=1);

namespace App\Documents\Controller;

use App\Documents\DocumentAccess;
use App\Documents\DocumentRepository;
use App\Documents\LifecycleService;
use App\Documents\RevisionComparer;
use App\Documents\RichTextDocument;
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

    /**
     * Saves the editor content of a draft (autosave and explicit save). The body is larger and
     * deeper than other JSON requests, so it gets its own bounds; RichTextDocument enforces
     * the real schema limits.
     */
    #[Route('/api/revisions/{id}/body', name: 'api_revision_body', methods: ['PUT'])]
    public function body(#[CurrentUser] PalUser $user, string $id, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request, 2 * RichTextDocument::MAX_BYTES, 128);

        return new JsonResponse(['ok' => true, 'rowVersion' => $this->lifecycle->updateBody($user, $id, $body)]);
    }

    /**
     * What changed in this revision compared with the revision it was based on. Same access
     * rule as reading the revision's work in progress (authors, assigned approver, publisher
     * of an approved revision, document.read_all); anything else is a 404.
     */
    #[Route('/api/revisions/{id}/compare', name: 'api_revision_compare', methods: ['GET'])]
    public function compare(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $revision = $this->documents->findRevision($id);
        $document = null === $revision ? null : $this->documents->findDocument(Row::string($revision, 'document_id'));
        if (null === $revision || null === $document || !$this->access->canReadWorkInProgress($user, $document, $id)) {
            throw new ApiProblem(404, 'not_found', 'Revision not found.');
        }
        $previousId = Row::nullableString($revision, 'previous_revision_id');
        $previous = null === $previousId ? null : $this->documents->findRevision($previousId);
        if (null === $previous) {
            throw new ApiProblem(404, 'nothing_to_compare', 'This is the first revision, so there is nothing to compare it with.');
        }
        // The earlier revision is disclosed too, so it needs the same right as downloading it:
        // readable as the current version, or as work in progress (e.g. once superseded).
        $visible = $this->documents->visibleRevision(Row::string($document, 'id'));
        $previousIsCurrent = null !== $visible && Row::string($visible, 'id') === $previousId;
        $mayReadPrevious = $previousIsCurrent
            ? $this->access->canReadPublished($user, $document)
            : $this->access->canReadWorkInProgress($user, $document, $previousId);
        if (!$mayReadPrevious) {
            throw new ApiProblem(404, 'not_found', 'Revision not found.');
        }
        $this->auditLog->info('revision_compared', [
            'user_id' => $user->id, 'document_id' => Row::string($document, 'id'), 'document_number' => Row::string($document, 'document_number'),
            'revision_id' => $id, 'previous_revision_id' => $previousId,
        ]);

        return new JsonResponse([
            'before' => ['id' => Row::string($previous, 'id'), 'label' => Row::int($previous, 'major').'.'.Row::int($previous, 'minor')],
            'after' => ['id' => $id, 'label' => Row::int($revision, 'major').'.'.Row::int($revision, 'minor'), 'labelConfirmed' => Row::bool($revision, 'label_confirmed')],
            ...RevisionComparer::compare($this->comparable($previous), $this->comparable($revision)),
        ]);
    }

    /**
     * @param array<string, mixed> $revision
     *
     * @return array{title: string, description: ?string, keywords: list<string>, changeSummary: ?string, file: ?array{sha256: string, filename: string}, body: ?array<string, mixed>}
     */
    private function comparable(array $revision): array
    {
        $file = $this->documents->primaryFile(Row::string($revision, 'id'));

        return [
            'title' => Row::string($revision, 'title'),
            'description' => Row::nullableString($revision, 'description'),
            'keywords' => DocumentRepository::keywords($revision['keywords']),
            'changeSummary' => Row::nullableString($revision, 'change_summary'),
            'file' => null === $file ? null : ['sha256' => Row::string($file, 'sha256'), 'filename' => Row::string($file, 'original_filename')],
            'body' => RichTextDocument::decodeStored(Row::nullableString($revision, 'body_json')),
        ];
    }

    /** Editor heartbeat: claims or renews "who is editing" (soft checkout). */
    #[Route('/api/revisions/{id}/editing', name: 'api_revision_editing', methods: ['POST'])]
    public function editing(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        return new JsonResponse($this->lifecycle->heartbeatEditing($user, $id));
    }

    #[Route('/api/revisions/{id}/editing', name: 'api_revision_editing_stop', methods: ['DELETE'])]
    public function stopEditing(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $this->lifecycle->stopEditing($user, $id);

        return new JsonResponse(['ok' => true]);
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
