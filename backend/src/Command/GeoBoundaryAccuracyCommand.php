<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BoundarySet;
use App\Geo\BoundaryAccuracyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0012 p.2, p.6: înregistrează verificarea preciziei unui set de limite (status, precizie, D, justificare) și,
 * opțional, îl activează — în aceeași tranzacție cu dezactivarea setului activ anterior.
 */
#[AsCommand(name: 'app:geo:boundary-accuracy', description: 'Înregistrează precizia unui set de limite și, opțional, îl activează.')]
final class GeoBoundaryAccuracyCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('set', InputArgument::REQUIRED, 'ID-ul setului (ULID) sau SHA-256 al artefactului')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'UNVERIFIED | PROVISIONAL | ACCEPTED | REJECTED')
            ->addOption('accuracy', null, InputOption::VALUE_REQUIRED, 'Precizia pozițională (m)')
            ->addOption('distance', null, InputOption::VALUE_REQUIRED, 'Distanța de incertitudine D (m)')
            ->addOption('justification', null, InputOption::VALUE_REQUIRED, 'Justificarea (sursele preciziei și ale lui D)')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activează setul (dezactivează setul activ anterior)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getArgument('set');
        $repo = $this->em->getRepository(BoundarySet::class);
        $set = Ulid::isValid($key) ? $repo->find(Ulid::fromString($key)) : $repo->findOneBy(['artifactChecksum' => strtolower($key)]);
        $status = BoundaryAccuracyStatus::tryFrom(strtoupper((string) $input->getOption('status')));
        if ($set === null || $status === null) {
            $io->error($set === null ? "Setul $key nu există." : 'Status necunoscut.');

            return Command::FAILURE;
        }

        $this->em->wrapInTransaction(function () use ($set, $status, $input, $repo): void {
            $set->recordAccuracy($status, $input->getOption('accuracy'), $input->getOption('distance'), (string) $input->getOption('justification'));
            if ($input->getOption('activate')) {
                foreach ($repo->findBy(['deactivatedAt' => null]) as $other) {
                    if ($other !== $set && $other->isActive()) {
                        $other->deactivate();
                        $this->em->flush(); // întâi dezactivarea: indexul unic parțial permite un singur set activ
                    }
                }
                $set->activate();
            }
        });

        $io->success(sprintf('Setul %s: %s, precizie %s m, D %s m, %s.', $set->getId()->toBase32(), $set->getAccuracyStatus()->value,
            $set->getAccuracyMeters() ?? '—', $set->getUncertaintyDistanceMeters() ?? '—', $set->isActive() ? 'ACTIV' : 'inactiv'));

        return Command::SUCCESS;
    }
}
