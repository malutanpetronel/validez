<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\CivicSubject;
use App\Entity\SubjectVoteType;
use App\Entity\VoteType;
use App\Vote\DefaultVoteTypes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pinul subiectului (ADR-0011 p.9, p.11). Câmpul `location` din payload urmează regula merge-patch a estimării:
 * lipsă = neschimbat, null = scos, obiect {lat, lng, source?} = pus sau mutat. Se apelează din procesoare,
 * înaintea SubjectRevisions::recordIfChanged, ca mutarea pinului să creeze revizie.
 * Tipul de vot LOCATION („Locul e corect?”) e activ doar cât subiectul are pin.
 */
final class SubjectLocation
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Proprietatea DTO-ului e neinițializată când câmpul lipsește din payload. */
    public static function present(object $data): bool
    {
        return \array_key_exists('location', get_object_vars($data));
    }

    /** @param array<string, mixed>|null $input */
    public function apply(CivicSubject $subject, ?array $input): void
    {
        if ($input === null) {
            $subject->setLocation(null, null, null);
        } else {
            [$lat, $lng, $source] = self::parse($input);
            $subject->setLocation($lat, $lng, $source);
        }
        $this->syncVoteType($subject);
    }

    /** @return array{0: string, 1: string, 2: LocationSource} */
    public static function parse(array $input): array
    {
        $lat = self::coordinate($input['lat'] ?? null, 90.0, 'Latitudinea');
        $lng = self::coordinate($input['lng'] ?? null, 180.0, 'Longitudinea');
        $source = $input['source'] ?? LocationSource::MANUAL->value;
        if (!\is_string($source) || ($source = LocationSource::tryFrom($source)) === null) {
            throw new UnprocessableEntityHttpException('Sursa localizării trebuie să fie MANUAL sau DEVICE.');
        }

        return [$lat, $lng, $source];
    }

    private static function coordinate(mixed $value, float $limit, string $label): string
    {
        if (\is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (!\is_int($value) && !\is_float($value) || !is_finite((float) $value) || abs((float) $value) > $limit) {
            throw new UnprocessableEntityHttpException(\sprintf('%s trebuie să fie un număr între -%d și %d.', $label, (int) $limit, (int) $limit));
        }
        // Aceeași formă ca valoarea DECIMAL(9,6) citită din baza de date: un pin retrimis identic nu face revizie.
        $normalized = \sprintf('%.6f', round((float) $value, 6));

        return $normalized === '-0.000000' ? '0.000000' : $normalized;
    }

    private function syncVoteType(CivicSubject $subject): void
    {
        $configured = $this->em->getRepository(SubjectVoteType::class)->findOneBy(['subject' => $subject, 'voteType' => DefaultVoteTypes::LOCATION]);
        if ($configured !== null) {
            $configured->setActive($subject->hasLocation());
        } elseif ($subject->hasLocation()) {
            $this->em->persist(new SubjectVoteType($subject, $this->em->getReference(VoteType::class, DefaultVoteTypes::LOCATION)));
        }
    }
}
