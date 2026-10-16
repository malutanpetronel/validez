<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SubjectMedia;
use App\Media\ImageProcessor;
use App\Media\InvalidImageException;
use App\Media\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Varianta `og` (og:image, JPEG 1200×630) pentru fotografiile încărcate înainte de 16.10.2026, generată din
 * varianta de afișare de pe disc. Se rulează o dată după deploy; repetată, sare peste cele care au deja varianta.
 * Atinge doar fotografiile atașate (nu și pe cele scoase, ale căror fișiere nu mai există).
 */
#[AsCommand(name: 'app:media:og-backfill', description: 'Generează imaginea de share (og) pentru fotografiile care nu o au.')]
final class MediaOgBackfillCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Doar numără, fără să scrie.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var list<SubjectMedia> $photos */
        $photos = $this->em->createQueryBuilder()->select('m')->from(SubjectMedia::class, 'm')
            ->where('m.kind = :photo')->andWhere('m.removedAt IS NULL')
            ->setParameter('photo', SubjectMedia::KIND_PHOTO)->getQuery()->getResult();

        $done = $present = 0;
        $failed = [];
        foreach ($photos as $photo) {
            if ($this->storage->exists($photo->storageKey('og'))) {
                ++$present;
                continue;
            }
            if ($dryRun) {
                ++$done;
                continue;
            }
            try {
                $this->storage->write($photo->storageKey('og'), $this->processor->ogFromDisplay($this->storage->read($photo->storageKey('display'))));
                ++$done;
            } catch (InvalidImageException|FilesystemException $e) {
                $failed[] = $photo->getId()->toBase32().': '.$e->getMessage();
            }
        }

        $io->writeln(\sprintf('%s: %d, aveau deja: %d, eșuate: %d', $dryRun ? 'De generat' : 'Generate', $done, $present, \count($failed)));
        if ($failed !== []) {
            $io->error($failed);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
