<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CivicSubject;
use App\Entity\SubjectVoteType;
use App\Entity\VoteResult;
use App\Vote\IntegrityChain;
use App\Vote\VoteResultCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifică consistența datelor de vot (ADR-0007): structura lanțurilor, agregatele față de o recalculare,
 * voturi/istoric fără consimțământ activ, revizia votului aparține subiectului, tipul votului e configurat.
 * Un consimțământ activ pe o versiune veche a informării NU e o problemă: voturile existente rămân valide.
 * Util după un restore din backup și în smoke test. Cod de ieșire 1 dacă găsește probleme.
 */
#[AsCommand(name: 'app:votes:verify', description: 'Verifică lanțurile de integritate, agregatele și datele private de vot.')]
final class VerifyVotesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly IntegrityChain $chain,
        private readonly VoteResultCalculator $results,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problems = [];

        $subjectIds = $this->db->fetchFirstColumn(
            'SELECT id FROM civic_subject WHERE id IN (SELECT subject_id FROM integrity_event_log UNION SELECT subject_id FROM subject_vote_type)'
        );
        foreach ($subjectIds as $id) {
            $subject = $this->em->find(CivicSubject::class, $id);
            $label = $subject->getId()->toBase32();
            if (($broken = $this->chain->firstBrokenSequence($subject)) !== null) {
                $problems[] = "lanț invalid la subiectul $label, secvența $broken";
            }
            $expected = $this->results->compute($subject);
            foreach ($this->em->getRepository(SubjectVoteType::class)->findBy(['subject' => $subject]) as $configured) {
                $code = $configured->getVoteType()->getCode();
                [$counts, $stale] = $expected[$code] ?? [[], 0];
                $stored = $this->em->find(VoteResult::class, ['subject' => $subject->getId(), 'voteType' => $code]);
                $storedCounts = $stored?->getCurrentCounts() ?? [];
                ksort($storedCounts);
                if ($storedCounts !== $counts || ($stored?->getCurrentTotal() ?? 0) !== array_sum($counts) || ($stored?->getStaleTotal() ?? 0) !== $stale) {
                    $problems[] = "agregat diferit de recalculare: subiectul $label, tipul $code";
                }
            }
            $this->em->clear();
        }

        $checks = [
            'voturi fără consimțământ activ' => 'SELECT COUNT(*) FROM vote v WHERE NOT EXISTS (SELECT 1 FROM vote_consent c WHERE c.user_id = v.user_id AND c.withdrawn_at IS NULL)',
            'istoric privat fără consimțământ activ' => 'SELECT COUNT(*) FROM user_vote_history h WHERE NOT EXISTS (SELECT 1 FROM vote_consent c WHERE c.user_id = h.user_id AND c.withdrawn_at IS NULL)',
            'voturi pe o revizie a altui subiect' => 'SELECT COUNT(*) FROM vote v JOIN subject_revision r ON r.id = v.revision_id WHERE r.subject_id <> v.subject_id',
            'voturi pe un tip neconfigurat pe subiect' => 'SELECT COUNT(*) FROM vote v WHERE NOT EXISTS (SELECT 1 FROM subject_vote_type t WHERE t.subject_id = v.subject_id AND t.vote_type_code = v.vote_type_code)',
            'subiecte fără revizia curentă' => 'SELECT COUNT(*) FROM civic_subject s WHERE NOT EXISTS (SELECT 1 FROM subject_revision r WHERE r.subject_id = s.id AND r.number = s.revision_number)',
            'voturi pe subiecte fără first_voted_at' => 'SELECT COUNT(*) FROM vote v JOIN civic_subject s ON s.id = v.subject_id WHERE s.first_voted_at IS NULL',
        ];
        foreach ($checks as $label => $sql) {
            if (($n = (int) $this->db->fetchOne($sql)) > 0) {
                $problems[] = "$label: $n";
            }
        }

        if ($problems !== []) {
            $io->error(\count($problems).' probleme găsite');
            $io->listing($problems);

            return Command::FAILURE;
        }
        $io->success(sprintf('Date de vot consistente (%d subiecte verificate).', \count($subjectIds)));

        return Command::SUCCESS;
    }
}
