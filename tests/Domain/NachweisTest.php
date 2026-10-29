<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Nachweis;
use App\Domain\NachweisArt;
use PHPUnit\Framework\TestCase;

/** Validity of a proof (docs/spec/07-mitglieder.md sections 5 and 6). */
final class NachweisTest extends TestCase
{
    public function testValidUpToAndIncludingTheEndDate(): void
    {
        $nachweis = new Nachweis(1, NachweisArt::Studium, gueltigBis: new \DateTimeImmutable('2026-09-30'));

        self::assertTrue($nachweis->istGueltigAm(new \DateTimeImmutable('2026-09-29')));
        self::assertTrue($nachweis->istGueltigAm(new \DateTimeImmutable('2026-09-30 18:00')), 'valid until today counts');
        self::assertFalse($nachweis->istGueltigAm(new \DateTimeImmutable('2026-10-01')));
        self::assertTrue($nachweis->istAbgelaufenAm(new \DateTimeImmutable('2026-10-01')));
        self::assertFalse($nachweis->istAbgelaufenAm(new \DateTimeImmutable('2026-09-30')));
    }

    public function testWithoutAnEndDateItIsAlwaysValid(): void
    {
        $nachweis = new Nachweis(1, NachweisArt::Rente);

        self::assertTrue($nachweis->istGueltigAm(new \DateTimeImmutable('2099-01-01')));
        self::assertFalse($nachweis->istAbgelaufenAm(new \DateTimeImmutable('2099-01-01')));
    }
}
