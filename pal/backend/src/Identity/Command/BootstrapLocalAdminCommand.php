<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Audit\AuditActor;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Local\LocalAccountService;
use App\Identity\Local\PasswordPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the first local System Administrator from PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME and
 * PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD, ONLY if no local account exists yet. The password is
 * marked temporary: it must be changed at first sign-in. Remove both variables afterwards.
 * Run automatically by the container's migrate step; idempotent.
 */
#[AsCommand(name: 'pal:users:bootstrap-admin', description: 'Create the first local administrator from environment variables (once)')]
final class BootstrapLocalAdminCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LocalAccountService $accounts,
        #[Autowire(env: 'default::PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME')]
        private readonly ?string $username,
        #[Autowire(env: 'default::PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD')]
        private readonly ?string $password,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (null === $this->username || '' === trim($this->username) || null === $this->password || '' === $this->password) {
            $io->writeln('Bootstrap admin not configured; nothing to do.');

            return Command::SUCCESS;
        }
        if (false !== $this->connection->fetchOne("SELECT 1 FROM app_user WHERE source = 'LOCAL' LIMIT 1")) {
            $io->writeln('A local account already exists; bootstrap skipped. Remove PAL_BOOTSTRAP_LOCAL_ADMIN_* variables.');

            return Command::SUCCESS;
        }
        $usernameReason = LocalAccountService::usernameRejectionReason($this->username);
        if (null !== $usernameReason) {
            $io->error('PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME rejected: '.$usernameReason);

            return Command::FAILURE;
        }
        $reason = PasswordPolicy::rejectionReason($this->password, LocalAccountService::normalizeUsername($this->username));
        if (null !== $reason) {
            $io->error('PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD rejected: '.$reason);

            return Command::FAILURE;
        }
        $actor = AuditActor::system('bootstrap');
        $created = $this->accounts->create($actor, null, $this->username, 'Administrator', null, [GlobalRole::SystemAdministrator, GlobalRole::PalAdministrator]);
        $this->accounts->setBootstrapPassword($actor, $created['id'], $this->password);
        $io->success(\sprintf('Created local administrator "%s". The password must be changed at first sign-in.', LocalAccountService::normalizeUsername($this->username)));

        return Command::SUCCESS;
    }
}
