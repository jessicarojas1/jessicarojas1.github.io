<?php

declare(strict_types=1);

namespace App\Documents;

use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use Doctrine\DBAL\Connection;

/**
 * Images in editor content (decision #29): who may put which image into content, and the
 * reference rows that tie stored images to revisions and template versions.
 *
 * An image may appear in content only if it is already part of what is being edited (the
 * draft, the revision it was copied from, the template it started from) or the editing user
 * uploaded it themselves as an image. That stops anyone from embedding an image from a
 * document they cannot read just by knowing its SHA-256.
 */
final class ContentImages
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $images  every image the new content shows
     * @param list<string> $already images already part of what is being edited
     */
    public function assertMayUse(PalUser $user, array $images, array $already): void
    {
        $new = array_values(array_diff($images, $already));
        if ([] === $new) {
            return;
        }
        $owned = Row::strings($this->connection->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT u.stored_sha256 FROM upload u JOIN stored_file sf ON sf.sha256 = u.stored_sha256
            WHERE u.uploaded_by = ? AND u.status = 'CLEAN' AND sf.detected_mime LIKE 'image/%' AND u.stored_sha256 = ANY(?::char(64)[])
            SQL, [$user->id, '{'.implode(',', $new).'}']));
        if ([] !== array_diff($new, $owned)) {
            throw ApiProblem::validation(['body' => 'An image in the content is not available to you. Insert it again from your computer.']);
        }
    }

    /**
     * @return list<string>
     */
    public function ofRevision(string $revisionId): array
    {
        return Row::strings($this->connection->fetchFirstColumn('SELECT sha256 FROM revision_image WHERE revision_id = ?', [$revisionId]));
    }

    /**
     * Images of any version of a template (an administrator may restore an older version).
     *
     * @return list<string>
     */
    public function ofTemplate(string $templateId): array
    {
        return Row::strings($this->connection->fetchFirstColumn('SELECT DISTINCT sha256 FROM template_version_image WHERE template_id = ?', [$templateId]));
    }

    /**
     * @return list<string>
     */
    public function ofTemplateVersion(string $templateId, int $version): array
    {
        return Row::strings($this->connection->fetchFirstColumn('SELECT sha256 FROM template_version_image WHERE template_id = ? AND version = ?', [$templateId, $version]));
    }

    /**
     * Makes the draft's reference rows match its content. Only valid while DRAFT (trigger).
     *
     * @param list<string> $images
     */
    public function syncRevision(string $revisionId, array $images): void
    {
        $current = $this->ofRevision($revisionId);
        foreach (array_diff($current, $images) as $gone) {
            $this->connection->executeStatement('DELETE FROM revision_image WHERE revision_id = ? AND sha256 = ?', [$revisionId, $gone]);
        }
        foreach (array_diff($images, $current) as $added) {
            $this->connection->executeStatement('INSERT INTO revision_image (revision_id, sha256) VALUES (?, ?)', [$revisionId, $added]);
        }
    }

    /**
     * @param list<string> $images
     */
    public function recordTemplateVersion(string $templateId, int $version, array $images): void
    {
        foreach ($images as $sha) {
            $this->connection->executeStatement('INSERT INTO template_version_image (template_id, version, sha256) VALUES (?, ?, ?) ON CONFLICT DO NOTHING', [$templateId, $version, $sha]);
        }
    }
}
