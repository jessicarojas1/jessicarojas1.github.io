<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Audit\AuditActor;
use App\Identity\Directory\DirectorySynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pal:directory:sync', description: 'Sync membership of all tracked directory groups now')]
final class SyncDirectoryCommand extends Command
{
    public function __construct(private readonly DirectorySynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->synchronizer->isConfigured()) {
            $io->error('Directory sync is not configured (OIDC_* and GRAPH_BASE_URL).');

            return Command::FAILURE;
        }
        $summary = $this->synchronizer->syncAll(AuditActor::system('cli:pal:directory:sync'));
        foreach ($summary->synced as $groupId => $result) {
            $io->writeln(\sprintf('%s: %d members (+%d / -%d)', $groupId, $result['members'], $result['added'], $result['removed']));
        }
        foreach ($summary->failed as $groupId => $error) {
            $io->error(\sprintf('%s: %s', $groupId, $error));
        }

        return $summary->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}
