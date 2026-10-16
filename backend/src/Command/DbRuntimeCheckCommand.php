<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifică conexiunea Doctrine EFECTIVĂ a aplicației (docs/postgresql-roles.md): cu ce rol rulează PHP-ul
 * și dacă acel rol are exact permisiunile runtime. Rulează în containerul PHP, înainte de publicarea
 * release-ului, urmată de app:votes:verify în același context.
 *
 * Verifică toate tabelele din schema `public`, nu doar cele mapate: o tabelă adăugată după configurarea
 * rolurilor, fără GRANT, e prinsă aici înainte de trafic.
 */
#[AsCommand(name: 'app:db:runtime-check', description: 'Verifică rolul și permisiunile conexiunii Doctrine runtime.')]
final class DbRuntimeCheckCommand extends Command
{
    private const LOG = 'integrity_event_log';
    private const MIGRATIONS = 'doctrine_migration_versions';

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('expect-user', null, InputOption::VALUE_REQUIRED, 'Rolul PostgreSQL așteptat pentru runtime', 'validez_app');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $expected = (string) $input->getOption('expect-user');
        $problems = [];

        $identity = $this->db->fetchAssociative(
            "SELECT current_user AS current_role, session_user AS session_role, current_database() AS db,
                    r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication, r.rolbypassrls,
                    has_database_privilege(current_database(), 'TEMPORARY') AS can_temp,
                    has_schema_privilege('public', 'CREATE') AS can_create,
                    (SELECT COUNT(*) FROM pg_auth_members m WHERE m.member = r.oid) AS memberships
             FROM pg_roles r WHERE r.rolname = current_user"
        );
        $io->writeln(sprintf('Conexiune runtime: rol %s (sesiune %s), baza %s', $identity['current_role'], $identity['session_role'], $identity['db']));

        if ($identity['current_role'] !== $expected || $identity['session_role'] !== $expected) {
            $problems[] = sprintf('rolul conexiunii este %s/%s, nu %s', $identity['session_role'], $identity['current_role'], $expected);
        }
        foreach (['rolsuper' => 'superuser', 'rolcreatedb' => 'CREATEDB', 'rolcreaterole' => 'CREATEROLE', 'rolreplication' => 'REPLICATION', 'rolbypassrls' => 'BYPASSRLS', 'can_temp' => 'TEMPORARY pe bază', 'can_create' => 'CREATE pe schema public'] as $key => $label) {
            if ($identity[$key]) {
                $problems[] = "rolul runtime are $label";
            }
        }
        if ((int) $identity['memberships'] > 0) {
            $problems[] = 'rolul runtime e membru al altor roluri';
        }

        $tables = $this->db->fetchAllAssociative(
            "SELECT c.relname AS name, pg_get_userbyid(c.relowner) AS owner,
                    has_table_privilege(c.oid, 'SELECT') AS s, has_table_privilege(c.oid, 'INSERT') AS i,
                    has_table_privilege(c.oid, 'UPDATE') AS u, has_table_privilege(c.oid, 'DELETE') AS d,
                    has_table_privilege(c.oid, 'TRUNCATE') AS t, has_table_privilege(c.oid, 'TRIGGER') AS tr
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
               AND NOT EXISTS (SELECT 1 FROM pg_depend dep WHERE dep.classid = 'pg_class'::regclass AND dep.objid = c.oid AND dep.deptype = 'e')
             ORDER BY c.relname"
        );
        if (!\in_array(self::LOG, array_column($tables, 'name'), true)) {
            $problems[] = 'tabela '.self::LOG.' lipsește (migrările nu au rulat pe această bază?)';
        }
        foreach ($tables as $t) {
            $name = $t['name'];
            if ($t['owner'] === $identity['current_role']) {
                $problems[] = "rolul runtime deține tabela $name";
            }
            if ($t['t'] || $t['tr']) {
                $problems[] = "TRUNCATE/TRIGGER pe $name";
            }
            [$required, $forbidden] = match ($name) {
                self::LOG => [['s', 'i'], ['u', 'd']],
                self::MIGRATIONS => [['s'], ['i', 'u', 'd']],
                default => [['s', 'i', 'u', 'd'], []],
            };
            foreach ($required as $p) {
                if (!$t[$p]) {
                    $problems[] = sprintf('lipsește %s pe %s', self::LABELS[$p], $name);
                }
            }
            foreach ($forbidden as $p) {
                if ($t[$p]) {
                    $problems[] = sprintf('%s nepermis pe %s', self::LABELS[$p], $name);
                }
            }
        }

        // ADR-0012 p.3: obiectele extensiilor (spatial_ref_sys etc.) sunt doar citite de runtime.
        $extensionObjects = $this->db->fetchAllAssociative(
            "SELECT c.relname AS name,
                    has_table_privilege(c.oid, 'INSERT') OR has_table_privilege(c.oid, 'UPDATE')
                    OR has_table_privilege(c.oid, 'DELETE') OR has_table_privilege(c.oid, 'TRUNCATE')
                    OR has_table_privilege(c.oid, 'TRIGGER') AS writable
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm')
               AND EXISTS (SELECT 1 FROM pg_depend dep WHERE dep.classid = 'pg_class'::regclass AND dep.objid = c.oid AND dep.deptype = 'e')
             ORDER BY c.relname"
        );
        foreach ($extensionObjects as $e) {
            if ($e['writable']) {
                $problems[] = sprintf('scriere permisă pe %s (obiect al unei extensii)', $e['name']);
            }
        }

        if ($problems !== []) {
            $io->error(\count($problems).' probleme pentru conexiunea runtime');
            $io->listing($problems);

            return Command::FAILURE;
        }
        $io->success(sprintf('Conexiunea runtime folosește %s, cu permisiunile așteptate pe %d tabele.', $expected, \count($tables)));

        return Command::SUCCESS;
    }

    private const LABELS = ['s' => 'SELECT', 'i' => 'INSERT', 'u' => 'UPDATE', 'd' => 'DELETE'];
}
