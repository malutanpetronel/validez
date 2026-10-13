<?php

declare(strict_types=1);

namespace App\Tests\Seo;

use App\Seo\SlugGenerator;
use PHPUnit\Framework\TestCase;

/** Step S.1 (ADR-0008): regulile pure de slug. */
final class SlugGeneratorTest extends TestCase
{
    public function testDiacriticeSiSemne(): void
    {
        self::assertSame('scoala-tara', SlugGenerator::base('Școală, Țară!', 'x'));
        self::assertSame('sesizare-pe-dn1-km-12', SlugGenerator::base('  Sesizare pe DN1 — km 12 ', 'x'));
        self::assertSame('aiats', SlugGenerator::base('ĂÎÂȚȘ', 'x'));
        self::assertSame('ste', SlugGenerator::base('ŞŢE', 'x'), 'formele cu sedilă (ş, ţ) dau același rezultat');
    }

    public function testTitluFaraLitereFolosesteFallback(): void
    {
        self::assertSame('subiect', SlugGenerator::base('???', SlugGenerator::SUBJECT_FALLBACK));
        self::assertSame('categorie', SlugGenerator::base('', SlugGenerator::NODE_FALLBACK));
    }

    public function testLungimeLimitataTaiataLaCratima(): void
    {
        $slug = SlugGenerator::base(str_repeat('cuvant ', 30), 'x');
        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, \strlen($slug));
        self::assertMatchesRegularExpression('/^(cuvant-)*cuvant$/', $slug);

        $fara = SlugGenerator::base(str_repeat('a', 120), 'x');
        self::assertSame(str_repeat('a', SlugGenerator::MAX_LENGTH), $fara);
    }

    public function testPrimulLiberSiRezervat(): void
    {
        self::assertSame('cluj', SlugGenerator::firstFree('cluj', []));
        self::assertSame('cluj-2', SlugGenerator::firstFree('cluj', ['cluj']));
        self::assertSame('cluj-3', SlugGenerator::firstFree('cluj', ['cluj', 'cluj-2']));
        self::assertSame('cluj-2', SlugGenerator::firstFree('cluj', ['cluj', 'cluj-3']));
        self::assertSame('subiect-2', SlugGenerator::firstFree('subiect', [], SlugGenerator::RESERVED_NODE_SLUGS));
    }
}
