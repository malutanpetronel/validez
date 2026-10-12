<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CivicSubject;
use App\Entity\IntegrityEventLog;
use App\Entity\SubjectRevision;
use App\Entity\SubjectVoteType;
use App\Entity\User;
use App\Entity\UserVoteHistory;
use App\Entity\Vote;
use App\Entity\VoteResult;
use App\Subject\SubjectVisibility;
use App\Vote\IntegrityChain;
use App\Vote\VoteException;
use App\Vote\VoteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * API-ul de vot (Step 4.b4, ADR-0007).
 *
 * Vizibilitatea e cea a detaliului subiectului: PUBLISHED pentru toți, altfel doar admin și autor (404 pentru ceilalți).
 * Excepție: retragerea propriului vot e permisă și pe un subiect devenit invizibil, dar răspunsul confirmă doar
 * operația — faptul că utilizatorul vedea subiectul nu-i dă acces la starea lui actuală.
 *
 * Răspunsurile cu date personale sunt `private, no-store`. Contoarele sunt obiecte JSON ({"0": n, "1": m}).
 */
final class VoteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VoteService $votes,
        private readonly RateLimiterFactoryInterface $voteWriteLimiter,
    ) {
    }

    #[Route('/api/civic_subjects/{id}/votes', methods: ['GET'])]
    public function summary(string $id): JsonResponse
    {
        $viewer = $this->viewer();
        $subject = $this->visibleSubject($id, $viewer);

        return $this->respond($this->summaryOf($subject, $viewer), $viewer !== null);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/api/civic_subjects/{id}/votes/{type}', methods: ['PUT'])]
    public function cast(string $id, string $type, Request $request): JsonResponse
    {
        $user = $this->user();
        if ($limited = $this->rateLimited($user)) {
            return $limited;
        }
        $subject = $this->visibleSubject($id, $user);
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !\is_int($body['value'] ?? null)) {
            return $this->error(422, 'invalid_body', 'Corpul cererii trebuie să conțină „value” ca număr întreg.');
        }
        try {
            $action = $this->votes->cast($user, $subject, $type, $body['value']);
        } catch (VoteException $e) {
            return $this->error($e->httpStatus(), $e->reason, $e->getMessage());
        }

        return $this->respond(['action' => $action?->value] + $this->summaryOf($subject, $user), true);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/api/civic_subjects/{id}/votes/{type}', methods: ['DELETE'])]
    public function withdraw(string $id, string $type): JsonResponse
    {
        $user = $this->user();
        if ($limited = $this->rateLimited($user)) {
            return $limited;
        }
        $subject = $this->findSubject($id);
        $ownVote = $subject === null ? null : $this->em->getRepository(Vote::class)->findOneBy(['user' => $user, 'subject' => $subject, 'voteType' => $type]);
        if ($subject === null || (!$this->canView($subject, $user) && $ownVote === null)) {
            throw $this->createNotFoundException('Subiectul nu există sau nu e vizibil.');
        }

        return $this->respond(['withdrawn' => $this->votes->withdraw($user, $subject, $type)], true);
    }

    #[Route('/api/civic_subjects/{id}/revisions', methods: ['GET'])]
    public function revisions(string $id): JsonResponse
    {
        $subject = $this->visibleSubject($id, $this->viewer());
        $revisions = $this->em->getRepository(SubjectRevision::class)->findBy(['subject' => $subject], ['number' => 'ASC']);

        return $this->respond(['currentRevision' => $subject->getRevisionNumber(), 'revisions' => array_map(static fn (SubjectRevision $r): array => [
            'number' => $r->getNumber(),
            'type' => $r->getType()->value,
            'title' => $r->getTitle(),
            'description' => $r->getDescription(),
            'estimate' => $r->publicEstimate(),
            'byAuthor' => $r->getCreatedBy()->getId()->equals($subject->getAuthor()->getId()),
            'createdAt' => $r->getCreatedAt()->format(\DATE_ATOM),
        ], $revisions)], false);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/api/me/vote-consent', methods: ['GET'])]
    public function consent(): JsonResponse
    {
        return $this->respond($this->votes->consentState($this->user()), true);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/api/me/vote-consent', methods: ['PUT'])]
    public function grantConsent(Request $request): JsonResponse
    {
        $user = $this->user();
        if ($limited = $this->rateLimited($user)) {
            return $limited;
        }
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !\is_string($body['noticeVersion'] ?? null)) {
            return $this->error(422, 'invalid_body', 'Corpul cererii trebuie să conțină „noticeVersion”.');
        }
        try {
            $this->votes->grantConsent($user, $body['noticeVersion']);
        } catch (VoteException $e) {
            return $this->error($e->httpStatus(), $e->reason, $e->getMessage());
        }

        return $this->respond($this->votes->consentState($user), true);
    }

    /** Fără limitare: retragerea consimțământului trebuie să rămână accesibilă și după atingerea limitei de vot. */
    #[IsGranted('ROLE_USER')]
    #[Route('/api/me/vote-consent', methods: ['DELETE'])]
    public function withdrawConsent(): JsonResponse
    {
        $user = $this->user();
        $withdrawn = $this->votes->withdrawConsent($user);

        return $this->respond(['withdrawnVotes' => $withdrawn] + $this->votes->consentState($user), true);
    }

    /**
     * Exportul propriu: voturile curente și istoricul privat, cu tot ce trebuie pentru a verifica fiecare commitment
     * fără acces la evenimentele altora: versiunea formatului, câmpurile canonice în ordine, nonce-ul și commitmentul.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/api/me/votes', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $user = $this->user();
        $votes = array_map(static fn (Vote $v): array => [
            'subjectId' => $v->getSubject()->getId()->toBase32(),
            'voteType' => $v->getVoteType()->getCode(),
            'value' => $v->getValue(),
            'revisionNumber' => $v->getRevision()->getNumber(),
            'current' => $v->getRevision()->getNumber() === $v->getSubject()->getRevisionNumber(),
        ], $this->em->getRepository(Vote::class)->findBy(['user' => $user]));

        $history = array_map(static function (UserVoteHistory $h): array {
            $event = $h->getIntegrityEvent();
            $subject = $event->getSubject();
            $fields = IntegrityChain::privateContent(
                $subject,
                $h->getVoteType()->getCode(),
                $h->getRevisionBefore()?->getNumber(),
                $h->getValueBefore(),
                $h->getRevisionAfter()?->getNumber(),
                $h->getValueAfter(),
            );

            return [
                'subjectId' => $subject->getId()->toBase32(),
                'voteType' => $h->getVoteType()->getCode(),
                'action' => $h->getAction()->value,
                'valueBefore' => $h->getValueBefore(),
                'valueAfter' => $h->getValueAfter(),
                'revisionBefore' => $h->getRevisionBefore()?->getNumber(),
                'revisionAfter' => $h->getRevisionAfter()?->getNumber(),
                'createdAt' => $h->getCreatedAt()->format(\DATE_ATOM),
                'commitment' => [
                    'domain' => IntegrityChain::COMMITMENT_DOMAIN,
                    'fields' => $fields,
                    'nonce' => bin2hex($h->getNonce()),
                    'commitment' => bin2hex($event->getCommitment()),
                ],
                'event' => [
                    'formatVersion' => $event->getFormatVersion(),
                    'sequenceNumber' => $event->getSequenceNumber(),
                    'eventHash' => bin2hex($event->getEventHash()),
                ],
            ];
        }, $this->em->getRepository(UserVoteHistory::class)->findBy(['user' => $user], ['createdAt' => 'ASC', 'id' => 'ASC']));

        return $this->respond([
            'format' => [
                'hash' => 'SHA-256',
                'encoding' => 'Domeniul, apoi câmpurile în ordine, apoi nonce-ul; fiecare element: 0x00 pentru null, altfel 0x01 + lungimea pe 4 octeți big-endian + octeții (text UTF-8, numerele în zecimal; nonce-ul ca 32 de octeți bruți).',
                'fieldOrder' => ['subjectId (RFC 4122)', 'voteType', 'revisionBefore', 'valueBefore', 'revisionAfter', 'valueAfter'],
                'eventFormatVersion' => IntegrityEventLog::FORMAT_VERSION,
            ],
            'consent' => $this->votes->consentState($user),
            'votes' => $votes,
            'history' => $history,
        ], true);
    }

    /** @return array<string, mixed> */
    private function summaryOf(CivicSubject $subject, ?User $viewer): array
    {
        $configured = $this->em->getRepository(SubjectVoteType::class)->findBy(['subject' => $subject]);
        usort($configured, static fn (SubjectVoteType $a, SubjectVoteType $b): int => strcmp($a->getVoteType()->getCode(), $b->getVoteType()->getCode()));
        $mine = [];
        if ($viewer !== null) {
            foreach ($this->em->getRepository(Vote::class)->findBy(['user' => $viewer, 'subject' => $subject]) as $vote) {
                $mine[$vote->getVoteType()->getCode()] = $vote;
            }
        }
        $types = [];
        foreach ($configured as $config) {
            $type = $config->getVoteType();
            $result = $this->em->find(VoteResult::class, ['subject' => $subject->getId(), 'voteType' => $type->getCode()]);
            $own = $mine[$type->getCode()] ?? null;
            $types[] = [
                'code' => $type->getCode(),
                'minValue' => $type->getMinValue(),
                'maxValue' => $type->getMaxValue(),
                'active' => $config->isActive(),
                'result' => [
                    'counts' => (object) ($result?->getCurrentCounts() ?? []),
                    'total' => $result?->getCurrentTotal() ?? 0,
                    'staleTotal' => $result?->getStaleTotal() ?? 0,
                ],
                'mine' => $viewer === null || $own === null ? null : [
                    'value' => $own->getValue(),
                    'revisionNumber' => $own->getRevision()->getNumber(),
                    'current' => $own->getRevision()->getNumber() === $subject->getRevisionNumber(),
                ],
            ];
        }

        return [
            'subjectId' => $subject->getId()->toBase32(),
            'revisionNumber' => $subject->getRevisionNumber(),
            'frozen' => VoteService::isFrozen($subject),
            'votingOpen' => $subject->getVisibility() === SubjectVisibility::PUBLISHED && !VoteService::isFrozen($subject),
            'types' => $types,
            'consent' => $viewer === null ? null : $this->votes->consentState($viewer),
        ];
    }

    private function viewer(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function findSubject(string $id): ?CivicSubject
    {
        return Ulid::isValid($id) ? $this->em->find(CivicSubject::class, Ulid::fromString($id)) : null;
    }

    private function canView(CivicSubject $subject, ?User $viewer): bool
    {
        return $subject->getVisibility() === SubjectVisibility::PUBLISHED || ($viewer?->isAdmin() ?? false) || $subject->isAuthoredBy($viewer);
    }

    private function visibleSubject(string $id, ?User $viewer): CivicSubject
    {
        $subject = $this->findSubject($id);
        if ($subject === null || !$this->canView($subject, $viewer)) {
            throw $this->createNotFoundException('Subiectul nu există sau nu e vizibil.');
        }

        return $subject;
    }

    private function rateLimited(User $user): ?JsonResponse
    {
        $limit = $this->voteWriteLimiter->create($user->getId()->toBase32())->consume();
        if ($limit->isAccepted()) {
            return null;
        }
        $response = $this->error(429, 'rate_limited', 'Prea multe operații de vot într-un minut. Reîncearcă puțin mai târziu.');
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }

    private function error(int $status, string $reason, string $message): JsonResponse
    {
        $response = new JsonResponse(['reason' => $reason, 'message' => $message], $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, bool $private): JsonResponse
    {
        $response = new JsonResponse($data);
        if ($private) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
