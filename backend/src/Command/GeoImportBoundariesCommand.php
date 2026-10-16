<?php

declare(strict_types=1);

namespace App\Command;

use App\Geo\BoundaryImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** G.1c-b (ADR-0012): importul limitelor UAT dintr-un artefact local, ca set UNVERIFIED și inactiv. Vezi App\Geo\BoundaryImporter. */
#[AsCommand(name: 'app:geo:import-boundaries', description: 'Importă limitele UAT dintr-un export local (Esri JSON + manifest), ca set neverificat.')]
final class GeoImportBoundariesCommand extends Command
{
    public function __construct(private readonly BoundaryImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('dir', InputArgument::REQUIRED, 'Directorul exportului (de ex. var/geo/arcgis-20261016)')
            ->addOption('source-srid', null, InputOption::VALUE_REQUIRED, 'SRID-ul cadrului declarat de sursă', '3844')
            ->addOption('allow-missing', null, InputOption::VALUE_NONE, 'Acceptă UAT-uri fără poligon (implicit importul se oprește)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verifică și construiește geometriile, fără să scrie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // O pagină a exportului (~10 MB JSON, ~280.000 de vârfuri) decodată în PHP depășește limita implicită de 128 MB.
        // Rulează și cu --no-debug: în debug, profilerul Doctrine păstrează parametrii (GeoJSON) tuturor interogărilor.
        ini_set('memory_limit', '1024M');
        $dryRun = (bool) $input->getOption('dry-run');
        $r = $this->importer->import((string) $input->getArgument('dir'), (int) $input->getOption('source-srid'), (bool) $input->getOption('allow-missing'), $dryRun);

        $io->title(($dryRun ? '[dry-run] ' : '').'Limite — '.$r['status']);
        $io->definitionList(
            ['manifest sha256' => $r['manifestSha256']],
            ['entități' => $r['features']],
            ['reparate (ST_MakeValid)' => $r['repaired']],
            ['UAT-uri fără poligon' => \count($r['missing'])],
            ['set' => $r['set']?->getId()->toBase32() ?? '—'],
            ['precizie' => $r['set']?->getAccuracyStatus()->value ?? '—'],
        );
        if ($r['missing'] !== []) {
            $io->listing(\array_slice($r['missing'], 0, 50));
        }
        $io->success($dryRun ? 'Verificare încheiată; nimic scris.' : 'Setul rămâne neverificat și inactiv până la app:geo:boundary-accuracy.');

        return Command::SUCCESS;
    }
}
