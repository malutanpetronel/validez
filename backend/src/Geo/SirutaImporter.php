<?php

declare(strict_types=1);

namespace App\Geo;

use App\Entity\AdministrativeUnit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G.1c-a (ADR-0012 p.2): importul nomenclatorului SIRUTA (CSV INS, separator `;`, UTF-8 cu BOM) în administrative_unit.
 *
 * - Județe: TIP 40. UAT-uri: TIP 1–6 (6 = sectoarele Bucureștiului). Localitățile componente și satele nu se importă.
 * - Părintele unui UAT e județul cu același JUD. SIRSUP se păstrează ca proveniență; la UAT-urile care nu sunt
 *   sectoare trebuie să fie chiar județul, altfel importul se oprește (structură neașteptată, fără ghicit).
 * - Idempotent, după codul SIRUTA: o denumire schimbată se actualizează; o unitate absentă din nomenclator primește
 *   retiredAt (rândul rămâne, pentru legături). Schimbarea nivelului, a TIP-ului sau a județului unei unități
 *   existente oprește importul: cere o decizie, nu o actualizare automată.
 * - Țara `RO` (fără SIRUTA) se creează dacă lipsește.
 */
final class SirutaImporter
{
    private const COUNTY_TYPE = 40;
    private const UAT_TYPES = [1, 2, 3, 4, 5, 6];
    private const SECTOR_TYPE = 6;
    private const REQUIRED_COLUMNS = ['SIRUTA', 'DENLOC', 'JUD', 'SIRSUP', 'TIP'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{counts: array<string, int>, renamed: list<string>, retired: list<string>}
     */
    public function import(string $file, string $version, bool $dryRun): array
    {
        [$counties, $uats] = $this->read($file);

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $report = $this->apply($counties, $uats, $version);
            $this->em->flush();
            $dryRun ? $conn->rollBack() : $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->em->clear(); // entitățile programate pentru inserare nu trebuie să ajungă la un flush ulterior
            throw $e;
        } finally {
            if ($dryRun) {
                $this->em->clear();
            }
        }

        return $report;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} județe și UAT-uri, după SIRUTA */
    private function read(string $file): array
    {
        $h = fopen($file, 'rb');
        if ($h === false) {
            throw new \RuntimeException("Nu pot citi $file.");
        }
        try {
            $header = fgetcsv($h, null, ';', '"', '');
            if ($header === false || $header === [null]) {
                throw new \RuntimeException('Fișier SIRUTA gol.');
            }
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
            $header = array_map(fn ($c) => trim((string) $c), $header);
            $missing = array_diff(self::REQUIRED_COLUMNS, $header);
            if ($missing !== []) {
                throw new \RuntimeException('Coloane lipsă în SIRUTA: '.implode(', ', $missing));
            }
            $counties = $uats = [];
            $line = 1;
            while (($row = fgetcsv($h, null, ';', '"', '')) !== false) {
                ++$line;
                if ($row === [null]) {
                    continue;
                }
                if (\count($row) !== \count($header)) {
                    throw new \RuntimeException("Linia $line are ".\count($row).' coloane, antetul '.\count($header).'.');
                }
                $r = array_combine($header, $row);
                $tip = (int) $r['TIP'];
                if ($tip !== self::COUNTY_TYPE && !\in_array($tip, self::UAT_TYPES, true)) {
                    continue;
                }
                $item = ['siruta' => $this->int($r['SIRUTA'], $line), 'name' => rtrim((string) $r['DENLOC'], "\r\n"),
                    'jud' => $this->int($r['JUD'], $line), 'sirsup' => $this->int($r['SIRSUP'], $line), 'tip' => $tip];
                if (isset($counties[$item['siruta']]) || isset($uats[$item['siruta']])) {
                    throw new \RuntimeException("SIRUTA {$item['siruta']} apare de două ori (linia $line).");
                }
                if ($tip === self::COUNTY_TYPE) {
                    $counties[$item['siruta']] = $item;
                } else {
                    $uats[$item['siruta']] = $item;
                }
            }
        } finally {
            fclose($h);
        }
        if ($counties === [] || $uats === []) {
            throw new \RuntimeException('Fișierul nu conține județe și UAT-uri.');
        }

        return [$counties, $uats];
    }

    private function int(string $value, int $line): int
    {
        if (!preg_match('/^\d+$/', trim($value))) {
            throw new \RuntimeException("Valoare nenumerică „{$value}” la linia $line.");
        }

        return (int) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $counties
     * @param array<int, array<string, mixed>> $uats
     *
     * @return array{counts: array<string, int>, renamed: list<string>, retired: list<string>}
     */
    private function apply(array $counties, array $uats, string $version): array
    {
        $existing = [];
        $country = null;
        foreach ($this->em->getRepository(AdministrativeUnit::class)->findAll() as $u) {
            if ($u->getLevel() === AdministrativeLevel::COUNTRY && $u->getCode() === AdministrativeUnit::COUNTRY_CODE) {
                $country = $u;
            } elseif ($u->getSiruta() !== null) {
                $existing[$u->getSiruta()] = $u;
            }
        }
        $counts = ['country_created' => 0, 'counties' => \count($counties), 'uats' => \count($uats), 'sectors' => 0,
            'created' => 0, 'renamed' => 0, 'reconfirmed' => 0, 'unchanged' => 0, 'retired' => 0];
        $renamed = $retired = [];
        if ($country === null) {
            $country = AdministrativeUnit::country('România');
            $this->em->persist($country);
            $counts['country_created'] = 1;
        }

        $byJud = [];
        foreach ($counties as $c) {
            if (isset($byJud[$c['jud']])) {
                throw new \RuntimeException("Două județe cu JUD {$c['jud']}.");
            }
            $byJud[$c['jud']] = $this->upsert($existing, AdministrativeLevel::COUNTY, $c, $country, $version, $counts, $renamed);
        }
        foreach ($uats as $u) {
            $county = $byJud[$u['jud']] ?? throw new \RuntimeException("UAT {$u['siruta']} ({$u['name']}): nu există județ cu JUD {$u['jud']}.");
            if ($u['tip'] === self::SECTOR_TYPE) {
                ++$counts['sectors'];
            } elseif ($u['sirsup'] !== $county->getSiruta()) {
                throw new \RuntimeException("UAT {$u['siruta']} ({$u['name']}): SIRSUP {$u['sirsup']} diferă de județul {$county->getSiruta()}.");
            }
            $this->upsert($existing, AdministrativeLevel::UAT, $u, $county, $version, $counts, $renamed);
        }

        $present = $counties + $uats;
        foreach ($existing as $siruta => $unit) {
            if (!isset($present[$siruta]) && $unit->getRetiredAt() === null) {
                $unit->retire();
                ++$counts['retired'];
                $retired[] = "$siruta {$unit->getOfficialName()}";
            }
        }

        return ['counts' => $counts, 'renamed' => $renamed, 'retired' => $retired];
    }

    /**
     * @param array<int, AdministrativeUnit> $existing
     * @param array<string, mixed>           $item
     * @param array<string, int>             $counts
     * @param list<string>                   $renamed
     */
    private function upsert(array &$existing, AdministrativeLevel $level, array $item, AdministrativeUnit $parent, string $version, array &$counts, array &$renamed): AdministrativeUnit
    {
        $unit = $existing[$item['siruta']] ?? null;
        if ($unit === null) {
            $unit = $level === AdministrativeLevel::COUNTY
                ? AdministrativeUnit::county($parent, $item['siruta'], $item['tip'], $item['name'], $item['sirsup'], $version)
                : AdministrativeUnit::uat($parent, $item['siruta'], $item['tip'], $item['name'], $item['sirsup'], $version);
            $this->em->persist($unit);
            $existing[$item['siruta']] = $unit;
            ++$counts['created'];

            return $unit;
        }
        if ($unit->getLevel() !== $level || $unit->getSirutaType() !== $item['tip'] || $unit->getParent() !== $parent) {
            throw new \RuntimeException("SIRUTA {$item['siruta']} ({$item['name']}): nivelul, TIP-ul sau județul s-a schimbat față de baza de date; cere o decizie, nu se actualizează automat.");
        }
        if ($unit->getOfficialName() !== $item['name']) {
            $renamed[] = "{$item['siruta']}: {$unit->getOfficialName()} → {$item['name']}";
            $unit->rename($item['name']);
            ++$counts['renamed'];
        } elseif ($unit->getNomenclatureVersion() === $version && $unit->getRetiredAt() === null) {
            ++$counts['unchanged'];
        } else {
            ++$counts['reconfirmed'];
        }
        $unit->confirmIn($version);
        $unit->setSirutaParentCode($item['sirsup']);

        return $unit;
    }
}
