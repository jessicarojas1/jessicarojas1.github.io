<?php

declare(strict_types=1);

namespace App\Storage;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The only way bytes enter PAL: size limit → hash → content-type detection and
 * allow-list → malware scan → promotion to the immutable store. Every attempt is
 * recorded, including rejected and infected ones. Nothing unscanned is ever promoted.
 */
final class UploadService
{
    public function __construct(
        private readonly BlobStore $blobs,
        private readonly MalwareScanner $scanner,
        private readonly FileTypePolicy $types,
        private readonly ImageSanitizer $images,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:PAL_MAX_UPLOAD_BYTES')]
        private readonly int $maxBytes,
        #[Autowire(env: 'int:default:pal.upload_daily_bytes_default:PAL_UPLOAD_DAILY_BYTES')]
        private readonly int $dailyBytes = 524_288_000,
        #[Autowire(env: 'int:default:pal.max_image_bytes_default:PAL_MAX_IMAGE_BYTES')]
        private readonly int $maxImageBytes = 10_485_760,
    ) {
    }

    /** The configured image limit (PAL_MAX_IMAGE_BYTES). */
    public function maxImageBytes(): int
    {
        return min($this->maxImageBytes, $this->maxBytes);
    }

    /** The configured upload limit (PAL_MAX_UPLOAD_BYTES), shown to users before they upload. */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * An image for document content: the same pipeline as files, with the image allow-list
     * and size limit, and the stored bytes are a metadata-free re-encoding (decision #29).
     *
     * @return array{id: string, filename: string, sizeBytes: int, mime: string, sha256: string}
     */
    public function acceptImage(UploadedFile $file, PalUser $user): array
    {
        return $this->accept($file, $user, true);
    }

    /**
     * @return array{id: string, filename: string, sizeBytes: int, mime: string, sha256: string}
     */
    public function accept(UploadedFile $file, PalUser $user, bool $image = false): array
    {
        if (!$file->isValid()) {
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                throw new ApiProblem(413, 'file_too_large', $this->tooLargeMessage());
            }
            throw new ApiProblem(400, 'upload_failed', 'The file did not arrive completely. Try again.');
        }
        $path = $file->getPathname();
        $filename = FileTypePolicy::safeFilename($file->getClientOriginalName());
        $size = (int) filesize($path);
        $actor = $user->auditActor();

        if ($size <= 0) {
            throw new ApiProblem(422, 'file_empty', 'The file is empty.');
        }
        $this->assertWithinDailyQuota($user, $size);
        $limit = $image ? $this->maxImageBytes() : $this->maxBytes;
        if ($size > $limit) {
            $this->record($user, $actor, $filename, $size, null, null, 'REJECTED', 'too large');
            throw new ApiProblem(413, 'file_too_large', $this->tooLargeMessage($limit));
        }

        $sha256 = (string) hash_file('sha256', $path);
        $mime = $this->types->detect($path);
        $reason = $image ? $this->images->rejectionReason($mime, $filename) : $this->types->rejectionReason($mime, $filename);
        if (null !== $reason) {
            $this->record($user, $actor, $filename, $size, $sha256, $mime, 'REJECTED', $reason);
            throw new ApiProblem(422, 'file_type_not_allowed', $reason);
        }

        $scan = $this->scanner->scan($path);
        if ($scan->isInfected()) {
            $this->record($user, $actor, $filename, $size, $sha256, $mime, 'INFECTED', (string) $scan->detail);
            throw new ApiProblem(422, 'malware_detected', 'The file was blocked because the malware scanner flagged it.');
        }
        if (!$scan->isClean()) {
            $this->record($user, $actor, $filename, $size, $sha256, $mime, 'ERROR', (string) $scan->detail);
            throw new ApiProblem(503, 'scanner_unavailable', 'Files cannot be checked for malware right now, so the upload was not accepted. Try again later.');
        }

        // Images are stored as a metadata-free re-encoding of the scanned original.
        $storedPath = $path;
        if ($image) {
            try {
                $storedPath = $this->images->sanitize($path, $mime);
            } catch (ApiProblem $problem) {
                $this->record($user, $actor, $filename, $size, $sha256, $mime, 'REJECTED', $problem->getMessage());
                throw $problem;
            }
        }
        try {
            $storedSha = $storedPath === $path ? $sha256 : (string) hash_file('sha256', $storedPath);
            $storedSize = (int) filesize($storedPath);
            // Content-addressed and idempotent: storing before the DB write can only leave an
            // unreferenced blob behind (harmless; collected later), never a dangling reference.
            $this->blobs->put($storedPath, $storedSha);
        } finally {
            if ($storedPath !== $path) {
                @unlink($storedPath);
            }
        }

        $id = $this->connection->transactional(function () use ($user, $actor, $filename, $size, $sha256, $mime, $scan, $storedSha, $storedSize): string {
            $this->connection->executeStatement(
                'INSERT INTO stored_file (sha256, size_bytes, detected_mime, scan_engine, scanned_at, storage_backend) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (sha256) DO NOTHING',
                [$storedSha, $storedSize, $mime, $scan->engine, $this->clock->now()->format('Y-m-d H:i:s.uP'), $this->blobs->backendName()],
            );

            return $this->record($user, $actor, $filename, $size, $sha256, $mime, 'CLEAN', null, $storedSha);
        });

        return ['id' => $id, 'filename' => $filename, 'sizeBytes' => $storedSize, 'mime' => $mime, 'sha256' => $storedSha];
    }

    /**
     * An upload may be attached only by the person who uploaded it, and only if it was clean.
     *
     * @return array{sha256: string, filename: string}
     */
    public function claim(string $uploadId, PalUser $user): array
    {
        $row = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uploadId)
            ? $this->connection->fetchAssociative("SELECT stored_sha256, original_filename FROM upload WHERE id = ? AND uploaded_by = ? AND status = 'CLEAN'", [$uploadId, $user->id])
            : false;
        if (false === $row) {
            throw ApiProblem::validation(['uploadId' => 'Upload the file again; this upload was not found.']);
        }

        return ['sha256' => Row::string($row, 'stored_sha256'), 'filename' => Row::string($row, 'original_filename')];
    }

    private function record(PalUser $user, AuditActor $actor, string $filename, int $size, ?string $sha256, ?string $mime, string $status, ?string $reason, ?string $storedSha = null): string
    {
        $write = function () use ($user, $actor, $filename, $size, $sha256, $mime, $status, $reason, $storedSha): string {
            $id = Row::scalarString($this->connection->fetchOne(
                'INSERT INTO upload (uploaded_by, original_filename, size_bytes, sha256, detected_mime, status, reason, stored_sha256) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$user->id, $filename, $size, $sha256, $mime, $status, null === $reason ? null : mb_substr($reason, 0, 500), $storedSha],
            ));
            $this->audit->record(new AuditEntry(
                match ($status) { 'CLEAN' => 'UPLOAD_ACCEPTED', 'INFECTED' => 'UPLOAD_MALWARE_DETECTED', default => 'UPLOAD_REJECTED' },
                $actor,
                'upload',
                $id,
                ['filename' => $filename, 'size_bytes' => $size, 'sha256' => $sha256, 'detected_mime' => $mime, 'status' => $status],
                $reason,
            ));

            return $id;
        };

        return $this->connection->isTransactionActive() ? $write() : $this->connection->transactional($write);
    }

    /**
     * Every accepted upload consumes storage that is never reclaimed (write-once), so one
     * account must not be able to fill the volume. Slight overshoot under concurrent uploads
     * is acceptable; the limit is a safeguard, not an accounting control.
     */
    private function assertWithinDailyQuota(PalUser $user, int $size): void
    {
        $used = $this->connection->fetchOne(
            "SELECT COALESCE(sum(size_bytes), 0) FROM upload WHERE uploaded_by = ? AND status = 'CLEAN' AND created_at > now() - interval '24 hours'",
            [$user->id],
        );
        if (is_numeric($used) && (int) $used + $size > $this->dailyBytes) {
            throw new ApiProblem(429, 'upload_quota_exceeded', 'You have reached the daily upload limit. Try again tomorrow.');
        }
    }

    private function tooLargeMessage(?int $limit = null): string
    {
        return \sprintf('Files can be up to %d MB.', intdiv($limit ?? $this->maxBytes, 1_048_576));
    }
}
