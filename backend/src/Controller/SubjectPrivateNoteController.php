<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CivicSubject;
use App\Entity\SubjectPrivateNote;
use App\Entity\User;
use App\Subject\SubjectType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_USER')]
final class SubjectPrivateNoteController extends AbstractController
{
    /** Includes preserved notes on non-proposal subjects for personal-data export/deletion. */
    #[Route('/api/me/subject-notes', methods: ['GET', 'DELETE'])]
    public function personalData(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        if ($request->isMethod('DELETE')) {
            $em->createQuery('DELETE FROM App\Entity\SubjectPrivateNote n WHERE IDENTITY(n.author) = :author')
                ->setParameter('author', $user->getId(), UlidType::NAME)->execute();
            $data = ['notes' => []];
        } else {
            $rows = $em->createQuery('SELECT s.id AS subjectId, s.title AS title, s.type AS type, n.text AS technicalNotes FROM App\Entity\SubjectPrivateNote n JOIN n.subject s WHERE IDENTITY(n.author) = :author ORDER BY s.createdAt DESC')
                ->setParameter('author', $user->getId(), UlidType::NAME)->getArrayResult();
            foreach ($rows as &$row) {
                $row['subjectId'] = (string) $row['subjectId'];
                if ($row['type'] instanceof SubjectType) $row['type'] = $row['type']->value;
            }
            unset($row);
            $data = ['notes' => $rows];
        }
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/api/civic_subjects/{id}/note', methods: ['GET', 'PUT', 'DELETE'])]
    public function __invoke(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $subject = Ulid::isValid($id) ? $em->find(CivicSubject::class, Ulid::fromString($id)) : null;
        $user = $this->getUser();
        // Do not reveal hidden subjects or note existence to anyone other than the author.
        if (!$subject || !$user instanceof User || !$subject->isAuthoredBy($user) || $subject->getType() !== SubjectType::PROPOSAL) {
            throw $this->createNotFoundException('Nota nu este disponibilă.');
        }
        $note = $em->getRepository(SubjectPrivateNote::class)->findOneBy(['subject' => $subject]);
        if ($request->isMethod('PUT')) {
            $body = $request->toArray();
            if (!isset($body['technicalNotes']) || !is_string($body['technicalNotes']) || mb_strlen($body['technicalNotes']) > 10000) {
                throw new UnprocessableEntityHttpException('Notele trebuie să fie text de maximum 10000 de caractere.');
            }
            $text = trim($body['technicalNotes']);
            if ($text === '') {
                if ($note) $em->remove($note);
                $note = null;
            } elseif ($note) {
                $note->setText($text);
            } else {
                $note = new SubjectPrivateNote($subject, $text);
                $em->persist($note);
            }
            $em->flush();
        } elseif ($request->isMethod('DELETE')) {
            if ($note) { $em->remove($note); $em->flush(); }
            $note = null;
        }
        $response = $this->json(['technicalNotes' => $note?->getText() ?? '']);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
