<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * G.1c-b: transformă un poligon Esri JSON (`rings`) în coordonatele unui MultiPolygon GeoJSON, fără să modifice
 * vreun punct. Regula Esri: inelele exterioare sunt în sens orar (arie cu semn negativă, cu axa y în sus), golurile
 * în sens trigonometric. Fiecare gol aparține celui mai mic inel exterior care îl conține. Un gol fără exterior
 * sau un inel degenerat opresc conversia (nu se ghicește).
 */
final class EsriPolygon
{
    /**
     * @param list<list<array{0: float|int, 1: float|int}>> $rings
     *
     * @return list<list<list<array{0: float|int, 1: float|int}>>> coordonatele MultiPolygon (poligon → inele → puncte)
     */
    public static function toMultiPolygon(array $rings): array
    {
        if ($rings === []) {
            throw new \InvalidArgumentException('Poligon fără inele.');
        }
        $exteriors = $holes = [];
        foreach ($rings as $i => $ring) {
            if (\count($ring) < 4 || $ring[0] != end($ring)) {
                throw new \InvalidArgumentException("Inelul $i nu e închis sau are sub 4 puncte.");
            }
            $area = self::signedArea($ring);
            if ($area == 0.0) {
                throw new \InvalidArgumentException("Inelul $i are arie zero.");
            }
            if ($area < 0) {
                $exteriors[] = ['ring' => $ring, 'area' => -$area, 'holes' => []];
            } else {
                $holes[] = $ring;
            }
        }
        if ($exteriors === []) {
            throw new \InvalidArgumentException('Poligon fără inel exterior (sens orar).');
        }
        foreach ($holes as $j => $hole) {
            $owner = null;
            foreach ($exteriors as $k => $ext) {
                if (self::contains($ext['ring'], $hole[0]) && ($owner === null || $ext['area'] < $exteriors[$owner]['area'])) {
                    $owner = $k;
                }
            }
            if ($owner === null) {
                throw new \InvalidArgumentException("Golul $j nu e în niciun inel exterior.");
            }
            $exteriors[$owner]['holes'][] = $hole;
        }

        return array_map(fn (array $e) => [$e['ring'], ...$e['holes']], $exteriors);
    }

    /** Aria cu semn (formula trapezelor): pozitivă în sens trigonometric. */
    private static function signedArea(array $ring): float
    {
        $sum = 0.0;
        for ($i = 0, $n = \count($ring) - 1; $i < $n; ++$i) {
            $sum += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }

        return $sum / 2;
    }

    /** Punct în poligon (raza orizontală); primul punct al unui gol e în interiorul exteriorului său. */
    private static function contains(array $ring, array $p): bool
    {
        $inside = false;
        for ($i = 0, $j = \count($ring) - 1; $i < \count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $p[1]) !== ($yj > $p[1]) && $p[0] < ($xj - $xi) * ($p[1] - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
