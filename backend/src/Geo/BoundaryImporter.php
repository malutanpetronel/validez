<?php

declare(strict_types=1);

namespace App\Geo;

use App\Entity\AdministrativeUnit;
use App\Entity\BoundarySet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G.1c-b (ADR-0012 p.2–3): importul limitelor UAT dintr-un artefact local produs de tools/geo/export_ancpi_arcgis.py
 * (paginile Esri JSON + manifest.json + SHA256SUMS). Nu accesează serviciul live.
 *
 * Verificări înainte de orice scriere (prima trecere, pagină cu pagină): sumele de control din SHA256SUMS, manifestul
 * fără probleme, câte o singură geometrie per cod, inelele convertibile, potrivirea EXACTĂ nationalCode → UAT activ,
 * numărul de entități (orice cod fără unitate și, implicit, orice UAT fără poligon opresc importul).
 * A doua trecere, într-o singură tranzacție, scrie geometriile. Paginile se citesc pe rând (o pagină decodată
 * ocupă zeci de MB), fără a ține tot exportul în memorie.
 *
 * Geometria: `geom_source` = punctele sursei, neschimbate, în SRID-ul declarat; `geom` = ST_Transform în WGS84 cu
 * definiția din spatial_ref_sys, înregistrată textual în BoundarySet.transformation. Doar dacă rezultatul e invalid se
 * repară (ST_MakeValid), cu repaired = true; originalul rămâne în geom_source.
 *
 * Setul nou e UNVERIFIED și inactiv; același artefact (SHA-256 al manifestului) nu creează un set nou.
 */
final class BoundaryImporter
{
    private const INSERT = 'WITH s AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:j), :srid) AS g), t AS (SELECT g, ST_Transform(g, 4326) AS w FROM s)
        INSERT INTO administrative_boundary (boundary_set_id, administrative_unit_id, source_srid, geom_source, geom, repaired)
        SELECT :set, :unit, :srid, g, CASE WHEN ST_IsValid(w) THEN w ELSE ST_Multi(ST_CollectionExtract(ST_MakeValid(w), 3)) END,
               NOT (ST_IsValid(g) AND ST_IsValid(w)) FROM t';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{status: string, set: ?BoundarySet, features: int, repaired: int, missing: list<string>, manifestSha256: string}
     */
    public function import(string $dir, int $sourceSrid, bool $allowMissing, bool $dryRun): array
    {
        $dir = rtrim($dir, '/');
        $manifestPath = "$dir/manifest.json";
        $manifestSha = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : throw new \RuntimeException("Lipsește $manifestPath.");
        $checked = $this->verifyChecksums($dir);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, \JSON_THROW_ON_ERROR);
        $unchecked = array_diff(['manifest.json', ...array_column($manifest['pages'] ?? [], 'file')], $checked);
        if ($unchecked !== []) {
            throw new \RuntimeException('Fișiere fără sumă de control în SHA256SUMS: '.implode(', ', $unchecked));
        }
        if (($manifest['problems'] ?? null) !== []) {
            throw new \RuntimeException('Manifestul exportului are probleme înregistrate: '.json_encode($manifest['problems'] ?? 'lipsă', \JSON_UNESCAPED_UNICODE));
        }
        if (\count($manifest['data_version_ids'] ?? []) !== 1) {
            throw new \RuntimeException('Exportul trebuie să aibă o singură versiune de date.');
        }

        $existing = $this->em->getRepository(BoundarySet::class)->findOneBy(['artifactChecksum' => $manifestSha]);
        if ($existing !== null) {
            return ['status' => 'deja importat', 'set' => $existing, 'features' => $existing->getUnitCount(), 'repaired' => 0, 'missing' => [], 'manifestSha256' => $manifestSha];
        }

        /** @var array<string, string> $uats cod → id */
        $uats = [];
        $names = [];
        foreach ($this->em->getRepository(AdministrativeUnit::class)->findBy(['level' => AdministrativeLevel::UAT, 'retiredAt' => null]) as $u) {
            $uats[$u->getCode()] = $u->getId()->toRfc4122();
            $names[$u->getCode()] = $u->getOfficialName();
        }
        if ($uats === []) {
            throw new \RuntimeException('Nu există UAT-uri: rulează întâi app:geo:import-siruta.');
        }

        // Prima trecere: doar verificări.
        $seen = [];
        foreach ($this->features($dir, $manifest) as $code => $rings) {
            if (isset($seen[$code])) {
                throw new \RuntimeException("Codul $code apare de două ori în export.");
            }
            if (!isset($uats[$code])) {
                throw new \RuntimeException("Codul $code nu corespunde niciunui UAT activ.");
            }
            EsriPolygon::toMultiPolygon($rings);
            $seen[$code] = true;
        }
        if (\count($seen) !== (int) $manifest['feature_count']) {
            throw new \RuntimeException(sprintf('Paginile au %d entități, manifestul %d.', \count($seen), $manifest['feature_count']));
        }
        $missing = array_values(array_map(fn (string $code) => "$code {$names[$code]}", array_keys(array_diff_key($uats, $seen))));
        if ($missing !== [] && !$allowMissing) {
            throw new \RuntimeException(\count($missing).' UAT-uri fără poligon: '.implode(', ', \array_slice($missing, 0, 10)));
        }

        $conn = $this->em->getConnection();
        $transformation = sprintf('PostGIS ST_Transform(SRID %d → 4326); spatial_ref_sys %d: %s; %s', $sourceSrid, $sourceSrid,
            trim((string) $conn->fetchOne('SELECT proj4text FROM spatial_ref_sys WHERE srid = ?', [$sourceSrid])),
            (string) $conn->fetchOne('SELECT postgis_full_version()'));
        $sr = $manifest['spatial_reference'] ?? [];
        $set = new BoundarySet((string) $manifest['source'], (string) $manifest['layer_url'], (string) $manifest['license'],
            (string) $manifest['data_version_ids'][0], $manifestSha, (string) ($sr['wkt'] ?? json_encode($sr)), $sourceSrid, $transformation);
        $set->setUnitCount(\count($seen));

        // A doua trecere: scrierea, într-o tranzacție.
        $conn->beginTransaction();
        try {
            $this->em->persist($set);
            $this->em->flush();
            $setId = $set->getId()->toRfc4122();
            foreach ($this->features($dir, $manifest) as $code => $rings) {
                $conn->executeStatement(self::INSERT, ['j' => json_encode(['type' => 'MultiPolygon', 'coordinates' => EsriPolygon::toMultiPolygon($rings)], \JSON_THROW_ON_ERROR),
                    'srid' => $sourceSrid, 'set' => $setId, 'unit' => $uats[$code]]);
            }
            $repaired = (int) $conn->fetchOne('SELECT COUNT(*) FROM administrative_boundary WHERE boundary_set_id = ? AND repaired', [$setId]);
            $dryRun ? $conn->rollBack() : $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->em->clear();
            throw $e;
        } finally {
            if ($dryRun) {
                $this->em->clear();
            }
        }

        return ['status' => $dryRun ? 'verificat (dry-run, nimic scris)' : 'importat', 'set' => $dryRun ? null : $set,
            'features' => \count($seen), 'repaired' => $repaired, 'missing' => $missing, 'manifestSha256' => $manifestSha];
    }

    /**
     * Entitățile exportului, pagină cu pagină (o singură pagină decodată în memorie).
     *
     * @return \Generator<string, list<list<array{0: float|int, 1: float|int}>>> cod → inele Esri
     */
    private function features(string $dir, array $manifest): \Generator
    {
        foreach ($manifest['pages'] as $page) {
            $data = json_decode((string) file_get_contents("$dir/{$page['file']}"), true, 512, \JSON_THROW_ON_ERROR);
            foreach ($data['features'] as $i => $f) {
                yield (string) $f['attributes']['nationalCode'] => $f['geometry']['rings'] ?? [];
                unset($data['features'][$i]);
            }
            unset($data);
        }
    }

    /** @return list<string> fișierele verificate */
    private function verifyChecksums(string $dir): array
    {
        $sums = @file("$dir/SHA256SUMS", \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: throw new \RuntimeException("Lipsește $dir/SHA256SUMS.");
        $checked = [];
        foreach ($sums as $line) {
            if (!preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $m)) {
                throw new \RuntimeException("Linie SHA256SUMS invalidă: $line");
            }
            if (hash_file('sha256', "$dir/{$m[2]}") !== $m[1]) {
                throw new \RuntimeException("Suma de control nu se potrivește pentru {$m[2]}.");
            }
            $checked[] = $m[2];
        }

        return $checked;
    }
}
