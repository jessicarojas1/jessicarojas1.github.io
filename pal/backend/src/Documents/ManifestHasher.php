<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * The fingerprint an approval binds to: the revision's versioned metadata plus the exact
 * bytes (by SHA-256) of every attached file. Any change produces a different hash, so an
 * approval can never silently carry over to different content.
 */
final class ManifestHasher
{
    /**
     * @param list<string>                                              $keywords
     * @param list<array{role: string, sha256: string, filename: string}> $files
     */
    public static function hash(string $title, ?string $description, array $keywords, ?string $changeSummary, array $files): string
    {
        usort($files, fn (array $a, array $b): int => strcmp($a['role'], $b['role']));

        return hash('sha256', json_encode([
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'change_summary' => $changeSummary,
            'files' => array_map(fn (array $f): array => ['role' => $f['role'], 'sha256' => $f['sha256'], 'filename' => $f['filename']], $files),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }
}
