<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CivicSubjectUpdateInput;
use App\Entity\CivicSubject;
use App\Entity\User;
use App\Repository\CivicSubjectRepository;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\CostEstimate;
use App\Subject\SubjectVisibility;
use App\Subject\PublishingPolicy;
use App\Subject\SubjectRevisions;
use App\Seo\SlugRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Dreptul de editare e verificat înainte (SUBJECT_EDIT: admin sau autor). Aici, restricțiile pe câmpuri:
 * stage/visibility doar adminul; un utilizator nu poate trece subiectul într-un tip doar-admin.
 *
 * @implements ProcessorInterface<CivicSubjectUpdateInput, CivicSubject>
 */
final class CivicSubjectUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CivicSubjectRepository $subjects,
        private readonly Security $security,
        private readonly PublishingPolicy $policy,
        private readonly SubjectRevisions $revisions,
        private readonly SlugRegistry $slugs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CivicSubject
    {
        \assert($data instanceof CivicSubjectUpdateInput);
        $user = $this->security->getUser();
        $isAdmin = $user instanceof User && $user->isAdmin();

        $id = $uriVariables['id'] ?? null;
        $subject = $id === null ? null : $this->subjects->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
        if ($subject === null) {
            throw new NotFoundHttpException('Subiectul nu există.');
        }

        if (!$isAdmin && ($data->stage !== null || $data->visibility !== null)) {
            throw new AccessDeniedHttpException('Doar administratorii pot schimba stadiul sau vizibilitatea.');
        }
        $type = $data->type === null ? null : SubjectType::from($data->type);
        if (!$isAdmin && $type?->isAdminOnly()) {
            throw new AccessDeniedHttpException('Doar administratorii pot folosi acest tip.');
        }

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Autentificare necesară.');
        }

        return $this->em->wrapInTransaction(function () use ($subject, $data, $type, $isAdmin, $user): CivicSubject {
            $this->policy->lock($subject->getAuthor());
            $this->em->refresh($subject, LockMode::PESSIMISTIC_WRITE);
            // După lock: starea citită aici e cea față de care se decide revizia și blocarea tipului.
            $before = SubjectRevisions::snapshot($subject);
            $wasPublished = $subject->getVisibility() === SubjectVisibility::PUBLISHED;
            SubjectRevisions::assertTypeChangeAllowed($subject, $type);
            if ($isAdmin && $data->visibility === SubjectVisibility::PUBLISHED->value && $subject->getVisibility() !== SubjectVisibility::PUBLISHED) {
                $subject->markApprovedContribution();
            }
            // An author under moderation cannot replace already approved public content unchecked.
            $contentChanged = $data->title !== null || $data->description !== null || $data->type !== null
                || array_intersect(['costEstimate', 'costCurrency', 'costEstimateScope'], array_keys(get_object_vars($data))) !== [];
            if ($contentChanged && !$isAdmin && !$this->policy->canPublish($subject->getAuthor()) && $subject->getVisibility() === SubjectVisibility::PUBLISHED) {
                $subject->moderate(null, SubjectVisibility::PENDING);
            }
            CostEstimate::apply($subject, get_object_vars($data), $type ?? $subject->getType());
            $subject->edit($data->title, $data->description, $type);
            if ($data->title !== null) {
                // ADR-0008: slug nou după titlu; cel vechi rămâne valid (301) dacă subiectul era public.
                $this->slugs->afterTitleChange($subject, $wasPublished);
            }
            $subject->moderate(
                $data->stage === null ? null : SubjectStage::from($data->stage),
                $data->visibility === null ? null : SubjectVisibility::from($data->visibility),
            );
            $this->revisions->recordIfChanged($subject, $before, $user);
            $this->em->flush();

            return $subject;
        });
    }
}
