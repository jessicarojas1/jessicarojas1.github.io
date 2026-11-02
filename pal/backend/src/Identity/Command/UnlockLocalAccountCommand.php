<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Local\LocalAccountService;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Clears a sign-in lockout from the server side. Lockout can be triggered by anyone who knows
 * a username, so this is the recovery path when the only administrator is locked out.
 */
#[AsCommand(name: 'pal:users:unlock', description: 'Clear the sign-in lockout on a local account')]
final class UnlockLocalAccountCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'The local username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('username');
        $username = LocalAccountService::normalizeUsername(\is_string($argument) ? $argument : '');
        $id = $this->connection->fetchOne('SELECT id FROM app_user WHERE username = ?', [$username]);
        if (false === $id) {
            $io->error('No local account has that username.');

            return Command::FAILURE;
        }
        $userId = Row::scalarString($id);
        $this->connection->transactional(function () use ($userId, $username): void {
            $this->connection->executeStatement('UPDATE local_credential SET failed_attempts = 0, locked_until = NULL, updated_at = now() WHERE user_id = ?', [$userId]);
            $this->audit->record(new AuditEntry('USER_UNLOCKED', AuditActor::system('console'), 'app_user', $userId, ['username' => $username]));
        });
        $io->success('Lockout cleared.');

        return Command::SUCCESS;
    }
}
