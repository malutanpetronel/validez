<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CivicSubject;
use App\Geo\PinClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * G.2 (ADR-0012 p.6): încadrarea pinilor existenți față de setul activ — pentru subiectele cu pin încă neîncadrate
 * (după primul import de limite). Nu atinge încadrările existente; recalcularea la un set nou e G.4.
 */
#[AsCommand(name: 'app:geo:classify-pins', description: 'Încadrează administrativ pinii subiectelor încă neîncadrate.')]
final class GeoClassifyPinsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly PinClassifier $classifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calculează și raportează, fără să scrie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->classifier->activeSet() === null) {
            $io->warning('Nu există un set de limite activ; nimic de încadrat.');

            return Command::SUCCESS;
        }
        $subjects = $this->em->getRepository(CivicSubject::class)->createQueryBuilder('s')
            ->where('s.locationLat IS NOT NULL AND s.pinAdminState IS NULL')->getQuery()->getResult();
        $counts = [];
        foreach ($subjects as $subject) {
            $this->classifier->classify($subject);
            $state = $subject->getPinAdminState()?->value ?? '—';
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        if (!$input->getOption('dry-run')) {
            $this->em->flush();
        }
        ksort($counts);
        $io->table(['stare', 'subiecte'], array_map(fn ($k, $v) => [$k, $v], array_keys($counts), $counts));
        $io->success(\sprintf('%d subiecte %s.', \count($subjects), $input->getOption('dry-run') ? 'evaluate (dry-run, nimic scris)' : 'încadrate'));

        return Command::SUCCESS;
    }
}
