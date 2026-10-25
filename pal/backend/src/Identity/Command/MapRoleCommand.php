<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Directory\DirectorySynchronizer;
use App\Identity\Directory\RoleMappingRepository;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pal:roles:map', description: 'Map (or unmap) an Entra group to a global role')]
final class MapRoleCommand extends Command
{
    public function __construct(
        private readonly DirectorySynchronizer $directory,
        private readonly RoleMappingRepository $mappings,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('role', InputArgument::REQUIRED, implode('|', GlobalRole::mappableValues()))
            ->addArgument('group', InputArgument::REQUIRED, 'Entra group object ID')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the mapping instead of adding it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $roleArgument = $input->getArgument('role');
        $groupArgument = $input->getArgument('group');
        $role = \is_string($roleArgument) ? GlobalRole::tryFrom(strtoupper($roleArgument)) : null;
        $objectId = \is_string($groupArgument) ? strtolower($groupArgument) : '';
        if (null === $role || !$role->isMappable()) {
            $io->error('Role must be one of: '.implode(', ', GlobalRole::mappableValues()));

            return Command::INVALID;
        }
        $actor = AuditActor::system('cli:pal:roles:map');

        if ($input->getOption('remove')) {
            foreach ($this->mappings->all() as $mapping) {
                if (Row::string($mapping, 'role') === $role->value && Row::string($mapping, 'idp_object_id') === $objectId) {
                    $this->connection->transactional(function () use ($mapping, $actor): void {
                        $this->mappings->remove(Row::string($mapping, 'id'));
                        $this->audit->record(new AuditEntry('ROLE_MAPPING_REMOVED', $actor, 'global_role_mapping', Row::string($mapping, 'id'), [
                            'role' => $mapping['role'], 'group_object_id' => $mapping['idp_object_id'],
                        ]));
                    });
                    $io->success('Mapping removed.');

                    return Command::SUCCESS;
                }
            }
            $io->warning('No such mapping.');

            return Command::FAILURE;
        }

        $group = $this->directory->trackGroup($objectId, $actor);
        $id = $this->connection->transactional(function () use ($role, $group, $actor, $objectId): ?string {
            $id = $this->mappings->add($role->value, $group['id'], null);
            if (null !== $id) {
                $this->audit->record(new AuditEntry('ROLE_MAPPING_ADDED', $actor, 'global_role_mapping', $id, [
                    'role' => $role->value, 'group_object_id' => $objectId, 'group_display_name' => $group['display_name'],
                ]));
            }

            return $id;
        });
        $result = $this->directory->syncGroup($group['id'], $objectId, $actor);
        $io->success(\sprintf('%s mapped to "%s" (%d members).%s', $role->value, $group['display_name'], $result['members'], null === $id ? ' (already existed)' : ''));

        return Command::SUCCESS;
    }
}
