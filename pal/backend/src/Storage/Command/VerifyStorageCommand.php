<?php

declare(strict_types=1);

namespace App\Storage\Command;

use App\Platform\Database\Row;
use App\Storage\BlobStore;
use App\Storage\StorageVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies that every blob PAL recorded in `stored_file` still exists in the blob store and
 * matches its SHA-256. Supports the disaster-recovery consistency check (DISASTER_RECOVERY.md).
 * Exits non-zero when anything is missing or corrupt, so it can gate a scheduled check.
 */
#[AsCommand(name: 'pal:storage:verify', description: 'Verify every stored file exists in the blob store and still matches its SHA-256')]
final class VerifyStorageCommand extends Command
{
    private const SAMPLE = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly StorageVerifier $verifier,
        private readonly BlobStore $blobs,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->verifier->verify($this->referencedHashes());

        if (0 === $report->checked) {
            $io->success('No stored files to verify.');

            return Command::SUCCESS;
        }
        if ($report->isClean()) {
            $io->success(\sprintf('Storage verified: %d file(s) present and intact (backend: %s).', $report->checked, $this->blobs->backendName()));

            return Command::SUCCESS;
        }

        $io->error(\sprintf(
            'Storage verification FAILED: %d missing, %d corrupt of %d file(s).',
            \count($report->missing),
            \count($report->corrupt),
            $report->checked,
        ));
        $this->listProblems($io, 'Missing', $report->missing);
        $this->listProblems($io, 'Corrupt (hash mismatch or unreadable)', $report->corrupt);

        return Command::FAILURE;
    }

    /**
     * @return iterable<string>
     */
    private function referencedHashes(): iterable
    {
        foreach ($this->connection->iterateAssociative('SELECT sha256 FROM stored_file ORDER BY created_at') as $row) {
            yield Row::string($row, 'sha256');
        }
    }

    /**
     * @param list<string> $items
     */
    private function listProblems(SymfonyStyle $io, string $label, array $items): void
    {
        if ([] === $items) {
            return;
        }
        $io->section(\sprintf('%s (%d)', $label, \count($items)));
        $io->listing(\array_slice($items, 0, self::SAMPLE));
        if (\count($items) > self::SAMPLE) {
            $io->writeln(\sprintf('  … and %d more', \count($items) - self::SAMPLE));
        }
    }
}
