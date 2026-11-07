<?php

declare(strict_types=1);

namespace App\Storage\Command;

use App\Platform\Database\Row;
use App\Storage\StorageGarbageCollector;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclaims disk from UNREFERENCED blobs — files with no `stored_file` row (orphans from a
 * failed post-upload insert) and abandoned `.incoming-*` temporaries. It never deletes a
 * blob PAL recorded (including never-attached uploads). Dry-run by default: pass --delete to
 * remove. An age guard (--older-than, default 24h) avoids racing an in-flight upload.
 */
#[AsCommand(name: 'pal:storage:gc', description: 'Reclaim unreferenced blobs and abandoned upload temporaries (dry-run unless --delete)')]
final class CollectStorageGarbageCommand extends Command
{
    private const SAMPLE = 20;
    private const DEFAULT_AGE = '24h';

    public function __construct(
        private readonly Connection $connection,
        private readonly StorageGarbageCollector $collector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Actually delete (otherwise report only)')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Only collect items older than this (e.g. 30m, 24h, 7d)', self::DEFAULT_AGE);
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

        $delete = true === $input->getOption('delete');
        $report = $this->collector->collect($this->referencedHashes(), $minAge, $delete);

        $io->writeln(\sprintf('Scanned %d blob(s); age guard: %s.', $report->scanned, $raw));
        if (!$report->foundAnything()) {
            $io->success('Nothing to reclaim: no unreferenced blobs or stale temporaries.');

            return Command::SUCCESS;
        }

        if ([] !== $report->orphans) {
            $io->section(\sprintf('Unreferenced blobs%s (%d)', $delete ? ' deleted' : ' (would delete)', \count($report->orphans)));
            $io->listing(\array_slice($report->orphans, 0, self::SAMPLE));
            if (\count($report->orphans) > self::SAMPLE) {
                $io->writeln(\sprintf('  … and %d more', \count($report->orphans) - self::SAMPLE));
            }
        }
        if ($report->temporariesFound > 0) {
            $io->writeln(\sprintf('Abandoned .incoming-* temporaries: %d%s.', $report->temporariesFound, $delete ? \sprintf(' (deleted %d)', $report->temporariesDeleted) : ' (would delete)'));
        }
        if ($report->skippedRecent > 0) {
            $io->writeln(\sprintf('Skipped %d unreferenced blob(s) newer than the age guard.', $report->skippedRecent));
        }
        if ([] !== $report->failed) {
            $io->warning(\sprintf('%d blob(s) could not be deleted (the blob directory may deny delete to this user). First: %s', \count($report->failed), implode(', ', \array_slice($report->failed, 0, 5))));
        }

        if (!$delete) {
            $io->note('Dry run — nothing was deleted. Re-run with --delete to reclaim.');

            return Command::SUCCESS;
        }
        $io->success(\sprintf('Reclaimed %d blob(s) and %d temporary file(s).', $report->deleted, $report->temporariesDeleted));

        return [] === $report->failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return iterable<string>
     */
    private function referencedHashes(): iterable
    {
        foreach ($this->connection->iterateAssociative('SELECT sha256 FROM stored_file') as $row) {
            yield Row::string($row, 'sha256');
        }
    }

    /** Parses "90", "30m", "24h", "7d" into seconds; null when the value is not valid. */
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
}
