<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdministrativeUnit;
use App\Entity\CivicSubject;
use App\Entity\User;
use App\Geo\AdministrativeLevel;
use App\Security\CivicSubjectVoter;
use App\Subject\SubjectVisibility;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * G.2b (ADR-0012 p.6): rezolvarea manuală a încadrării pinului și coada de moderare a încadrărilor nedeterminate.
 * Alegerea o fac autorul subiectului sau un administrator, doar dintre candidați, doar la AMBIGUOUS/UNMATCHED.
 * Nu creează revizie (pinul nu se mută); mutarea pinului anulează alegerea.
 */
final class PinClassificationController extends AbstractController
{
    private const OPEN_STATES = ['AMBIGUOUS', 'UNMATCHED', 'OUTSIDE'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/civic_subjects/{id}/pin-classification', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resolve(string $id, Request $request): JsonResponse
    {
        $code = json_decode($request->getContent(), true)['code'] ?? null;
        if (!\is_string($code) || !preg_match('/^\d+$/', $code)) {
            return $this->noStore($this->json(['reason' => 'invalid_body'], 422));
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $subject = $this->visibleSubject($id);
            // aceeași ordine de lock ca editarea subiectului: rândul subiectului, înainte de citirea stării
            $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($subject);
            if (!$this->isGranted(CivicSubjectVoter::EDIT, $subject)) {
                $conn->rollBack();

                return $this->noStore($this->json(['reason' => 'forbidden'], 403));
            }
            $unit = $this->em->getRepository(AdministrativeUnit::class)->findOneBy(['code' => $code, 'level' => AdministrativeLevel::UAT, 'retiredAt' => null]);
            try {
                if ($unit === null) {
                    throw new \DomainException('not_a_candidate');
                }
                $subject->resolvePinClassification($unit, $this->user());
            } catch (\DomainException $e) {
                $conn->rollBack();

                return $this->noStore($this->json(['reason' => $e->getMessage()], $e->getMessage() === 'not_resolvable' ? 409 : 422));
            }
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $this->noStore($this->json(['administrative' => $subject->getPinAdministrative()]));
    }

    /** Coada de moderare (#/moderare): pinii cu încadrare nedeterminată, cei mai vechi primii. */
    #[Route('/api/moderation/pin-classifications', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function pending(): JsonResponse
    {
        $subjects = $this->em->createQuery('SELECT s FROM '.CivicSubject::class.' s WHERE s.pinAdminState IN (:open) ORDER BY s.pinAdminComputedAt ASC')
            ->setParameter('open', self::OPEN_STATES)->setMaxResults(200)->getResult();
        $items = array_map(fn (CivicSubject $s): array => [
            'subjectId' => $s->getId()->toBase32(),
            'subjectTitle' => $s->getTitle(),
            'subjectPath' => $s->getCanonicalPath(),
            'visibility' => $s->getVisibility()->value,
            'location' => ['lat' => $s->getLocation()['lat'], 'lng' => $s->getLocation()['lng']],
            'administrative' => $s->getPinAdministrative(),
        ], $subjects);

        return $this->noStore($this->json(['items' => $items]));
    }

    private function visibleSubject(string $id): CivicSubject
    {
        $subject = Ulid::isValid($id) ? $this->em->find(CivicSubject::class, Ulid::fromString($id)) : null;
        if ($subject === null || ($subject->getVisibility() !== SubjectVisibility::PUBLISHED && !$this->isGranted(CivicSubjectVoter::EDIT, $subject))) {
            throw $this->createNotFoundException('Subiectul nu există.');
        }

        return $subject;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
