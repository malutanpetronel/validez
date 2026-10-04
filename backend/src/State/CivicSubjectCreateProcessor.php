<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CivicSubjectCreateInput;
use App\Entity\CivicSubject;
use App\Entity\User;
use App\Repository\TreeNodeRepository;
use App\Subject\SubjectType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Autorul = utilizatorul autentificat (niciodată din payload). Tipurile doar-admin -> 403 pentru ceilalți.
 *
 * @implements ProcessorInterface<CivicSubjectCreateInput, CivicSubject>
 */
final class CivicSubjectCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TreeNodeRepository $nodes,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CivicSubject
    {
        \assert($data instanceof CivicSubjectCreateInput);
        $author = $this->security->getUser();
        if (!$author instanceof User) {
            throw new AccessDeniedHttpException('Autentificare necesară.');
        }

        $type = SubjectType::from($data->type);
        if ($type->isAdminOnly() && !$author->isAdmin()) {
            throw new AccessDeniedHttpException('Doar administratorii pot crea subiecte de acest tip.');
        }

        $node = $this->nodes->find(Ulid::fromString($data->node)) ?? throw new UnprocessableEntityHttpException('Nodul nu există.');

        $subject = new CivicSubject($node, $author, $type, $data->title, $data->description);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }
}
