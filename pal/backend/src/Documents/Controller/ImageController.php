<?php

declare(strict_types=1);

namespace App\Documents\Controller;

use App\Documents\DocumentAccess;
use App\Documents\DocumentRepository;
use App\Documents\Ids;
use App\Identity\Authorization\Permission;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use App\Storage\BlobStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Serves images shown in editor content (decision #29). An image is returned only when the
 * user may see it through the context it is shown in:
 *  - ?revision=ID: the revision shows it (revision_image) and the user may read that revision
 *    (the published version as a reader, otherwise work-in-progress access), or
 *  - ?template=ID: some version of the template shows it and the user administers templates,
 * or when the user uploaded it themselves (so it shows while they are still editing).
 * Anything else is a 404, so the endpoint cannot be used to probe which images exist.
 */
final class ImageController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentRepository $documents,
        private readonly DocumentAccess $access,
        private readonly BlobStore $blobs,
    ) {
    }

    #[Route('/api/images/{sha256}', name: 'api_image', methods: ['GET'], requirements: ['sha256' => '[0-9a-f]{64}'])]
    public function show(#[CurrentUser] PalUser $user, string $sha256, Request $request): StreamedResponse
    {
        $file = $this->connection->fetchAssociative("SELECT size_bytes, detected_mime FROM stored_file WHERE sha256 = ? AND detected_mime LIKE 'image/%'", [$sha256]);
        if (false === $file || !$this->mayView($user, $sha256, $request)) {
            throw new ApiProblem(404, 'not_found', 'Image not found.');
        }
        if (!$this->blobs->exists($sha256)) {
            throw new ApiProblem(410, 'file_unavailable', 'This image is no longer in storage.');
        }

        $response = new StreamedResponse(function () use ($sha256): void {
            $handle = $this->blobs->openRead($sha256);
            fpassthru($handle);
            fclose($handle);
        });
        $response->headers->set('Content-Type', Row::string($file, 'detected_mime'));
        $response->headers->set('Content-Length', (string) Row::int($file, 'size_bytes'));
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        // Content-addressed: the bytes behind this URL never change. Private: access-checked.
        $response->headers->set('Cache-Control', 'private, max-age=86400, immutable');
        $response->headers->set('ETag', '"'.$sha256.'"');

        return $response;
    }

    private function mayView(PalUser $user, string $sha256, Request $request): bool
    {
        $revisionId = (string) $request->query->get('revision', '');
        if ('' !== $revisionId) {
            return Ids::isUuid($revisionId) && $this->mayViewInRevision($user, $sha256, $revisionId);
        }
        $templateId = (string) $request->query->get('template', '');
        if ('' !== $templateId) {
            return Ids::isUuid($templateId) && DocumentAccess::can($user, Permission::ADMIN_TEMPLATES)
                && false !== $this->connection->fetchOne('SELECT 1 FROM template_version_image WHERE template_id = ? AND sha256 = ? LIMIT 1', [$templateId, $sha256]);
        }

        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM upload WHERE uploaded_by = ? AND stored_sha256 = ? AND status = 'CLEAN' LIMIT 1",
            [$user->id, $sha256],
        );
    }

    private function mayViewInRevision(PalUser $user, string $sha256, string $revisionId): bool
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM revision_image WHERE revision_id = ? AND sha256 = ?', [$revisionId, $sha256])) {
            // Not saved into the draft yet: its uploader may still see it.
            return false !== $this->connection->fetchOne(
                "SELECT 1 FROM upload WHERE uploaded_by = ? AND stored_sha256 = ? AND status = 'CLEAN' LIMIT 1",
                [$user->id, $sha256],
            );
        }
        $revision = $this->documents->findRevision($revisionId);
        $document = null === $revision ? null : $this->documents->findDocument(Row::string($revision, 'document_id'));
        if (null === $document) {
            return false;
        }
        $visible = $this->documents->visibleRevision(Row::string($document, 'id'));
        $isCurrent = null !== $visible && Row::string($visible, 'id') === $revisionId;

        return $isCurrent
            ? $this->access->canReadPublished($user, $document)
            : $this->access->canReadWorkInProgress($user, $document, $revisionId);
    }
}
