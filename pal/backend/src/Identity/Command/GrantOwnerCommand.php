<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Local\LocalAccountService;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grants (or revokes) the Owner role — the superuser / god mode — on a LOCAL account, from the
 * server side. Owner is otherwise granted only by another Owner, so this is the out-of-band
 * path for designating the first Owner on an instance that was already bootstrapped before the
 * role existed (shell access is already full trust). The change is audited like any other.
 */
#[AsCommand(name: 'pal:users:grant-owner', description: 'Grant or revoke the Owner (superuser) role on a local account')]
final class GrantOwnerCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The local username')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke the Owner role instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('username');
        $username = LocalAccountService::normalizeUsername(\is_string($argument) ? $argument : '');
        $revoke = (bool) $input->getOption('revoke');

        $id = $this->connection->fetchOne('SELECT id FROM app_user WHERE username = ?', [$username]);
        if (false === $id) {
            $io->error('No local account has that username.');

            return Command::FAILURE;
        }
        $userId = Row::scalarString($id);

        try {
            $applied = $this->connection->transactional(function () use ($userId, $username, $revoke): bool {
                $before = Row::strings($this->connection->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ? ORDER BY role FOR UPDATE', [$userId]));
                $hasOwner = \in_array(GlobalRole::Owner->value, $before, true);
                if ($revoke === !$hasOwner) {
                    return false; // Already in the desired state; nothing to do.
                }

                if ($revoke) {
                    $this->connection->executeStatement('DELETE FROM user_role WHERE user_id = ? AND role = ?', [$userId, GlobalRole::Owner->value]);
                    $this->assertAdministratorRemains();
                } else {
                    $this->connection->executeStatement(
                        "INSERT INTO user_role (user_id, role, granted_by) VALUES (?, 'OWNER', NULL) ON CONFLICT DO NOTHING",
                        [$userId],
                    );
                }

                $after = $before;
                if ($revoke) {
                    $after = array_values(array_diff($before, [GlobalRole::Owner->value]));
                } else {
                    $after[] = GlobalRole::Owner->value;
                    sort($after);
                }
                $this->audit->record(new AuditEntry('USER_ROLES_CHANGED', AuditActor::system('console'), 'app_user', $userId, [
                    'username' => $username, 'from' => $before, 'to' => $after,
                ]));

                return true;
            });
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$applied) {
            $io->writeln(\sprintf('"%s" is already %s the Owner role; nothing to do.', $username, $revoke ? 'without' : 'with'));

            return Command::SUCCESS;
        }
        $io->success($revoke ? \sprintf('Revoked the Owner role from "%s".', $username) : \sprintf('Granted the Owner role (superuser) to "%s".', $username));

        return Command::SUCCESS;
    }

    /** Refuses a revoke that would leave no active System Administrator or Owner. */
    private function assertAdministratorRemains(): void
    {
        $remains = $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) > 0 FROM app_user u
            WHERE u.is_active AND (
                EXISTS (SELECT 1 FROM user_role ur WHERE ur.user_id = u.id AND ur.role IN (:sysadmin, :owner))
                OR EXISTS (SELECT 1 FROM global_role_mapping m
                           JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
                           WHERE gm.user_id = u.id AND m.role = :sysadmin))
            SQL, ['sysadmin' => GlobalRole::SystemAdministrator->value, 'owner' => GlobalRole::Owner->value]);
        if (true !== $remains) {
            throw new \RuntimeException('Refusing to revoke: at least one active System Administrator or Owner must remain.');
        }
    }
}
