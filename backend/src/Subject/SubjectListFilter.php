<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\AdministrativeUnit;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Filtrele din pagina principală care se aplică la fel pe listă (CivicSubjectRepository) și pe bulinele din arbore
 * (TreeNodeRepository): fragment SQL pe aliasul `s` (civic_subject) cu parametri poziționali.
 *
 * - area (strict, în jos — decizie 20.11.2026):
 *     RO        = doar subiectele cu aria NATIONAL;
 *     cod județ = aria COUNTY cu județul declarat + aria LOCAL cu o localitate a județului (declarată sau din pin);
 *     cod UAT   = aria LOCAL cu localitatea în aria efectivă (declarată sau UAT-ul pinului încadrat).
 *   Subiectele fără arie (vechi, scope NULL) nu apar când filtrul e activ.
 * - q: text în titlu sau descriere, fără majuscule și diacritice (ca la căutarea unităților).
 */
final class SubjectListFilter
{
    private const PIN_DETERMINED = "s.pin_admin_state IN ('RESOLVED', 'RESOLVED_MANUALLY')";
    private const MAX_QUERY = 100;

    private function __construct(
        private readonly ?string $areaLevel,
        private readonly ?string $areaUnitId,
        private readonly ?string $like,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function fromFilters(array $filters, Connection $db): self
    {
        [$level, $unitId] = self::resolveArea($filters['area'] ?? null, $db);

        return new self($level, $unitId, self::likePattern($filters['q'] ?? null));
    }

    public function isEmpty(): bool
    {
        return $this->areaLevel === null && $this->like === null;
    }

    /** @return array{0: string, 1: list<string>} fragment care începe cu „ AND ” (sau gol) + parametrii lui, în ordine */
    public function sql(): array
    {
        $sql = '';
        $params = [];
        if ($this->areaLevel === 'COUNTRY') {
            $sql .= " AND s.scope = 'NATIONAL'";
        } elseif ($this->areaLevel === 'COUNTY') {
            $sql .= " AND ((s.scope = 'COUNTY' AND EXISTS (SELECT 1 FROM subject_administrative_unit sau
                                WHERE sau.subject_id = s.id AND sau.unit_id = ?))
                        OR (s.scope = 'LOCAL' AND (EXISTS (SELECT 1 FROM subject_administrative_unit sau
                                JOIN administrative_unit u ON u.id = sau.unit_id
                                WHERE sau.subject_id = s.id AND u.parent_id = ?)
                            OR (".self::PIN_DETERMINED.' AND EXISTS (SELECT 1 FROM administrative_unit pu
                                WHERE pu.id = s.pin_admin_unit_id AND pu.parent_id = ?)))))';
            array_push($params, $this->areaUnitId, $this->areaUnitId, $this->areaUnitId);
        } elseif ($this->areaLevel === 'UAT') {
            $sql .= " AND s.scope = 'LOCAL' AND (EXISTS (SELECT 1 FROM subject_administrative_unit sau
                                WHERE sau.subject_id = s.id AND sau.unit_id = ?)
                            OR (".self::PIN_DETERMINED.' AND s.pin_admin_unit_id = ?))';
            array_push($params, $this->areaUnitId, $this->areaUnitId);
        }
        if ($this->like !== null) {
            $sql .= ' AND ('.self::fold('s.title').' LIKE ? OR '.self::fold('s.description').' LIKE ?)';
            array_push($params, $this->like, $this->like);
        }

        return [$sql, $params];
    }

    private static function fold(string $column): string
    {
        return "translate(lower($column), 'ăâîșşțţ', 'aaissttt')";
    }

    /** @return array{0: ?string, 1: ?string} [nivel, id unitate RFC 4122] */
    private static function resolveArea(mixed $raw, Connection $db): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }
        if (!\is_string($raw) || \strlen($raw) > 16) {
            throw new BadRequestHttpException('Zonă necunoscută.');
        }
        if ($raw === AdministrativeUnit::COUNTRY_CODE) {
            return ['COUNTRY', null];
        }
        $row = $db->fetchAssociative(
            "SELECT id::text AS id, level FROM administrative_unit WHERE code = ? AND level IN ('COUNTY', 'UAT')",
            [$raw],
        );

        return $row === false ? throw new BadRequestHttpException('Zonă necunoscută.') : [(string) $row['level'], (string) $row['id']];
    }

    private static function likePattern(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!\is_string($raw) || mb_strlen($raw) > self::MAX_QUERY) {
            throw new BadRequestHttpException('Căutare prea lungă.');
        }
        $q = trim($raw);
        if (mb_strlen($q) < 2) {
            return null;
        }
        $needle = strtr(mb_strtolower($q), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return '%'.addcslashes($needle, '%_\\').'%';
    }
}
