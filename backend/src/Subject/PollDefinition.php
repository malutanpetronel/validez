<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\CivicSubject;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Variantele și data de închidere ale unui sondaj (ADR-0013). Se apelează din procesoarele de creare/editare,
 * după lockuri. Câmpurile DTO neinițializate = lipsă din payload.
 */
final class PollDefinition
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 20;
    public const MAX_LABEL = 120;

    /** @param array<string, mixed> $payload get_object_vars(DTO): doar câmpurile prezente */
    public static function applyOnCreate(CivicSubject $subject, array $payload): void
    {
        if (!$subject->isPoll()) {
            self::rejectPollFields($payload);

            return;
        }
        if (!isset($payload['pollOptions'], $payload['pollClosesAt'])) {
            throw new UnprocessableEntityHttpException('Un sondaj are nevoie de variante de răspuns și de data de închidere.');
        }
        $subject->setPollOptions(self::normalizeOptions($payload['pollOptions']));
        $subject->setPollClosesAt(self::futureDate($payload['pollClosesAt']));
    }

    /**
     * Înaintea modificărilor celorlalte câmpuri. Blocarea conținutului după primul vot se verifică separat,
     * pe instantaneu (assertUnlockedContent), ca să prindă orice câmp de revizie.
     *
     * @param array<string, mixed> $payload
     */
    public static function applyOnUpdate(CivicSubject $subject, array $payload, ?SubjectType $requestedType): void
    {
        if ($requestedType !== null && $requestedType !== $subject->getType()
            && ($requestedType === SubjectType::POLL || $subject->isPoll())) {
            throw new UnprocessableEntityHttpException('Un sondaj nu se poate transforma în alt tip de subiect și nici invers.');
        }
        if (!$subject->isPoll()) {
            self::rejectPollFields($payload);

            return;
        }
        if (\array_key_exists('pollOptions', $payload)) {
            $subject->setPollOptions(self::normalizeOptions($payload['pollOptions']));
        }
        if (\array_key_exists('pollClosesAt', $payload)) {
            if ($subject->isPollClosed()) {
                throw new ConflictHttpException('Sondajul este închis; data de închidere nu se mai poate schimba.');
            }
            $subject->setPollClosesAt(self::futureDate($payload['pollClosesAt']));
        }
    }

    /**
     * ADR-0013 p.4: după primul vot, nimic din instantaneul reviziei unui sondaj nu se mai schimbă.
     *
     * @param array<string, mixed> $before instantaneul de dinaintea modificărilor
     */
    public static function assertUnlockedContent(CivicSubject $subject, array $before): void
    {
        if ($subject->isPoll() && $subject->isTypeLocked() && SubjectRevisions::snapshot($subject) !== $before) {
            throw new ConflictHttpException('Întrebarea și variantele sondajului nu se mai pot schimba după primul vot. Închide sondajul și creează altul.');
        }
    }

    /** @return list<string> */
    public static function normalizeOptions(mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new UnprocessableEntityHttpException('Variantele de răspuns trebuie trimise ca listă.');
        }
        $options = [];
        $seen = [];
        foreach ($raw as $label) {
            if (!\is_string($label)) {
                throw new UnprocessableEntityHttpException('Fiecare variantă de răspuns trebuie să fie text.');
            }
            $label = trim((string) preg_replace('/\s+/u', ' ', $label));
            if ($label === '') {
                throw new UnprocessableEntityHttpException('O variantă de răspuns nu poate fi goală.');
            }
            if (mb_strlen($label) > self::MAX_LABEL) {
                throw new UnprocessableEntityHttpException(sprintf('O variantă de răspuns are cel mult %d de caractere.', self::MAX_LABEL));
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                throw new UnprocessableEntityHttpException(sprintf('Varianta „%s” apare de două ori.', $label));
            }
            $seen[$key] = true;
            $options[] = $label;
        }
        if (\count($options) < self::MIN_OPTIONS || \count($options) > self::MAX_OPTIONS) {
            throw new UnprocessableEntityHttpException(sprintf('Un sondaj are între %d și %d variante de răspuns.', self::MIN_OPTIONS, self::MAX_OPTIONS));
        }

        return $options;
    }

    /** @param array<string, mixed> $payload */
    private static function rejectPollFields(array $payload): void
    {
        if (($payload['pollOptions'] ?? null) !== null || ($payload['pollClosesAt'] ?? null) !== null) {
            throw new UnprocessableEntityHttpException('Variantele de răspuns și data de închidere există doar la sondaje.');
        }
    }

    private static function futureDate(mixed $raw): \DateTimeImmutable
    {
        // Cu sau fără milisecunde (toISOString din browser); fusul orar e obligatoriu, inclusiv „Z”.
        $date = \is_string($raw)
            ? (\DateTimeImmutable::createFromFormat(\DATE_ATOM, $raw) ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $raw))
            : false;
        if ($date === false) {
            throw new UnprocessableEntityHttpException('Data de închidere trebuie să fie o dată cu fus orar (ISO 8601).');
        }
        if ($date <= new \DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('Data de închidere trebuie să fie în viitor.');
        }

        return $date;
    }
}
