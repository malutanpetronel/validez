<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CivicSubject;
use App\Entity\SubjectMedia;
use App\Entity\User;
use App\Media\ImageProcessor;
use App\Media\InvalidImageException;
use App\Media\MediaStorage;
use App\Repository\SubjectMediaRepository;
use App\Security\CivicSubjectVoter;
use App\Subject\MediaStatus;
use App\Subject\PublishingPolicy;
use App\Subject\SubjectVisibility;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0011, Step 3 M.1: fotografiile subiectelor.
 * - adaugă/scot doar autorul subiectului și adminii (decizie 15.10.2026); media nu creează revizie (p.2);
 * - autorii sub moderare (ADR-0006) încarcă media PENDING, aprobate individual de admin (p.12);
 * - fișierele se servesc prin X-Accel-Redirect, după aceleași reguli de vizibilitate ca subiectul (p.4).
 * Subiectele și media pe care utilizatorul nu le poate vedea răspund 404, ca detaliul subiectului.
 */
final class SubjectMediaController extends AbstractController
{
    public const MAX_PER_SUBJECT = 10;
    private const VARIANTS = ['display', 'thumb'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubjectMediaRepository $media,
        private readonly MediaStorage $storage,
    ) {
    }

    #[Route('/api/civic_subjects/{id}/media', methods: ['GET'])]
    public function list(string $id): JsonResponse
    {
        $subject = $this->visibleSubject($id);
        $items = array_map($this->serialize(...), $this->media->findActive($subject, $this->canManage($subject)));

        return $this->noStore($this->json(['items' => $items, 'max' => self::MAX_PER_SUBJECT]));
    }

    #[Route('/api/civic_subjects/{id}/media', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function upload(string $id, Request $request, ImageProcessor $processor, PublishingPolicy $policy): JsonResponse
    {
        $subject = $this->visibleSubject($id);
        $this->denyAccessUnlessGranted(CivicSubjectVoter::EDIT, $subject);
        $user = $this->getUser();
        \assert($user instanceof User);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('Lipsește fotografia (câmpul „file”).');
        }
        if (!$file->isValid()) {
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                throw new HttpException(413, 'Fotografia depășește 10 MB.');
            }
            throw new UnprocessableEntityHttpException('Fotografia nu a putut fi încărcată.');
        }
        try {
            // Înainte de tranzacție: re-encodarea durează, iar lock-ul pe subiect trebuie să fie scurt.
            $image = $processor->process($file->getPathname());
        } catch (InvalidImageException $e) {
            throw $e->tooLarge ? new HttpException(413, $e->getMessage()) : new UnprocessableEntityHttpException($e->getMessage());
        }

        $written = [];
        try {
            $item = $this->em->wrapInTransaction(function () use ($subject, $user, $image, $policy, &$written): SubjectMedia {
                // Limita de 10 se numără sub lock: două upload-uri simultane nu pot trece amândouă de a 10-a.
                $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
                if ($this->media->countActive($subject) >= self::MAX_PER_SUBJECT) {
                    throw new UnprocessableEntityHttpException('Un subiect poate avea cel mult '.self::MAX_PER_SUBJECT.' fotografii.');
                }
                $status = $policy->canPublish($user) ? MediaStatus::PUBLISHED : MediaStatus::PENDING;
                $item = new SubjectMedia($subject, $user, $status, $this->media->nextPosition($subject), $image->width, $image->height, \strlen($image->display));
                $this->em->persist($item);
                foreach (['display' => $image->display, 'thumb' => $image->thumb] as $variant => $bytes) {
                    $this->storage->write($item->storageKey($variant), $bytes);
                    $written[] = $item->storageKey($variant);
                }

                return $item;
            });
        } catch (\Throwable $e) {
            foreach ($written as $key) {
                $this->storage->delete($key);
            }
            throw $e;
        }

        return $this->noStore($this->json($this->serialize($item), 201));
    }

    #[Route('/api/media/{id}/{variant}', methods: ['GET'], requirements: ['variant' => 'display|thumb'])]
    public function serve(string $id, string $variant): Response
    {
        $item = $this->visibleMedia($id);
        \assert(\in_array($variant, self::VARIANTS, true));

        // Corpul îl trimite nginx din locația internal; aici doar decidem dacă are voie.
        return new Response('', 200, [
            'X-Accel-Redirect' => $this->storage->accelPath($item->storageKey($variant)),
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    #[Route('/api/media/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function remove(string $id): Response
    {
        $item = $this->visibleMedia($id);
        $this->denyAccessUnlessGranted(CivicSubjectVoter::EDIT, $item->getSubject());
        $user = $this->getUser();
        \assert($user instanceof User);

        $item->markRemoved($user);
        $this->em->flush();
        // Fișierul dispare de pe disc acum; din copiile de siguranță, după rotația de 30 de zile (ADR-0011 p.10).
        foreach (self::VARIANTS as $variant) {
            $this->storage->delete($item->storageKey($variant));
        }

        return new Response(null, 204);
    }

    #[Route('/api/media/{id}/approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(string $id): JsonResponse
    {
        $item = $this->visibleMedia($id);
        if ($item->getStatus() === MediaStatus::PENDING) {
            $item->approve();
            $this->em->flush();
        }

        return $this->noStore($this->json($this->serialize($item)));
    }

    /** Coada de moderare a fotografiilor (#/moderare): subiectul rămâne public, doar media așteaptă. */
    #[Route('/api/moderation/media', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function pending(): JsonResponse
    {
        $items = array_map(fn (SubjectMedia $m): array => $this->serialize($m) + [
            'subjectTitle' => $m->getSubject()->getTitle(),
            'subjectPath' => $m->getSubject()->getCanonicalPath(),
            'uploadedByName' => $m->getUploadedBy()?->getDisplayName(),
        ], $this->media->findPending());

        return $this->noStore($this->json(['items' => $items]));
    }

    private function visibleSubject(string $id): CivicSubject
    {
        $subject = Ulid::isValid($id) ? $this->em->find(CivicSubject::class, Ulid::fromString($id)) : null;
        if ($subject === null || ($subject->getVisibility() !== SubjectVisibility::PUBLISHED && !$this->canManage($subject))) {
            throw $this->createNotFoundException('Subiectul nu există.');
        }

        return $subject;
    }

    private function visibleMedia(string $id): SubjectMedia
    {
        $item = Ulid::isValid($id) ? $this->media->find(Ulid::fromString($id)) : null;
        if ($item === null || $item->isRemoved()) {
            throw $this->createNotFoundException('Fotografia nu există.');
        }
        $subject = $item->getSubject();
        $manager = $this->canManage($subject) || $item->isUploadedBy($this->currentUser());
        if (!$manager && ($subject->getVisibility() !== SubjectVisibility::PUBLISHED || $item->getStatus() !== MediaStatus::PUBLISHED)) {
            throw $this->createNotFoundException('Fotografia nu există.');
        }

        return $item;
    }

    /** Autorul subiectului și adminii: văd și media PENDING, pot scoate. */
    private function canManage(CivicSubject $subject): bool
    {
        $user = $this->currentUser();

        return $user !== null && ($user->isAdmin() || $subject->isAuthoredBy($user));
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, mixed> */
    private function serialize(SubjectMedia $m): array
    {
        $id = $m->getId()->toBase32();

        return [
            'id' => $id,
            'subjectId' => $m->getSubject()->getId()->toBase32(),
            'kind' => $m->getKind(),
            'status' => $m->getStatus()->value,
            'position' => $m->getPosition(),
            'width' => $m->getWidth(),
            'height' => $m->getHeight(),
            'byteSize' => $m->getByteSize(),
            'createdAt' => $m->getCreatedAt()->format(\DATE_ATOM),
            'displayUrl' => "/api/media/$id/display",
            'thumbUrl' => "/api/media/$id/thumb",
        ];
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
