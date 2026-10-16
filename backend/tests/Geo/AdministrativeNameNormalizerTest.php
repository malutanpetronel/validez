<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\Geo\AdministrativeNameNormalizer;
use PHPUnit\Framework\TestCase;

/** ADR-0012 p.2: doar NFC, sedilă → virgulă și spații; restul rămâne ca în SIRUTA. */
final class AdministrativeNameNormalizerTest extends TestCase
{
    public function testCedillaBecomesCommaBelow(): void
    {
        self::assertSame('JUDEȚUL BRAȘOV', AdministrativeNameNormalizer::display('JUDEŢUL BRAŞOV'));
        self::assertSame('ș ț Ș Ț', AdministrativeNameNormalizer::display('ş ţ Ş Ţ'));
    }

    public function testDecomposedInputIsComposedFirst(): void
    {
        // S + U+0327 (sedilă combinată) → Ş (NFC) → Ș
        self::assertSame('ȘIEU', AdministrativeNameNormalizer::display("S\u{0327}IEU"));
    }

    public function testRepeatedWhitespaceIsCollapsed(): void
    {
        self::assertSame('MUNICIPIUL MARGHITA', AdministrativeNameNormalizer::display('MUNICIPIUL  MARGHITA'));
        self::assertSame('POIANA ILVEI', AdministrativeNameNormalizer::display(" POIANA\u{00A0}\tILVEI "));
    }

    public function testLettersHyphensPrefixesAndCaseAreKept(): void
    {
        self::assertSame('ORAȘ POPEȘTI-LEORDENI', AdministrativeNameNormalizer::display('ORAŞ POPEŞTI-LEORDENI'));
        self::assertSame('CICEU - MIHĂIEȘTI', AdministrativeNameNormalizer::display('CICEU - MIHĂIEŞTI'));
        self::assertSame('ALEXANDRU I.CUZA', AdministrativeNameNormalizer::display('ALEXANDRU I.CUZA'));
    }
}
