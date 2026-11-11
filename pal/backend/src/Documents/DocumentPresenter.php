<?php

declare(strict_types=1);

namespace App\Documents;

use App\Identity\Authorization\Permission;
use App\Identity\User\PalUser;
use App\Storage\BlobStore;
use App\Platform\Database\Row;

/**
 * Builds the document detail view for one user: only what they may see, plus the actions
 * the server will accept from them (the UI renders these; the server re-checks every one).
 */
final class DocumentPresenter
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentAccess $access,
        private readonly GrantService $grants,
        private readonly BlobStore $blobs,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function present(PalUser $user, array $document): array
    {
        $documentId = Row::string($document, 'id');
        $visible = $this->documents->visibleRevision($documentId);
        $open = $this->documents->openRevision($documentId);
        $awaiting = $this->documents->approvedUnreleased($documentId);
        $canReadPublished = null !== $visible && $this->access->canReadPublished($user, $document);
        $isEditor = $this->access->isOwnerOrAuthor($user, $document);
        $canManage = $this->access->canManageAccess($user, $document);
        $canPublish = DocumentAccess::can($user, Permission::PUBLICATION_RELEASE);
        $active = 'ACTIVE' === $document['disposition'];

        $actions = [];
        if ($active && $isEditor && null === $open && null !== $visible && null === $awaiting) {
            $actions[] = 'createRevision';
        }
        if ($canManage) {
            $actions[] = 'manageAccess';
        }

        $openView = null;
        if (null !== $open && $this->access->canReadWorkInProgress($user, $document, Row::string($open, 'id'))) {
            $openView = $this->revision($open, $user, $isEditor && $active);
        }
        $awaitingView = null;
        if (null !== $awaiting && ($canPublish || $this->access->canReadWorkInProgress($user, $document, Row::string($awaiting, 'id')))) {
            $awaitingView = $this->revision($awaiting, $user, false, $canPublish && $active ? ['release'] : []);
        }

        $showHistory = $this->access->canReadWorkInProgress($user, $document);

        return [
            'id' => $documentId,
            'documentNumber' => Row::string($document, 'document_number'),
            'type' => ['code' => Row::string($document, 'type_code'), 'name' => Row::string($document, 'type_name')],
            'department' => ['code' => Row::string($document, 'dept_code'), 'name' => Row::string($document, 'dept_name')],
            'owner' => ['id' => Row::string($document, 'owner_user_id'), 'displayName' => Row::string($document, 'owner_name')],
            'audience' => Row::string($document, 'audience'),
            'disposition' => Row::string($document, 'disposition'),
            'relations' => $this->access->relations($user, $document),
            'current' => null !== $visible && $canReadPublished ? [
                ...$this->revision($visible, $user, false),
                'releasedAt' => Row::string($visible, 'released_at'),
                'effectiveFrom' => Row::string($visible, 'effective_from'),
            ] : null,
            'openRevision' => $openView,
            'awaitingRelease' => $awaitingView,
            'history' => $showHistory ? array_map(fn (array $h): array => [
                'revisionId' => Row::string($h, 'id'),
                'label' => Row::int($h, 'major').'.'.Row::int($h, 'minor'),
                'title' => Row::string($h, 'title'),
                'changeSummary' => Row::nullableString($h, 'change_summary'),
                'releasedAt' => Row::string($h, 'released_at'),
                'releasedBy' => Row::nullableString($h, 'released_by_name'),
                'effectiveFrom' => Row::string($h, 'effective_from'),
                'effectiveTo' => Row::nullableString($h, 'effective_to'),
                'isCurrent' => Row::bool($h, 'is_current'),
            ], $this->documents->releaseHistory($documentId)) : [],
            'grants' => $canManage ? $this->grants->list($documentId) : null,
            'actions' => $actions,
        ];
    }

    /**
     * @param array<string, mixed> $revision
     * @param list<string>         $extraActions
     *
     * @return array<string, mixed>
     */
    private function revision(array $revision, PalUser $user, bool $editable, array $extraActions = []): array
    {
        $revisionId = Row::string($revision, 'id');
        $state = Row::string($revision, 'state');
        $file = $this->documents->primaryFile($revisionId);
        $workflow = $this->documents->latestWorkflow($revisionId);
        $actions = [];
        if ($editable) {
            $actions = match ($state) {
                'DRAFT' => ['edit', 'attachFile', 'submit', 'withdraw'],
                'IN_WORKFLOW' => ['withdraw'],
                'CHANGES_REQUESTED', 'REJECTED' => ['reopen', 'withdraw'],
                default => [],
            };
        }
        if (null !== $workflow && 'OPEN' === $workflow['task_status'] && Row::string($workflow, 'assignee_user_id') === $user->id) {
            $actions[] = 'decide';
        }

        return [
            'id' => $revisionId,
            'state' => $state,
            'label' => Row::int($revision, 'major').'.'.Row::int($revision, 'minor'),
            'labelConfirmed' => Row::bool($revision, 'label_confirmed'),
            'title' => Row::string($revision, 'title'),
            'description' => Row::nullableString($revision, 'description'),
            'keywords' => DocumentRepository::keywords($revision['keywords']),
            'changeSummary' => Row::nullableString($revision, 'change_summary'),
            'rowVersion' => Row::int($revision, 'row_version'),
            'manifestHash' => Row::nullableString($revision, 'content_manifest_hash'),
            'body' => RichTextDocument::decodeStored(Row::nullableString($revision, 'body_json')),
            'editing' => 'DRAFT' === $state ? $this->documents->currentEditor($revisionId) : null,
            'template' => $this->documents->templateOrigin($revisionId),
            'file' => null === $file ? null : [
                'filename' => Row::string($file, 'original_filename'),
                'sizeBytes' => Row::int($file, 'size_bytes'),
                'mime' => Row::string($file, 'detected_mime'),
                'sha256' => Row::string($file, 'sha256'),
                'available' => $this->blobs->exists(Row::string($file, 'sha256')),
            ],
            'workflow' => null === $workflow ? null : [
                'status' => Row::string($workflow, 'status'),
                'taskId' => Row::string($workflow, 'task_id'),
                'approver' => Row::string($workflow, 'assignee_name'),
                'decision' => Row::nullableString($workflow, 'decision'),
                'comment' => Row::nullableString($workflow, 'comment'),
                'decidedAt' => Row::nullableString($workflow, 'decided_at'),
            ],
            'actions' => [...$actions, ...$extraActions],
        ];
    }
}
