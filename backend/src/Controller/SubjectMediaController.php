<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CivicSubject;
use App\Entity\SubjectMedia;
use App\Entity\User;
use App\Media\ImageProcessor;
use App\Media\InvalidImageException;
use App\Media\LinkUrl;
use App\Media\MediaStorage;
use App\Repository\SubjectMediaRepository;
use App\Security\CivicSubjectVoter;
use App\Subject\MediaStatus;
use App\Subject\PublishingPolicy;
use App\Subject\SubjectVisibility;
use App\Vote\ErasureRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
 * ADR-0011, Step 3 M.1–M.2: fotografiile și linkurile subiectelor.
 * - adaugă/scot doar autorul subiectului și adminii (decizie 15.10.2026); media nu creează revizie (p.2);
 * - autorii sub moderare (ADR-0006) încarcă media PENDING, aprobate individual de admin (p.12);
 * - fișierele se servesc prin X-Accel-Redirect, după aceleași reguli de vizibilitate ca subiectul (p.4).
 * - istoricul public arată ce s-a adăugat și ce s-a scos, fără conținutul celor scoase (p.2).
 * - aceiași (autor, admini) ordonează fotografiile; prima publicată e imaginea de share (og:image, 16.10.2026).
 * Subiectele și media pe care utilizatorul nu le poate vedea răspund 404, ca detaliul subiectului.
 */
final class SubjectMediaController extends AbstractController
{
    public const MAX_PER_SUBJECT = 10;
    public const MAX_LINKS_PER_SUBJECT = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubjectMediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly ErasureRegistry $erasures,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/civic_subjects/{id}/media', methods: ['GET'])]
    public function list(string $id): JsonResponse
    {
        $subject = $this->visibleSubject($id);
        $items = array_map($this->serialize(...), $this->media->findActive($subject, $this->canManage($subject)));

        return $this->noStore($this->json(['items' => $items, 'max' => self::MAX_PER_SUBJECT, 'maxLinks' => self::MAX_LINKS_PER_SUBJECT]));
    }

    #[Route('/api/civic_subjects/{id}/media/history', methods: ['GET'])]
    public function history(string $id): JsonResponse
    {
        $subject = $this->visibleSubject($id);
        $events = [];
        foreach ($this->media->findPublishedEver($subject) as $m) {
            $removed = $m->isRemoved();
            $added = ['event' => 'ADDED', 'kind' => $m->getKind(), 'at' => ($m->getApprovedAt() ?? $m->getCreatedAt()), 'pos' => $m->getPosition()];
            if (!$removed) {
                $added['mediaId'] = $m->getId()->toBase32();
                if (!$m->isPhoto()) {
                    $added += ['url' => $m->getUrl(), 'label' => $m->getLabel()];
                }
            }
            $events[] = $added;
            if ($removed) {
                // Fără URL și fără nume: un link scos pentru date personale nu trebuie să rămână public.
                $by = $m->getRemovedBy();
                $events[] = ['event' => 'REMOVED', 'kind' => $m->getKind(), 'at' => $m->getRemovedAt(), 'pos' => $m->getPosition(),
                    'by' => $by === null ? null : ($subject->isAuthoredBy($by) ? 'AUTHOR' : 'ADMIN')];
            }
        }
        // Momentele au precizie de o secundă: la egalitate, adăugarea înaintea scoaterii, apoi ordinea atașării.
        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['event'] === 'REMOVED', $a['pos']] <=> [$b['at'], $b['event'] === 'REMOVED', $b['pos']]);
        foreach ($events as &$e) {
            $e['at'] = $e['at']->format(\DATE_ATOM);
            unset($e['pos']);
        }
        unset($e);

        return $this->noStore($this->json(['events' => $events]));
    }

    #[Route('/api/civic_subjects/{id}/links', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createLink(string $id, Request $request, PublishingPolicy $policy): JsonResponse
    {
        $subject = $this->visibleSubject($id);
        $this->denyAccessUnlessGranted(CivicSubjectVoter::EDIT, $subject);
        $user = $this->getUser();
        \assert($user instanceof User);

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new UnprocessableEntityHttpException('Corpul cererii trebuie să fie un obiect JSON cu „url”.');
        }
        $url = LinkUrl::normalize($body['url'] ?? null);
        $label = LinkUrl::label($body['label'] ?? null);

        $item = $this->em->wrapInTransaction(function () use ($subject, $user, $policy, $url, $label): SubjectMedia {
            $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            if ($this->media->countActive($subject, SubjectMedia::KIND_LINK) >= self::MAX_LINKS_PER_SUBJECT) {
                throw new UnprocessableEntityHttpException('Un subiect poate avea cel mult '.self::MAX_LINKS_PER_SUBJECT.' linkuri.');
            }
            if ($this->media->hasActiveLink($subject, $url)) {
                throw new UnprocessableEntityHttpException('Linkul este deja atașat subiectului.');
            }
            $status = $policy->canPublish($user) ? MediaStatus::PUBLISHED : MediaStatus::PENDING;
            $item = SubjectMedia::link($subject, $user, $status, $this->media->nextPosition($subject), $url, $label);
            $this->em->persist($item);

            return $item;
        });

        return $this->noStore($this->json($this->serialize($item), 201));
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
                if ($this->media->countActive($subject, SubjectMedia::KIND_PHOTO) >= self::MAX_PER_SUBJECT) {
                    throw new UnprocessableEntityHttpException('Un subiect poate avea cel mult '.self::MAX_PER_SUBJECT.' fotografii.');
                }
                $status = $policy->canPublish($user) ? MediaStatus::PUBLISHED : MediaStatus::PENDING;
                $item = SubjectMedia::photo($subject, $user, $status, $this->media->nextPosition($subject), $image->width, $image->height, \strlen($image->display));
                $this->em->persist($item);
                foreach (['display' => $image->display, 'thumb' => $image->thumb, 'og' => $image->og] as $variant => $bytes) {
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

    /**
     * Noua ordine a fotografiilor: `{ids: [...]}` cu toate fotografiile atașate, inclusiv cele în așteptare.
     * Fotografiile primesc, în noua ordine, aceleași poziții pe care le ocupau: linkurile (aceeași numerotare)
     * rămân pe loc. Fără revizie și fără intrare în istoricul public (ADR-0011 p.2).
     */
    #[Route('/api/civic_subjects/{id}/media/order', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function reorder(string $id, Request $request): JsonResponse
    {
        $subject = $this->visibleSubject($id);
        $this->denyAccessUnlessGranted(CivicSubjectVoter::EDIT, $subject);
        $ids = json_decode($request->getContent(), true)['ids'] ?? null;
        if (!\is_array($ids) || !array_is_list($ids) || array_filter($ids, static fn ($i): bool => !\is_string($i)) !== []) {
            throw new UnprocessableEntityHttpException('Lipsește noua ordine a fotografiilor („ids”).');
        }

        $this->em->wrapInTransaction(function () use ($subject, $ids): void {
            // Sub lock: o fotografie adăugată sau scoasă între timp face lista trimisă incompletă (422, nu ordine pe jumătate).
            $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            $photos = [];
            foreach ($this->media->findActivePhotos($subject) as $photo) {
                $photos[$photo->getId()->toBase32()] = $photo;
            }
            $sorted = $ids;
            sort($sorted);
            $known = array_keys($photos);
            sort($known);
            if ($sorted !== $known) {
                throw new UnprocessableEntityHttpException('Lista fotografiilor s-a schimbat între timp. Reîncarcă pagina.');
            }
            $positions = array_map(static fn (SubjectMedia $p): int => $p->getPosition(), array_values($photos));
            foreach ($ids as $i => $photoId) {
                $photos[$photoId]->moveTo($positions[$i]);
            }
        });

        $items = array_map($this->serialize(...), $this->media->findActive($subject, true));

        return $this->noStore($this->json(['items' => $items]));
    }

    #[Route('/api/media/{id}/{variant}', methods: ['GET'], requirements: ['variant' => 'display|thumb'])]
    public function serve(string $id, string $variant): Response
    {
        $item = $this->visibleMedia($id);
        if (!$item->isPhoto()) {
            throw $this->createNotFoundException('Fotografia nu există.');
        }
        \assert(\in_array($variant, SubjectMedia::VARIANTS, true));

        // Corpul îl trimite nginx din locația internal; aici doar decidem dacă are voie.
        return new Response('', 200, [
            'X-Accel-Redirect' => $this->storage->accelPath($item->storageKey($variant)),
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Imaginea de share (og:image), pe domeniul public: nginx-ul FE trimite `validez.webnou.ro/media/og/` aici
     * (BE-ul are robots Disallow, iar Twitterbot respectă robots.txt). Doar fotografii publicate ale subiectelor
     * publicate; fără token, deci fără excepția pentru autor/admini de la `serve`.
     */
    #[Route('/media/og/{id}.jpg', name: 'media_og', methods: ['GET', 'HEAD'])]
    public function og(string $id): Response
    {
        $item = Ulid::isValid($id) ? $this->media->find(Ulid::fromString($id)) : null;
        if ($item === null || $item->isRemoved() || !$item->isPhoto() || $item->getStatus() !== MediaStatus::PUBLISHED
            || $item->getSubject()->getVisibility() !== SubjectVisibility::PUBLISHED || !$this->storage->exists($item->storageKey('og'))) {
            throw $this->createNotFoundException('Imaginea nu există.');
        }

        // O oră: după scoatere, fotografia nu rămâne mult în cache-uri intermediare.
        return new Response('', 200, [
            'X-Accel-Redirect' => $this->storage->accelPath($item->storageKey('og')),
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
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
        // ADR-0011 p.10: o restaurare dintr-o copie mai veche nu trebuie să readucă media scoasă
        // (app:votes:reapply-erasures o scoate din nou). Ca la retragerea consimțământului, eșecul se loghează.
        try {
            $this->erasures->record($item->getId(), ErasureRegistry::MEDIA_REMOVED, $item->getRemovedAt());
        } catch (\Throwable $e) {
            $this->logger->critical('Scoaterea unei media nu a putut fi notată în registrul de ștergeri (ADR-0011).', ['exception' => $e]);
        }
        // Fișierul dispare de pe disc acum; din copiile de siguranță, după rotația de 30 de zile (ADR-0011 p.10).
        if ($item->isPhoto()) {
            foreach (SubjectMedia::VARIANTS as $variant) {
                $this->storage->delete($item->storageKey($variant));
            }
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
        $common = [
            'id' => $id,
            'subjectId' => $m->getSubject()->getId()->toBase32(),
            'kind' => $m->getKind(),
            'status' => $m->getStatus()->value,
            'position' => $m->getPosition(),
            'createdAt' => $m->getCreatedAt()->format(\DATE_ATOM),
        ];
        if (!$m->isPhoto()) {
            // FE afișează cu rel="nofollow ugc noopener" (ADR-0011 p.8).
            return $common + ['url' => $m->getUrl(), 'label' => $m->getLabel()];
        }

        return $common + [
            'width' => $m->getWidth(),
            'height' => $m->getHeight(),
            'byteSize' => $m->getByteSize(),
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
