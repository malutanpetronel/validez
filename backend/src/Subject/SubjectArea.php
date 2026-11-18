<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\AdministrativeUnit;
use App\Entity\CivicSubject;
use App\Geo\PinClassificationState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * ADR-0012 p.4–5 (G.3): aria declarată a subiectului (`scope` + `areaUnits`, coduri SIRUTA). Se apelează din
 * procesoare DUPĂ SubjectLocation (aria efectivă LOCAL include UAT-ul pinului încadrat) și ÎNAINTEA reviziei.
 *
 * Payload (merge-patch): `scope` lipsă = neschimbat; `areaUnits` lipsă = neschimbat (la NATIONAL se golesc).
 * Subiect nou: `scope` obligatoriu. Odată setat, `scope` nu mai revine la NULL. Subiectele vechi (NULL) se pot
 * edita fără arie (decizie 18.11.2026). Validarea contractului rulează la orice salvare cu `scope` setat, deci și
 * la scoaterea pinului de pe un subiect LOCAL fără localitate declarată.
 */
final class SubjectArea
{
    public const MAX_UNITS = 20;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $data proprietățile inițializate ale DTO-ului (get_object_vars) */
    public function apply(CivicSubject $subject, array $data, bool $creating): void
    {
        $hasScope = \array_key_exists('scope', $data);
        $hasUnits = \array_key_exists('areaUnits', $data);
        if ($creating && ($data['scope'] ?? null) === null) {
            throw new UnprocessableEntityHttpException('Alege aria subiectului: locală, județeană sau națională.');
        }
        if ($hasScope || $hasUnits) {
            $this->change($subject, $hasScope, $data['scope'] ?? null, $hasUnits, $data['areaUnits'] ?? null);
        }
        self::assertValid($subject);
    }

    private function change(CivicSubject $subject, bool $hasScope, mixed $rawScope, bool $hasUnits, mixed $rawUnits): void
    {
        if ($hasScope && $rawScope === null) {
            if ($subject->getScope() !== null) {
                throw new UnprocessableEntityHttpException('Aria subiectului nu mai poate fi scoasă; alege Local, Județean sau Național.');
            }
            if ($hasUnits && $rawUnits !== null && $rawUnits !== []) {
                throw new UnprocessableEntityHttpException('Alege întâi aria subiectului.');
            }

            return; // subiect vechi, rămâne nespecificat
        }
        if ($hasScope) {
            $scope = \is_string($rawScope) ? SubjectScope::tryFrom($rawScope) : null;
            if ($scope === null) {
                throw new UnprocessableEntityHttpException('Aria trebuie să fie LOCAL, COUNTY sau NATIONAL.');
            }
        } else {
            $scope = $subject->getScope() ?? throw new UnprocessableEntityHttpException('Alege întâi aria subiectului.');
        }

        if ($hasUnits) {
            $units = $this->load($rawUnits);
        } else {
            $units = $scope === SubjectScope::NATIONAL ? [] : $subject->getAreaUnits();
        }
        $level = $scope->declaredLevel();
        foreach ($units as $unit) {
            if ($unit->getLevel() !== $level) {
                throw new UnprocessableEntityHttpException(match ($scope) {
                    SubjectScope::LOCAL => 'La aria locală se aleg doar localități (UAT-uri).',
                    SubjectScope::COUNTY => 'La aria județeană se aleg doar județe.',
                    SubjectScope::NATIONAL => 'Aria națională nu are unități declarate.',
                });
            }
        }
        $subject->setArea($scope, $units);
    }

    /** @return list<AdministrativeUnit> */
    private function load(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw) || !array_is_list($raw) || array_filter($raw, static fn ($c) => !\is_string($c) || $c === '') !== []) {
            throw new UnprocessableEntityHttpException('Unitățile ariei trebuie să fie o listă de coduri SIRUTA.');
        }
        $codes = array_values(array_unique($raw));
        if (\count($codes) > self::MAX_UNITS) {
            throw new UnprocessableEntityHttpException(\sprintf('Aria poate avea cel mult %d unități.', self::MAX_UNITS));
        }
        $found = [];
        foreach ($codes === [] ? [] : $this->em->getRepository(AdministrativeUnit::class)->findBy(['code' => $codes]) as $unit) {
            $found[$unit->getCode()] = $unit;
        }
        $units = [];
        foreach ($codes as $code) {
            $unit = $found[$code] ?? throw new UnprocessableEntityHttpException("Unitate administrativă necunoscută: $code.");
            if ($unit->getRetiredAt() !== null) {
                throw new UnprocessableEntityHttpException(\sprintf('%s nu mai există în nomenclatorul SIRUTA.', $unit->getDisplayName()));
            }
            $units[] = $unit;
        }

        return $units;
    }

    /** Contractul din ADR-0012 p.5, pe starea finală a subiectului. */
    public static function assertValid(CivicSubject $subject): void
    {
        $scope = $subject->getScope();
        if ($scope === SubjectScope::COUNTY && $subject->getAreaUnits() === []) {
            throw new UnprocessableEntityHttpException('Alege cel puțin un județ pentru aria județeană.');
        }
        if ($scope === SubjectScope::LOCAL && $subject->effectiveArea() === []) {
            $state = $subject->getPinAdminState();
            throw new UnprocessableEntityHttpException(match (true) {
                \in_array($state, [PinClassificationState::AMBIGUOUS, PinClassificationState::UNMATCHED], true)
                    => 'Pinul e lângă limita dintre localități, deci localitatea nu se poate deduce sigur. Adaug-o în «Aria subiectului».',
                $subject->hasLocation() => 'Pinul nu e încadrat într-o localitate din România. Adaugă localitatea în «Aria subiectului».',
                default => 'Adaugă localitatea în «Aria subiectului» (aria locală are nevoie de cel puțin o localitate).',
            });
        }
    }
}
