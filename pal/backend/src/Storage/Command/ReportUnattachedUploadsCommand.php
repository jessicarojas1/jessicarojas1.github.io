<?php

declare(strict_types=1);

namespace App\Storage\Command;

use App\Audit\AuditActor;
use App\Storage\UnattachedUploadReclaimer;
use App\Storage\UnattachedUploadScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports, and with --delete reclaims, never-attached uploads — blobs stored for a clean upload
 * that no document revision references — and the disk space they occupy.
 *
 * Dry-run by default (report only). --delete removes the DATABASE rows (the upload records and the
 * stored_file row) for candidates older than --older-than (default 30d); the retention window
 * protects uploads a user has not yet attached, so --delete requires a positive window. The blob
 * file is then unreferenced; `pal:storage:gc --delete` frees it from disk race-safely.
 */
#[AsCommand(name: 'pal:storage:unattached', description: 'Report (or with --delete reclaim) never-attached uploads older than a retention window')]
final class ReportUnattachedUploadsCommand extends Command
{
    private const SAMPLE = 20;
    private const DEFAULT_AGE = '30d';

    public function __construct(
        private readonly UnattachedUploadScanner $scanner,
        private readonly UnattachedUploadReclaimer $reclaimer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Retention window: only uploads stored before this (e.g. 30d, 24h). Use 0 to report all.', self::DEFAULT_AGE)
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Reclaim (delete upload + stored_file rows); otherwise report only')
            ->addOption('fail-over-bytes', null, InputOption::VALUE_REQUIRED, 'Report mode: exit non-zero when the reclaimable total is at least this many bytes (for alerting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawOption = $input->getOption('older-than');
        $raw = \is_string($rawOption) ? $rawOption : self::DEFAULT_AGE;
        $minAge = self::parseDuration($raw);
        if (null === $minAge) {
            $io->error(\sprintf('Invalid --older-than value "%s". Use seconds or a suffix: 30m, 24h, 7d.', $raw));

            return Command::INVALID;
        }

        return true === $input->getOption('delete')
            ? $this->reclaim($io, $minAge, $raw)
            : $this->report($io, $input, $minAge, $raw);
    }

    private function report(SymfonyStyle $io, InputInterface $input, int $minAge, string $raw): int
    {
        $report = $this->scanner->scan($minAge, self::SAMPLE);
        $scope = $minAge > 0 ? \sprintf(' stored more than %s ago', $raw) : '';
        if (0 === $report->fileCount) {
            $io->success(\sprintf('No never-attached uploads%s.', $scope));

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('Never-attached uploads%s: %d file(s), %s reclaimable.', $scope, $report->fileCount, self::humanBytes($report->totalBytes)));
        if ([] !== $report->sample) {
            $io->section(\sprintf('Largest %d', \count($report->sample)));
            $io->table(
                ['SHA-256 (short)', 'Size', 'Uploads', 'First uploaded'],
                array_map(static fn (array $r): array => [
                    substr($r['sha256'], 0, 12).'…',
                    self::humanBytes($r['sizeBytes']),
                    (string) $r['uploadCount'],
                    $r['firstUploadedAt'],
                ], $report->sample),
            );
        }
        $io->note('Read-only: nothing was deleted. Re-run with --delete to reclaim, then `pal:storage:gc --delete` to free disk.');

        $failOver = $input->getOption('fail-over-bytes');
        if (\is_string($failOver) && ctype_digit($failOver) && $report->totalBytes >= (int) $failOver) {
            $io->warning(\sprintf('Reclaimable total %s is at or above the %s threshold.', self::humanBytes($report->totalBytes), self::humanBytes((int) $failOver)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function reclaim(SymfonyStyle $io, int $minAge, string $raw): int
    {
        if ($minAge <= 0) {
            $io->error('--delete needs a positive --older-than retention window (e.g. 30d); it protects uploads a user has not yet attached.');

            return Command::INVALID;
        }

        $actor = AuditActor::system('storage-reclaim');
        $reclaimed = 0;
        $skipped = 0;
        $bytes = 0;
        $uploadRows = 0;
        // Materialise the candidate SHAs first so iterating is not disturbed by the deletes.
        foreach (iterator_to_array($this->reclaimer->candidates($minAge), false) as $sha256) {
            $result = $this->reclaimer->reclaim($sha256, $minAge, $actor);
            if (null === $result) {
                ++$skipped;

                continue;
            }
            ++$reclaimed;
            $bytes += $result['sizeBytes'];
            $uploadRows += $result['uploads'];
        }

        if (0 === $reclaimed) {
            $io->success(\sprintf('No never-attached uploads to reclaim (older than %s).%s', $raw, $skipped > 0 ? \sprintf(' Skipped %d that became ineligible.', $skipped) : ''));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Reclaimed %d never-attached upload(s) (%d upload row(s)); %s of blobs are now unreferenced.', $reclaimed, $uploadRows, self::humanBytes($bytes)));
        if ($skipped > 0) {
            $io->writeln(\sprintf('Skipped %d that became attached or were already gone.', $skipped));
        }
        $io->note('Run `pal:storage:gc --delete` to remove the unreferenced blobs from disk (it is race-safe against a concurrent re-upload).');

        return Command::SUCCESS;
    }

    /** Parses "0", "90", "30m", "24h", "7d" into seconds; null when the value is not valid. */
    private static function parseDuration(string $value): ?int
    {
        $value = trim($value);
        if (1 !== preg_match('/^(\d+)([smhd]?)$/', $value, $m)) {
            return null;
        }
        $n = (int) $m[1];
        $unit = '' === $m[2] ? 's' : $m[2];

        return $n * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$unit];
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return 0 === $i ? $bytes.' B' : \sprintf('%.1f %s', $value, $units[$i]);
    }
}
