<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\Geo\EsriPolygon;
use PHPUnit\Framework\TestCase;

/** G.1c-b: inelele Esri (exterior în sens orar, goluri trigonometric) → MultiPolygon, fără modificarea punctelor. */
final class EsriPolygonTest extends TestCase
{
    // sens orar (exterior Esri) și trigonometric (gol)
    private const OUTER = [[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]];
    private const HOLE = [[2, 2], [4, 2], [4, 4], [2, 4], [2, 2]];
    private const ISLAND = [[20, 0], [20, 5], [25, 5], [25, 0], [20, 0]];

    public function testHoleGoesToTheExteriorThatContainsIt(): void
    {
        self::assertSame([[self::OUTER, self::HOLE], [self::ISLAND]], EsriPolygon::toMultiPolygon([self::OUTER, self::ISLAND, self::HOLE]));
    }

    public function testNestedIslandInsideAHoleBelongsToTheSmallestContainingExterior(): void
    {
        $bigHole = [[1, 1], [9, 1], [9, 9], [1, 9], [1, 1]];
        $islandInHole = [[3, 3], [3, 7], [7, 7], [7, 3], [3, 3]];
        $innerHole = [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]];
        self::assertSame([[self::OUTER, $bigHole], [$islandInHole, $innerHole]],
            EsriPolygon::toMultiPolygon([self::OUTER, $bigHole, $islandInHole, $innerHole]));
    }

    public function testCoordinatesAreNotChanged(): void
    {
        $outer = [[580000.1234567, 325000.7654321], [580000.1234567, 326000.5], [581000.25, 326000.5], [581000.25, 325000.7654321], [580000.1234567, 325000.7654321]];
        self::assertSame([[$outer]], EsriPolygon::toMultiPolygon([$outer]));
    }

    public function testRejectsAmbiguousInput(): void
    {
        $cases = [
            'fără inele' => [],
            'inel deschis' => [[[0, 0], [0, 10], [10, 10], [10, 0]]],
            'doar gol (trigonometric)' => [self::HOLE],
            'gol în afara exteriorului' => [self::OUTER, [[30, 30], [32, 30], [32, 32], [30, 32], [30, 30]]],
            'arie zero' => [[[0, 0], [0, 10], [0, 5], [0, 0]]],
        ];
        foreach ($cases as $label => $rings) {
            try {
                EsriPolygon::toMultiPolygon($rings);
                self::fail("Trebuia respins: $label");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
