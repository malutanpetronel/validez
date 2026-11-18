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
 * Varianta `card` (WebP 1200 px, coperta din liste și din pagina subiectului; audit viteză 18.11.2026) pentru
 * fotografiile urcate înainte de ea, din varianta de afișare de pe disc. Se rulează o dată după deploy; repetată,
 * sare peste cele care o au. Până atunci, /api/media/{id}/card servește varianta de afișare.
 */
#[AsCommand(name: 'app:media:card-backfill', description: 'Generează coperta comprimată (card) pentru fotografiile care nu o au.')]
final class MediaCardBackfillCommand extends Command
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
            if ($this->storage->exists($photo->storageKey('card'))) {
                ++$present;
                continue;
            }
            if ($dryRun) {
                ++$done;
                continue;
            }
            try {
                $this->storage->write($photo->storageKey('card'), $this->processor->cardFromDisplay($this->storage->read($photo->storageKey('display'))));
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
