<?php

declare(strict_types=1);

namespace App\Documents;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\Permission;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use App\Storage\BlobStore;
use App\Storage\UploadService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Every state change of a document and its revisions. Each operation runs in one
 * transaction that locks the document row (serializing changes per document), re-checks
 * permission and state on the server, and records its audit event in the same transaction.
 */
final class LifecycleService
{
    private const OPEN_STATES = ['DRAFT', 'IN_WORKFLOW', 'CHANGES_REQUESTED', 'REJECTED'];

    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentRepository $documents,
        private readonly DocumentAccess $access,
        private readonly ReferenceRepository $reference,
        private readonly TemplateRepository $templates,
        private readonly ContentImages $images,
        private readonly UploadService $uploads,
        private readonly BlobStore $blobs,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{id: string, documentNumber: string, revisionId: string}
     */
    public function createDocument(PalUser $user, array $input): array
    {
        if (!DocumentAccess::can($user, Permission::DOCUMENT_CREATE)) {
            throw new ApiProblem(403, 'forbidden', 'You do not have permission to create documents.');
        }
        $errors = [];
        $type = $this->reference->findActiveType(\is_string($input['typeId'] ?? null) ? $input['typeId'] : '');
        $department = $this->reference->findActiveDepartment(\is_string($input['departmentId'] ?? null) ? $input['departmentId'] : '');
        if (null === $type) {
            $errors['typeId'] = 'Choose a document type.';
        }
        if (null === $department) {
            $errors['departmentId'] = 'Choose a department.';
        }
        $content = $this->validateContent($input, $errors);
        $restricted = true === ($input['restricted'] ?? false);
        $template = null;
        $body = null;
        $templateId = $input['templateId'] ?? null;
        if (null !== $templateId && '' !== $templateId) {
            $template = null === $type ? null : $this->templates->findActiveForType(\is_string($templateId) ? $templateId : '', Row::string($type, 'id'));
            if (null === $template) {
                $errors['templateId'] = 'Choose one of the templates for this document type, or none.';
            } else {
                $body = RichTextDocument::normalize(RichTextDocument::decodeStored(Row::string($template, 'body_json')), 'templateId');
            }
        }
        $upload = null;
        if (isset($input['uploadId'])) {
            $upload = $this->uploads->claim(\is_string($input['uploadId']) ? $input['uploadId'] : '', $user);
        }
        if ([] !== $errors || null === $type || null === $department) {
            throw ApiProblem::validation($errors);
        }

        return $this->connection->transactional(function () use ($user, $type, $department, $content, $restricted, $upload, $template, $body): array {
            $number = $this->allocateNumber(Row::string($type, 'id'), Row::string($type, 'code'), Row::string($department, 'id'), Row::string($department, 'code'));
            $documentId = Row::scalarString($this->connection->fetchOne(
                'INSERT INTO document (document_number, document_type_id, org_unit_id, owner_user_id, audience, created_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$number, Row::string($type, 'id'), Row::string($department, 'id'), $user->id, $restricted ? 'RESTRICTED' : 'ALL_EMPLOYEES', $user->id],
            ));
            $revisionId = $this->insertDraft($documentId, 1, $content, $user, null, $body);
            // Images come with the template version (already vetted when the template was saved).
            $this->images->syncRevision($revisionId, $body['images'] ?? []);
            if (null !== $template) {
                $this->connection->executeStatement(
                    'UPDATE revision SET template_id = ?, template_version = ? WHERE id = ?',
                    [Row::string($template, 'id'), Row::int($template, 'version'), $revisionId],
                );
            }
            if (null !== $upload) {
                $this->setPrimaryFile($revisionId, $upload['sha256'], $upload['filename']);
            }
            $actor = $this->actor($user);
            $this->audit->record(new AuditEntry('DOCUMENT_CREATED', $actor, 'document', $documentId, [
                'document_number' => $number, 'type' => Row::string($type, 'code'), 'department' => Row::string($department, 'code'),
                'audience' => $restricted ? 'RESTRICTED' : 'ALL_EMPLOYEES', 'title' => $content['title'],
            ]));
            $created = ['document_id' => $documentId, 'proposed_label' => '1.0'];
            if (null !== $template) {
                $created['template_id'] = Row::string($template, 'id');
                $created['template_name'] = Row::string($template, 'name');
                $created['template_version'] = Row::int($template, 'version');
                $created['body_sha256'] = $body['sha256'] ?? null;
            }
            $this->audit->record(new AuditEntry('REVISION_CREATED', $actor, 'revision', $revisionId, $created, toState: 'DRAFT'));

            return ['id' => $documentId, 'documentNumber' => $number, 'revisionId' => $revisionId];
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateDraft(PalUser $user, string $revisionId, array $input): int
    {
        $errors = [];
        $content = $this->validateContent($input, $errors);
        $rowVersion = $input['rowVersion'] ?? null;
        if (!\is_int($rowVersion)) {
            $errors['rowVersion'] = 'Reload the page and try again.';
        }
        if ([] !== $errors || !\is_int($rowVersion)) {
            throw ApiProblem::validation($errors);
        }

        return $this->connection->transactional(function () use ($user, $revisionId, $content, $rowVersion): int {
            [$document, $revision] = $this->lockForDraftChange($user, $revisionId);
            $updated = $this->connection->executeStatement(
                'UPDATE revision SET title = ?, description = ?, keywords = ?, change_summary = ?, updated_at = now(), row_version = row_version + 1 WHERE id = ? AND row_version = ?',
                [$content['title'], $content['description'], DocumentRepository::toPgArray($content['keywords']), $content['changeSummary'], $revisionId, $rowVersion],
            );
            if (1 !== $updated) {
                throw new ApiProblem(409, 'edit_conflict', 'Someone else changed this draft. Reload to see their changes.');
            }
            $this->audit->record(new AuditEntry('REVISION_EDITED', $this->actor($user), 'revision', $revisionId, [
                'document_id' => Row::string($document, 'id'),
                ...$this->contentDiff($revision, $content),
            ]));

            return $rowVersion + 1;
        });
    }

    /**
     * Replaces the editor content of a draft. The content is validated and canonicalized
     * by RichTextDocument; an empty editor clears the body. Saving identical content is a
     * no-op (no version bump, no audit event), so autosave never inflates the trail.
     *
     * @param array<string, mixed> $input {body: ProseMirror JSON|null, rowVersion: int}
     */
    public function updateBody(PalUser $user, string $revisionId, array $input): int
    {
        $rowVersion = $input['rowVersion'] ?? null;
        if (!\is_int($rowVersion)) {
            throw ApiProblem::validation(['rowVersion' => 'Reload the page and try again.']);
        }
        return $this->connection->transactional(function () use ($user, $revisionId, $input, $rowVersion): int {
            // Access and state first, so only an editor of this draft makes PAL parse the body.
            [$document, $revision] = $this->lockForDraftChange($user, $revisionId);
            $body = RichTextDocument::normalize($input['body'] ?? null);
            $this->images->assertMayUse($user, $body['images'] ?? [], $this->images->ofRevision($revisionId));
            if (Row::int($revision, 'row_version') !== $rowVersion) {
                throw new ApiProblem(409, 'edit_conflict', 'Someone else changed this draft. Reload to see their changes.');
            }
            $before = Row::nullableString($revision, 'body_json');
            if ($before === ($body['json'] ?? null)) {
                return $rowVersion;
            }
            $this->connection->executeStatement(
                'UPDATE revision SET body_json = ?, body_text = ?, updated_at = now(), row_version = row_version + 1 WHERE id = ? AND row_version = ?',
                [$body['json'] ?? null, $body['text'] ?? null, $revisionId, $rowVersion],
            );
            $this->images->syncRevision($revisionId, $body['images'] ?? []);
            $this->audit->record(new AuditEntry('REVISION_EDITED', $this->actor($user), 'revision', $revisionId, [
                'document_id' => Row::string($document, 'id'),
                'body' => [
                    'from_sha256' => null === $before ? null : hash('sha256', $before),
                    'to_sha256' => $body['sha256'] ?? null,
                    'characters' => null === $body ? 0 : mb_strlen($body['text']),
                ],
            ]));

            return $rowVersion + 1;
        });
    }

    /**
     * The editor's heartbeat (soft checkout, ADR-0009). Claims the draft for this user unless
     * someone else holds a fresh claim, in which case nothing changes and that person is
     * returned so the UI can warn. Advisory only: saves stay guarded by row_version, so a
     * second editor is never silently overwritten. A new claim (not a renewal) is audited.
     *
     * @return array{holder: array{userId: string, displayName: string, since: string}|null, mine: bool}
     */
    public function heartbeatEditing(PalUser $user, string $revisionId): array
    {
        return $this->connection->transactional(function () use ($user, $revisionId): array {
            [$document] = $this->lockForDraftChange($user, $revisionId);
            $current = $this->connection->fetchAssociative(
                'SELECT e.user_id, e.started_at, u.display_name, e.heartbeat_at > now() - make_interval(secs => ?) AS fresh
                 FROM revision_editing e JOIN app_user u ON u.id = e.user_id WHERE e.revision_id = ? FOR UPDATE OF e',
                [DocumentRepository::EDITING_WINDOW_SECONDS, $revisionId],
            );
            if (false !== $current && Row::bool($current, 'fresh') && Row::string($current, 'user_id') !== $user->id) {
                return ['holder' => [
                    'userId' => Row::string($current, 'user_id'),
                    'displayName' => Row::string($current, 'display_name'),
                    'since' => Row::string($current, 'started_at'),
                ], 'mine' => false];
            }
            $renewal = false !== $current && Row::bool($current, 'fresh');
            $since = Row::scalarString($this->connection->fetchOne(<<<'SQL'
                INSERT INTO revision_editing (revision_id, user_id) VALUES (?, ?)
                ON CONFLICT (revision_id) DO UPDATE SET
                    user_id = EXCLUDED.user_id,
                    started_at = CASE WHEN ?::boolean THEN revision_editing.started_at ELSE now() END,
                    heartbeat_at = now()
                RETURNING started_at
                SQL, [$revisionId, $user->id, $renewal ? 'true' : 'false']));
            // One audit event per editing session: re-claiming after leaving and coming back
            // within 15 minutes is the same session, so a claim/release loop cannot flood the trail.
            $recentlyStarted = false !== $this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM audit_event
                WHERE subject_type = 'revision' AND subject_id = ? AND actor_user_id = ?
                  AND event_type = 'REVISION_EDITING_STARTED' AND occurred_at > now() - interval '15 minutes'
                LIMIT 1
                SQL, [$revisionId, $user->id]);
            if (!$renewal && !$recentlyStarted) {
                $this->audit->record(new AuditEntry('REVISION_EDITING_STARTED', $this->actor($user), 'revision', $revisionId, [
                    'document_id' => Row::string($document, 'id'),
                ]));
            }

            return ['holder' => ['userId' => $user->id, 'displayName' => $user->displayName, 'since' => $since], 'mine' => true];
        });
    }

    /**
     * Gives up this user's editing claim (leaving the page). Only ever removes the caller's
     * own claim, so it needs no further authorization; harmless when there is none.
     */
    public function stopEditing(PalUser $user, string $revisionId): void
    {
        if (Ids::isUuid($revisionId)) {
            $this->connection->executeStatement('DELETE FROM revision_editing WHERE revision_id = ? AND user_id = ?', [$revisionId, $user->id]);
        }
    }

    /**
     * Changed draft fields as {field: {from, to}}, so the audit trail shows every edit.
     *
     * @param array<string, mixed>                                                                         $revision
     * @param array{title: string, description: ?string, keywords: list<string>, changeSummary: ?string} $content
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function contentDiff(array $revision, array $content): array
    {
        $before = [
            'title' => Row::string($revision, 'title'),
            'description' => Row::nullableString($revision, 'description'),
            'keywords' => DocumentRepository::keywords($revision['keywords']),
            'change_summary' => Row::nullableString($revision, 'change_summary'),
        ];
        $after = ['title' => $content['title'], 'description' => $content['description'], 'keywords' => $content['keywords'], 'change_summary' => $content['changeSummary']];
        $diff = [];
        foreach ($before as $field => $value) {
            if ($value !== $after[$field]) {
                $diff[$field] = ['from' => $value, 'to' => $after[$field]];
            }
        }

        return $diff;
    }

    public function attachPrimaryFile(PalUser $user, string $revisionId, string $uploadId): void
    {
        $upload = $this->uploads->claim($uploadId, $user);
        $this->connection->transactional(function () use ($user, $revisionId, $upload): void {
            [$document] = $this->lockForDraftChange($user, $revisionId);
            $this->setPrimaryFile($revisionId, $upload['sha256'], $upload['filename']);
            $this->connection->executeStatement('UPDATE revision SET updated_at = now(), row_version = row_version + 1 WHERE id = ?', [$revisionId]);
            $this->audit->record(new AuditEntry('REVISION_FILE_ATTACHED', $this->actor($user), 'revision', $revisionId, [
                'document_id' => Row::string($document, 'id'), 'sha256' => $upload['sha256'], 'filename' => $upload['filename'],
            ]));
        });
    }

    public function submit(PalUser $user, string $revisionId, string $approverId, ?string $comment): void
    {
        $this->connection->transactional(function () use ($user, $revisionId, $approverId, $comment): void {
            [$document, $revision] = $this->lockForDraftChange($user, $revisionId);
            $documentId = Row::string($document, 'id');
            $files = $this->documents->manifestFiles($revisionId);
            if ([] === $files && null === Row::nullableString($revision, 'body_json')) {
                throw ApiProblem::validation(['file' => 'Write the document content or attach its file before submitting.']);
            }
            if (!Ids::isUuid($approverId) || $approverId === $user->id || $approverId === Row::string($revision, 'created_by')) {
                throw ApiProblem::validation(['approverId' => 'Choose an approver other than the author or yourself.']);
            }
            if ($approverId === Row::string($document, 'owner_user_id') || $this->contributedTo($revisionId, $approverId)) {
                throw ApiProblem::validation(['approverId' => 'The owner, and anyone who edited this revision, cannot approve it.']);
            }
            if (!$this->isEligibleApprover($documentId, $approverId)) {
                throw ApiProblem::validation(['approverId' => 'That person is not an approver for this document. The owner can add approvers under Access.']);
            }

            $hash = $this->manifestHash($revision, $files);
            $this->connection->executeStatement(
                "UPDATE revision SET state = 'IN_WORKFLOW', content_manifest_hash = ?, submitted_at = now(), updated_at = now(), row_version = row_version + 1 WHERE id = ?",
                [$hash, $revisionId],
            );
            // Nobody is editing a submitted draft any more.
            $this->connection->executeStatement('DELETE FROM revision_editing WHERE revision_id = ?', [$revisionId]);
            $instanceId = Row::scalarString($this->connection->fetchOne(
                'INSERT INTO workflow_instance (revision_id, bound_manifest_hash, initiated_by) VALUES (?, ?, ?) RETURNING id',
                [$revisionId, $hash, $user->id],
            ));
            $taskId = Row::scalarString($this->connection->fetchOne(
                'INSERT INTO workflow_task (instance_id, assignee_user_id) VALUES (?, ?) RETURNING id',
                [$instanceId, $approverId],
            ));
            $this->audit->record(new AuditEntry('REVISION_SUBMITTED', $this->actor($user), 'revision', $revisionId, [
                'document_id' => $documentId, 'workflow_instance_id' => $instanceId, 'task_id' => $taskId,
                'approver_user_id' => $approverId, 'manifest_hash' => $hash,
            ], $this->cleanComment($comment), 'DRAFT', 'IN_WORKFLOW'));
        });
    }

    public function decide(PalUser $user, string $taskId, string $decision, ?string $comment): void
    {
        if (!\in_array($decision, ['APPROVE', 'REJECT', 'REQUEST_CHANGES'], true)) {
            throw ApiProblem::validation(['decision' => 'Choose approve, reject or request changes.']);
        }
        $comment = $this->cleanComment($comment);
        if ('APPROVE' !== $decision && null === $comment) {
            throw ApiProblem::validation(['comment' => 'Explain what needs to change, so the author can act on it.']);
        }

        $this->connection->transactional(function () use ($user, $taskId, $decision, $comment): void {
            $task = Ids::isUuid($taskId) ? $this->connection->fetchAssociative(<<<'SQL'
                SELECT t.*, i.revision_id, i.status AS instance_status, i.bound_manifest_hash, i.initiated_by, r.document_id
                FROM workflow_task t JOIN workflow_instance i ON i.id = t.instance_id JOIN revision r ON r.id = i.revision_id
                WHERE t.id = ?
                SQL, [$taskId]) : false;
            if (false === $task || Row::string($task, 'assignee_user_id') !== $user->id) {
                throw new ApiProblem(404, 'not_found', 'Task not found.');
            }
            $document = $this->documents->findDocument(Row::string($task, 'document_id'), true);
            $revision = $this->documents->findRevision(Row::string($task, 'revision_id'), true);
            $this->connection->executeStatement('SELECT 1 FROM workflow_instance WHERE id = ? FOR UPDATE', [Row::string($task, 'instance_id')]);
            if (null === $document || null === $revision || 'OPEN' !== $task['status'] || 'ACTIVE' !== $task['instance_status'] || 'IN_WORKFLOW' !== $revision['state']) {
                throw new ApiProblem(409, 'task_closed', 'This task has already been completed or cancelled.');
            }
            // Separation of duties is re-checked at decision time; roles may have changed since submission.
            if ($user->id === Row::string($revision, 'created_by') || $user->id === Row::string($task, 'initiated_by')
                || $user->id === Row::string($document, 'owner_user_id') || $this->contributedTo(Row::string($revision, 'id'), $user->id)) {
                throw new ApiProblem(403, 'separation_of_duties', 'You cannot approve a revision you own, wrote, edited or submitted.');
            }
            if (!$this->isEligibleApprover(Row::string($document, 'id'), $user->id)) {
                throw new ApiProblem(403, 'approver_revoked', 'You are no longer an approver for this document.');
            }
            $currentHash = $this->manifestHash($revision, $this->documents->manifestFiles(Row::string($revision, 'id')));
            $boundHash = Row::string($task, 'bound_manifest_hash');
            if (!hash_equals($boundHash, $currentHash)) {
                throw new ApiProblem(409, 'content_changed', 'The revision content no longer matches what was submitted. It must be resubmitted.');
            }

            try {
                $this->connection->insert('approval_decision', [
                    'task_id' => $taskId,
                    'revision_id' => Row::string($revision, 'id'),
                    'actor_user_id' => $user->id,
                    'actor_snapshot' => json_encode($this->actor($user)->snapshot, \JSON_THROW_ON_ERROR),
                    'decision' => $decision,
                    'comment' => $comment,
                    'manifest_hash_at_decision' => $boundHash,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ApiProblem(409, 'task_closed', 'This task has already been decided.');
            }
            $this->connection->executeStatement("UPDATE workflow_task SET status = 'DONE', completed_at = now() WHERE id = ?", [$taskId]);
            [$instanceStatus, $revisionState, $event] = match ($decision) {
                'APPROVE' => ['APPROVED', 'APPROVED', 'REVISION_APPROVED'],
                'REJECT' => ['REJECTED', 'REJECTED', 'REVISION_REJECTED'],
                default => ['CHANGES_REQUESTED', 'CHANGES_REQUESTED', 'REVISION_CHANGES_REQUESTED'],
            };
            $this->connection->executeStatement('UPDATE workflow_instance SET status = ?, completed_at = now() WHERE id = ?', [$instanceStatus, Row::string($task, 'instance_id')]);
            $changes = ['document_id' => Row::string($document, 'id'), 'task_id' => $taskId, 'manifest_hash' => $boundHash];
            if ('APPROVE' === $decision) {
                $major = $this->documents->nextMajor(Row::string($document, 'id'));
                $this->connection->executeStatement(
                    "UPDATE revision SET state = 'APPROVED', major = ?, minor = 0, label_confirmed = true, approved_at = now(), updated_at = now(), row_version = row_version + 1 WHERE id = ?",
                    [$major, Row::string($revision, 'id')],
                );
                $changes['label'] = $major.'.0';
            } else {
                $this->connection->executeStatement(
                    'UPDATE revision SET state = ?, updated_at = now(), row_version = row_version + 1 WHERE id = ?',
                    [$revisionState, Row::string($revision, 'id')],
                );
            }
            $this->audit->record(new AuditEntry($event, $this->actor($user), 'revision', Row::string($revision, 'id'), $changes, $comment, 'IN_WORKFLOW', $revisionState));
        });
    }

    /**
     * Returns a revision that was rejected or sent back to an editable draft.
     */
    public function reopen(PalUser $user, string $revisionId): void
    {
        $this->connection->transactional(function () use ($user, $revisionId): void {
            [$document, $revision] = $this->lockRevision($user, $revisionId, ['CHANGES_REQUESTED', 'REJECTED']);
            $this->connection->executeStatement(
                "UPDATE revision SET state = 'DRAFT', content_manifest_hash = NULL, updated_at = now(), row_version = row_version + 1 WHERE id = ?",
                [$revisionId],
            );
            $this->audit->record(new AuditEntry('REVISION_REOPENED', $this->actor($user), 'revision', $revisionId, ['document_id' => Row::string($document, 'id')], fromState: Row::string($revision, 'state'), toState: 'DRAFT'));
        });
    }

    public function withdraw(PalUser $user, string $revisionId, ?string $reason): void
    {
        $reason = $this->cleanComment($reason);
        if (null === $reason) {
            throw ApiProblem::validation(['reason' => 'Say why this revision is being withdrawn.']);
        }
        $this->connection->transactional(function () use ($user, $revisionId, $reason): void {
            [$document, $revision] = $this->lockRevision($user, $revisionId, self::OPEN_STATES);
            $this->connection->executeStatement(<<<'SQL'
                UPDATE workflow_task SET status = 'CANCELLED', completed_at = now()
                WHERE status = 'OPEN' AND instance_id IN (SELECT id FROM workflow_instance WHERE revision_id = ? AND status = 'ACTIVE')
                SQL, [$revisionId]);
            $this->connection->executeStatement("UPDATE workflow_instance SET status = 'CANCELLED', completed_at = now() WHERE revision_id = ? AND status = 'ACTIVE'", [$revisionId]);
            $this->connection->executeStatement("UPDATE revision SET state = 'WITHDRAWN', updated_at = now(), row_version = row_version + 1 WHERE id = ?", [$revisionId]);
            $this->connection->executeStatement('DELETE FROM revision_editing WHERE revision_id = ?', [$revisionId]);
            $this->audit->record(new AuditEntry('REVISION_WITHDRAWN', $this->actor($user), 'revision', $revisionId, ['document_id' => Row::string($document, 'id')], $reason, Row::string($revision, 'state'), 'WITHDRAWN'));
        });
    }

    /**
     * Starts the next revision as a copy of the currently published one. The published
     * revision stays in force until the new one is released.
     */
    public function newRevision(PalUser $user, string $documentId): string
    {
        return $this->connection->transactional(function () use ($user, $documentId): string {
            $document = $this->documents->findDocument($documentId, true);
            if (null === $document || !$this->access->isOwnerOrAuthor($user, $document)) {
                throw new ApiProblem(404, 'not_found', 'Document not found.');
            }
            if ('ACTIVE' !== $document['disposition']) {
                throw new ApiProblem(409, 'document_not_active', 'This document is no longer active.');
            }
            if (null !== $this->documents->openRevision($documentId)) {
                throw new ApiProblem(409, 'draft_exists', 'This document already has a revision in progress.');
            }
            $published = $this->documents->visibleRevision($documentId);
            if (null === $published) {
                throw new ApiProblem(409, 'not_published', 'Only published documents can be revised.');
            }
            $content = [
                'title' => Row::string($published, 'title'),
                'description' => Row::nullableString($published, 'description'),
                'keywords' => DocumentRepository::keywords($published['keywords']),
                'changeSummary' => null,
            ];
            $major = $this->documents->nextMajor($documentId);
            $publishedBody = Row::nullableString($published, 'body_json');
            $body = null === $publishedBody ? null : ['json' => $publishedBody, 'text' => Row::string($published, 'body_text')];
            $revisionId = $this->insertDraft($documentId, $major, $content, $user, Row::string($published, 'id'), $body);
            $this->images->syncRevision($revisionId, RichTextDocument::imagesIn($publishedBody));
            $file = $this->documents->primaryFile(Row::string($published, 'id'));
            if (null !== $file) {
                $this->setPrimaryFile($revisionId, Row::string($file, 'sha256'), Row::string($file, 'original_filename'));
            }
            $this->audit->record(new AuditEntry('REVISION_CREATED', $this->actor($user), 'revision', $revisionId, [
                'document_id' => $documentId, 'proposed_label' => $major.'.0', 'based_on_revision_id' => Row::string($published, 'id'),
            ], toState: 'DRAFT'));

            return $revisionId;
        });
    }

    /**
     * Releases an approved revision immediately, superseding the current one. Idempotent:
     * releasing an already released revision changes nothing.
     *
     * @return array{publicationId: string, alreadyReleased: bool}
     */
    public function release(PalUser $user, string $revisionId): array
    {
        if (!DocumentAccess::can($user, Permission::PUBLICATION_RELEASE)) {
            throw new ApiProblem(403, 'forbidden', 'Only publishers can release documents.');
        }

        return $this->connection->transactional(function () use ($user, $revisionId): array {
            $revision = $this->documents->findRevision($revisionId);
            $document = null === $revision ? null : $this->documents->findDocument(Row::string($revision, 'document_id'), true);
            if (null === $revision || null === $document) {
                throw new ApiProblem(404, 'not_found', 'Revision not found.');
            }
            $existing = $this->connection->fetchOne("SELECT id FROM publication WHERE revision_id = ? AND status = 'RELEASED'", [$revisionId]);
            if (false !== $existing) {
                return ['publicationId' => Row::scalarString($existing), 'alreadyReleased' => true];
            }
            if ('APPROVED' !== $revision['state']) {
                throw new ApiProblem(409, 'not_approved', 'Only approved revisions can be released.');
            }
            if ('ACTIVE' !== $document['disposition']) {
                throw new ApiProblem(409, 'document_not_active', 'This document is no longer active.');
            }
            $this->verifyApprovalIntegrity($revision);

            $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
            $superseded = Row::strings($this->connection->fetchFirstColumn(<<<'SQL'
                UPDATE publication
                SET visible_period = tstzrange(lower(visible_period), :now::timestamptz),
                    effective_period = tstzrange(lower(effective_period), :now::timestamptz)
                WHERE document_id = :doc AND status = 'RELEASED' AND upper_inf(effective_period)
                RETURNING revision_id
                SQL, ['now' => $now, 'doc' => Row::string($document, 'id')]));
            $publicationId = Row::scalarString($this->connection->fetchOne(<<<'SQL'
                INSERT INTO publication (document_id, revision_id, status, visible_period, effective_period, released_by, released_at)
                VALUES (:doc, :rev, 'RELEASED', tstzrange(:now::timestamptz, NULL), tstzrange(:now::timestamptz, NULL), :user, :now::timestamptz)
                RETURNING id
                SQL, ['doc' => Row::string($document, 'id'), 'rev' => $revisionId, 'now' => $now, 'user' => $user->id]));

            $actor = $this->actor($user);
            $label = Row::int($revision, 'major').'.'.Row::int($revision, 'minor');
            $this->audit->record(new AuditEntry('REVISION_RELEASED', $actor, 'revision', $revisionId, [
                'document_id' => Row::string($document, 'id'), 'document_number' => Row::string($document, 'document_number'),
                'label' => $label, 'publication_id' => $publicationId, 'manifest_hash' => Row::string($revision, 'content_manifest_hash'),
                'superseded_revision_ids' => $superseded,
            ], toState: 'PUBLISHED'));
            foreach ($superseded as $oldRevisionId) {
                $this->audit->record(new AuditEntry('REVISION_SUPERSEDED', $actor, 'revision', $oldRevisionId, [
                    'document_id' => Row::string($document, 'id'), 'superseded_by_revision_id' => $revisionId,
                ], fromState: 'PUBLISHED', toState: 'SUPERSEDED'));
            }

            return ['publicationId' => $publicationId, 'alreadyReleased' => false];
        });
    }

    /**
     * Release proceeds only if an APPROVE decision exists for exactly this content: decision
     * hash = revision hash = hash recomputed from current metadata and files, and every
     * stored file's bytes still hash to their recorded SHA-256.
     *
     * @param array<string, mixed> $revision
     */
    private function verifyApprovalIntegrity(array $revision): void
    {
        $revisionId = Row::string($revision, 'id');
        $approvedHash = $this->connection->fetchOne("SELECT manifest_hash_at_decision FROM approval_decision WHERE revision_id = ? AND decision = 'APPROVE' ORDER BY decided_at DESC LIMIT 1", [$revisionId]);
        $files = $this->documents->manifestFiles($revisionId);
        $recomputed = $this->manifestHash($revision, $files);
        $recorded = Row::nullableString($revision, 'content_manifest_hash');
        if (!\is_string($approvedHash) || null === $recorded || !hash_equals($approvedHash, $recorded) || !hash_equals($recorded, $recomputed)) {
            throw new ApiProblem(409, 'approval_integrity_failed', 'The approved content could not be verified, so the revision was not released.');
        }
        // Images in the content are covered by the body hash (by SHA-256); their bytes must
        // still match too.
        foreach ($this->images->ofRevision($revisionId) as $imageSha) {
            $files[] = ['role' => 'IMAGE', 'sha256' => $imageSha, 'filename' => $imageSha];
        }
        foreach ($files as $file) {
            if (!$this->blobs->exists($file['sha256'])) {
                throw new ApiProblem(409, 'approval_integrity_failed', 'A stored file is missing, so the revision was not released.');
            }
            $handle = $this->blobs->openRead($file['sha256']);
            $context = hash_init('sha256');
            hash_update_stream($context, $handle);
            fclose($handle);
            if (!hash_equals($file['sha256'], hash_final($context))) {
                throw new ApiProblem(409, 'approval_integrity_failed', 'A stored file no longer matches the approved content, so the revision was not released.');
            }
        }
    }

    /**
     * The manifest hash of a revision row as stored now: metadata, files and, when present,
     * the SHA-256 of the exact stored body bytes.
     *
     * @param array<string, mixed>                                        $revision
     * @param list<array{role: string, sha256: string, filename: string}> $files
     */
    private function manifestHash(array $revision, array $files): string
    {
        $body = Row::nullableString($revision, 'body_json');

        return ManifestHasher::hash(
            Row::string($revision, 'title'),
            Row::nullableString($revision, 'description'),
            DocumentRepository::keywords($revision['keywords']),
            Row::nullableString($revision, 'change_summary'),
            $files,
            null === $body ? null : hash('sha256', $body),
        );
    }

    /**
     * People who may be chosen as approver: active users with an open APPROVER grant,
     * directly or through a current group membership, excluding the given author.
     *
     * @return list<array{id: string, displayName: string, email: string|null}>
     */
    public function eligibleApprovers(string $documentId, string $excludeUserId): array
    {
        return array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'displayName' => Row::string($r, 'display_name'),
            'email' => Row::nullableString($r, 'email'),
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT u.id, u.display_name, u.email FROM app_user u
            WHERE u.is_active AND u.id <> :exclude
              AND u.id <> (SELECT d.owner_user_id FROM document d WHERE d.id = :doc)
              AND NOT EXISTS (
                SELECT 1 FROM revision r WHERE r.document_id = :doc AND r.state = 'DRAFT'
                  AND (r.created_by = u.id OR EXISTS (
                      SELECT 1 FROM audit_event a WHERE a.actor_user_id = u.id AND a.subject_type = 'revision'
                        AND a.subject_id = r.id::text AND a.event_type IN ('REVISION_CREATED', 'REVISION_EDITED', 'REVISION_FILE_ATTACHED'))))
              AND EXISTS (
                SELECT 1 FROM document_grant g WHERE g.document_id = :doc AND g.role = 'APPROVER' AND g.valid_to IS NULL
                  AND (g.user_id = u.id OR g.group_id IN (
                      SELECT gm.group_id FROM group_membership gm WHERE gm.user_id = u.id AND gm.valid_to IS NULL)))
            ORDER BY u.display_name
            LIMIT 200
            SQL, ['doc' => $documentId, 'exclude' => $excludeUserId]));
    }

    /**
     * Whether the user created, edited or attached files to this revision (read from the audit
     * trail, which records every content change). Such people may not approve it.
     */
    private function contributedTo(string $revisionId, string $userId): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM audit_event
            WHERE actor_user_id = :user AND subject_type = 'revision' AND subject_id = :revision
              AND event_type IN ('REVISION_CREATED', 'REVISION_EDITED', 'REVISION_FILE_ATTACHED')
            LIMIT 1
            SQL, ['user' => $userId, 'revision' => $revisionId]);
    }

    public function isEligibleApprover(string $documentId, string $userId): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM app_user u
            WHERE u.id = :user AND u.is_active AND EXISTS (
                SELECT 1 FROM document_grant g WHERE g.document_id = :doc AND g.role = 'APPROVER' AND g.valid_to IS NULL
                  AND (g.user_id = :user OR g.group_id IN (
                      SELECT gm.group_id FROM group_membership gm WHERE gm.user_id = :user AND gm.valid_to IS NULL)))
            SQL, ['user' => $userId, 'doc' => $documentId]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function lockForDraftChange(PalUser $user, string $revisionId): array
    {
        return $this->lockRevision($user, $revisionId, ['DRAFT']);
    }

    /**
     * @param list<string> $allowedStates
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function lockRevision(PalUser $user, string $revisionId, array $allowedStates): array
    {
        $revision = $this->documents->findRevision($revisionId);
        $document = null === $revision ? null : $this->documents->findDocument(Row::string($revision, 'document_id'), true);
        if (null === $revision || null === $document || !$this->access->isOwnerOrAuthor($user, $document)) {
            throw new ApiProblem(404, 'not_found', 'Revision not found.');
        }
        $revision = $this->documents->findRevision($revisionId, true);
        if (null === $revision || !\in_array($revision['state'], $allowedStates, true)) {
            throw new ApiProblem(409, 'invalid_state', 'This revision cannot be changed in its current state.');
        }
        if ('ACTIVE' !== $document['disposition']) {
            throw new ApiProblem(409, 'document_not_active', 'This document is no longer active.');
        }

        return [$document, $revision];
    }

    /**
     * @param array{title: string, description: string|null, keywords: list<string>, changeSummary: string|null} $content
     * @param array{json: string, text: string}|null                                                          $body
     */
    private function insertDraft(string $documentId, int $major, array $content, PalUser $user, ?string $previousRevisionId, ?array $body = null): string
    {
        return Row::scalarString($this->connection->fetchOne(
            'INSERT INTO revision (document_id, major, title, description, keywords, change_summary, body_json, body_text, previous_revision_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$documentId, $major, $content['title'], $content['description'], DocumentRepository::toPgArray($content['keywords']), $content['changeSummary'], $body['json'] ?? null, $body['text'] ?? null, $previousRevisionId, $user->id],
        ));
    }

    private function setPrimaryFile(string $revisionId, string $sha256, string $filename): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO revision_file (revision_id, sha256, role, original_filename) VALUES (?, ?, 'PRIMARY', ?)
            ON CONFLICT (revision_id, role) DO UPDATE SET sha256 = EXCLUDED.sha256, original_filename = EXCLUDED.original_filename
            SQL, [$revisionId, $sha256, $filename]);
    }

    private function allocateNumber(string $typeId, string $typeCode, string $departmentId, string $departmentCode): string
    {
        $this->connection->executeStatement('INSERT INTO numbering_counter (document_type_id, org_unit_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$typeId, $departmentId]);
        $next = Row::int(['v' => $this->connection->fetchOne('SELECT next_value FROM numbering_counter WHERE document_type_id = ? AND org_unit_id = ? FOR UPDATE', [$typeId, $departmentId])], 'v');
        $this->connection->executeStatement('UPDATE numbering_counter SET next_value = next_value + 1 WHERE document_type_id = ? AND org_unit_id = ?', [$typeId, $departmentId]);

        return \sprintf('%s-%s-%04d', $typeCode, $departmentCode, $next);
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return array{title: string, description: string|null, keywords: list<string>, changeSummary: string|null}
     */
    private function validateContent(array $input, array &$errors): array
    {
        $title = \is_string($input['title'] ?? null) ? self::clean($input['title']) : '';
        if ('' === $title || mb_strlen($title) > 300) {
            $errors['title'] = 'Give the document a title (up to 300 characters).';
        }
        $description = \is_string($input['description'] ?? null) ? self::clean($input['description'], true) : '';
        if (mb_strlen($description) > 4000) {
            $errors['description'] = 'Keep the description under 4,000 characters.';
        }
        $summary = \is_string($input['changeSummary'] ?? null) ? self::clean($input['changeSummary'], true) : '';
        if (mb_strlen($summary) > 2000) {
            $errors['changeSummary'] = 'Keep the change summary under 2,000 characters.';
        }
        $keywords = [];
        $raw = $input['keywords'] ?? [];
        if (!\is_array($raw)) {
            $raw = [];
        }
        foreach ($raw as $keyword) {
            $keyword = \is_string($keyword) ? self::clean($keyword) : '';
            if ('' !== $keyword && !\in_array(mb_strtolower($keyword), array_map('mb_strtolower', $keywords), true)) {
                $keywords[] = mb_substr($keyword, 0, 40);
            }
        }
        if (\count($keywords) > 20) {
            $errors['keywords'] = 'Use at most 20 keywords.';
        }

        return [
            'title' => $title,
            'description' => '' === $description ? null : $description,
            'keywords' => \array_slice($keywords, 0, 20),
            'changeSummary' => '' === $summary ? null : $summary,
        ];
    }

    private function cleanComment(?string $comment): ?string
    {
        $comment = null === $comment ? '' : self::clean($comment, true);

        return '' === $comment ? null : mb_substr($comment, 0, 2000);
    }

    private static function clean(string $value, bool $multiline = false): string
    {
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';

        return trim((string) preg_replace($pattern, '', $value));
    }

    private function actor(PalUser $user): AuditActor
    {
        return $user->auditActor();
    }
}
