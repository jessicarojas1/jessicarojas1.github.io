<?php

declare(strict_types=1);

namespace App\Audit\Command;

use App\Audit\AuditAnchorWriter;
use App\Audit\AuditChainVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pal:audit:verify', description: 'Verify the audit hash chain and optionally anchor its head externally')]
final class VerifyAuditChainCommand extends Command
{
    public function __construct(
        private readonly AuditChainVerifier $verifier,
        private readonly AuditAnchorWriter $anchors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('anchor', null, InputOption::VALUE_NONE, 'Append the verified head hash to PAL_AUDIT_ANCHOR_DIR');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->verifier->verify();
        if (!$report->valid) {
            $io->error(\sprintf('Audit chain BROKEN at event %d: %s', (int) $report->firstBrokenEventId, (string) $report->failure));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Audit chain valid: %d events, head %s', $report->eventsChecked, $report->headHash));
        if ($input->getOption('anchor')) {
            $io->writeln('Anchor written to '.$this->anchors->write($report));
        }

        return Command::SUCCESS;
    }
}
