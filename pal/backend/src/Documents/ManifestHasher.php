<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * The fingerprint an approval binds to: the revision's versioned metadata, the exact
 * bytes (by SHA-256) of every attached file, and the SHA-256 of the canonical editor body
 * when there is one. Any change produces a different hash, so an approval can never
 * silently carry over to different content.
 *
 * The body is only part of the hashed structure when present, so revisions without editor
 * content (all revisions approved before the editor existed) keep exactly the hash they
 * were approved under.
 */
final class ManifestHasher
{
    /**
     * @param list<string>                                              $keywords
     * @param list<array{role: string, sha256: string, filename: string}> $files
     */
    public static function hash(string $title, ?string $description, array $keywords, ?string $changeSummary, array $files, ?string $bodySha256 = null): string
    {
        usort($files, fn (array $a, array $b): int => strcmp($a['role'], $b['role']));
        $manifest = [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'change_summary' => $changeSummary,
            'files' => array_map(fn (array $f): array => ['role' => $f['role'], 'sha256' => $f['sha256'], 'filename' => $f['filename']], $files),
        ];
        if (null !== $bodySha256) {
            $manifest['body_sha256'] = $bodySha256;
        }

        return hash('sha256', json_encode($manifest, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }
}
