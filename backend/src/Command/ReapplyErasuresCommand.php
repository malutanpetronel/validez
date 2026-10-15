<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Entity\SubjectMedia;
use App\Media\MediaStorage;
use App\Vote\ErasureRegistry;
use App\Vote\VoteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;

/**
 * După restaurarea unei copii a bazei (ADR-0009): reaplică retragerile de consimțământ și scoaterile de media
 * (ADR-0011 p.10) notate în registrul de ștergeri, înainte de redeschiderea traficului. La media scoase șterge și
 * fișierele readuse de copia de media. Idempotentă. Afișează doar totaluri, fără identificatori.
 * Ordinea pe server: restaurare → această comandă → app:votes:verify → pornirea nginx (Deploy.md, secțiunea E2).
 */
#[AsCommand(name: 'app:votes:reapply-erasures', description: 'Reaplică retragerile de consimțământ și scoaterile de media din registrul de ștergeri, după o restaurare.')]
final class ReapplyErasuresCommand extends Command
{
    public function __construct(
        private readonly ErasureRegistry $registry,
        private readonly VoteService $votes,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Arată ce s-ar reaplica, fără să modifice baza.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $entries = $this->registry->entries();
        $applied = $votes = $regranted = $missing = 0;
        $mediaRemoved = $mediaMissing = $filesDeleted = 0;

        foreach ($entries as $entry) {
            if ($entry['action'] === ErasureRegistry::MEDIA_REMOVED) {
                $item = $this->em->find(SubjectMedia::class, Ulid::fromString($entry['media']));
                if ($item === null) {
                    ++$mediaMissing;
                    continue;
                }
                if (!$item->isRemoved()) {
                    ++$mediaRemoved;
                    if (!$dryRun) {
                        $item->markRemoved(null, $entry['at']);
                        $this->em->flush();
                    }
                }
                // Și dacă rândul era deja scos: o copie de media mai nouă decât dump-ul poate avea fișierele.
                if ($item->isPhoto()) {
                    foreach (SubjectMedia::VARIANTS as $variant) {
                        if ($this->storage->exists($item->storageKey($variant))) {
                            ++$filesDeleted;
                            if (!$dryRun) {
                                $this->storage->delete($item->storageKey($variant));
                            }
                        }
                    }
                }
                $this->em->clear();
                continue;
            }
            $user = $this->em->find(User::class, Ulid::fromString($entry['user']));
            if ($user === null) {
                ++$missing;
                continue;
            }
            $result = $this->votes->reapplyConsentWithdrawal($user, $entry['at'], $dryRun);
            if ($result === null) {
                ++$regranted;
            } else {
                ++$applied;
                $votes += $result;
            }
            $this->em->clear();
        }

        $io->definitionList(
            ['Intrări în registru' => (string) \count($entries)],
            [($dryRun ? 'De reaplicat' : 'Reaplicate') => (string) $applied],
            [($dryRun ? 'Voturi de retras' : 'Voturi retrase') => (string) $votes],
            ['Fără nimic de făcut (deja aplicate sau acord dat din nou)' => (string) $regranted],
            ['Utilizatori inexistenți în copia restaurată' => (string) $missing],
            [($dryRun ? 'Media de scos' : 'Media scoase') => (string) $mediaRemoved],
            [($dryRun ? 'Fișiere media de șters' : 'Fișiere media șterse') => (string) $filesDeleted],
            ['Media inexistente în copia restaurată' => (string) $mediaMissing],
        );
        $io->success($dryRun ? 'Simulare: baza nu a fost modificată.' : 'Retragerile din registru sunt aplicate.');

        return Command::SUCCESS;
    }
}
