<?php

declare(strict_types=1);

namespace App\Command;

use App\Geo\SirutaImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** G.1c-a (ADR-0012 p.2): importul nomenclatorului SIRUTA în administrative_unit. Vezi App\Geo\SirutaImporter. */
#[AsCommand(name: 'app:geo:import-siruta', description: 'Importă județele și UAT-urile din nomenclatorul SIRUTA (CSV INS).')]
final class GeoImportSirutaCommand extends Command
{
    public function __construct(private readonly SirutaImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Fișierul CSV SIRUTA (de ex. var/geo/siruta_s1_2026.csv)')
            // nu `--version`: e opțiunea globală a consolei Symfony (afișează versiunea și iese)
            ->addOption('nomenclature-version', null, InputOption::VALUE_REQUIRED, 'Versiunea nomenclatorului, de ex. SIRUTA_s1_2026')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'SHA-256 așteptat al fișierului (din SHA256SUMS-ul descărcării)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verifică și raportează, fără să scrie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $version = (string) $input->getOption('nomenclature-version');
        $expected = strtolower((string) $input->getOption('sha256'));
        if ($version === '' || !preg_match('/^[0-9a-f]{64}$/', $expected)) {
            $io->error('Sunt obligatorii --nomenclature-version și --sha256 (64 de caractere hex).');

            return Command::INVALID;
        }
        $actual = is_file($file) ? hash_file('sha256', $file) : false;
        if ($actual !== $expected) {
            $io->error(sprintf('Suma de control a %s nu se potrivește (%s).', $file, $actual ?: 'fișier lipsă'));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $report = $this->importer->import($file, $version, $dryRun);
        $io->title(($dryRun ? '[dry-run] ' : '').'SIRUTA '.$version.' — sha256 '.$actual);
        $io->table(['indicator', 'valoare'], array_map(fn ($k, $v) => [$k, $v], array_keys($report['counts']), $report['counts']));
        if ($report['renamed'] !== []) {
            $io->section('Denumiri schimbate');
            $io->listing($report['renamed']);
        }
        if ($report['retired'] !== []) {
            $io->section('Unități absente din nomenclator (retiredAt)');
            $io->listing($report['retired']);
        }
        $io->success($dryRun ? 'Verificare încheiată; nimic scris.' : 'Import încheiat.');

        return Command::SUCCESS;
    }
}
