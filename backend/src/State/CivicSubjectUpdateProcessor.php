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
use App\Subject\SubjectVisibility;
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

        $subject->edit($data->title, $data->description, $type);
        $subject->moderate(
            $data->stage === null ? null : SubjectStage::from($data->stage),
            $data->visibility === null ? null : SubjectVisibility::from($data->visibility),
        );
        $this->em->flush();

        return $subject;
    }
}
