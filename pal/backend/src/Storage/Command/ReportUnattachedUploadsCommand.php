<?php

declare(strict_types=1);

namespace App\Storage\Command;

use App\Storage\UnattachedUploadScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports never-attached uploads — blobs stored for a clean upload that were never referenced by
 * any document revision — and the disk space they occupy. READ-ONLY: it deletes nothing. Use it
 * for disk-usage visibility/alerting. Reclaiming this space is a separate, policy-gated step
 * (retention window + removing the upload/stored_file rows) that is not yet implemented.
 */
#[AsCommand(name: 'pal:storage:unattached', description: 'Report never-attached uploads and the space they occupy (read-only)')]
final class ReportUnattachedUploadsCommand extends Command
{
    private const SAMPLE = 20;

    public function __construct(
        private readonly UnattachedUploadScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Only count uploads stored before this age (e.g. 7d, 24h, 30d); default: all', '0')
            ->addOption('fail-over-bytes', null, InputOption::VALUE_REQUIRED, 'Exit non-zero when the reclaimable total is at least this many bytes (for alerting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawOption = $input->getOption('older-than');
        $raw = \is_string($rawOption) ? $rawOption : '0';
        $minAge = self::parseDuration($raw);
        if (null === $minAge) {
            $io->error(\sprintf('Invalid --older-than value "%s". Use seconds or a suffix: 30m, 24h, 7d.', $raw));

            return Command::INVALID;
        }

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
        $io->note('Read-only: nothing was deleted. Reclaiming this space needs a retention policy and is not yet implemented (see OPEN_ITEMS.md).');

        $failOver = $input->getOption('fail-over-bytes');
        if (\is_string($failOver) && ctype_digit($failOver) && $report->totalBytes >= (int) $failOver) {
            $io->warning(\sprintf('Reclaimable total %s is at or above the %s threshold.', self::humanBytes($report->totalBytes), self::humanBytes((int) $failOver)));

            return Command::FAILURE;
        }

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
