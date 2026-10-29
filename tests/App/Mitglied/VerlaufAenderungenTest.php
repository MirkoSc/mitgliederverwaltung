<?php

declare(strict_types=1);

namespace App\Tests\App\Mitglied;

use App\App\Mitglied\VerlaufAenderungen;
use App\Domain\Mitglied;
use App\Domain\MitgliedVersion;
use App\Domain\VersionsAnlass;
use PHPUnit\Framework\TestCase;

final class VerlaufAenderungenTest extends TestCase
{
    /** @param array<string, mixed> $mehr */
    private function version(int $nr, Mitglied $m, array $mehr = []): MitgliedVersion
    {
        return new MitgliedVersion($nr, 1, $nr, VersionsAnlass::geaendert(), null, new \DateTimeImmutable('2026-01-01'), [...$m->daten(), ...$mehr]);
    }

    public function testTheFirstVersionHasNoChanges(): void
    {
        self::assertSame([], VerlaufAenderungen::zwischen(null, $this->version(1, new Mitglied(1, 'Muster'))));
    }

    public function testOnlyChangedFieldsAreListed(): void
    {
        $alt = $this->version(1, new Mitglied(1, 'Muster', ort: 'Altdorf', geburtsdatum: new \DateTimeImmutable('2010-03-04')));
        $neu = $this->version(2, new Mitglied(1, 'Muster', ort: 'Neustadt', aktiv: false));

        self::assertSame([
            ['feld' => 'Geburtsdatum', 'alt' => '04.03.2010', 'neu' => '–'],
            ['feld' => 'Ort', 'alt' => 'Altdorf', 'neu' => 'Neustadt'],
            ['feld' => 'Status', 'alt' => 'aktiv', 'neu' => 'passiv'],
        ], VerlaufAenderungen::zwischen($alt, $neu));
    }

    public function testNamesComeFromTheVersionsThemselves(): void
    {
        $alt = $this->version(1, new Mitglied(1, 'Muster', abteilungIds: [3]), ['bezeichnungen' => ['abteilungen' => ['3' => 'Fußball']]]);
        $neu = $this->version(2, new Mitglied(1, 'Muster', abteilungIds: [3, 4]), ['bezeichnungen' => ['abteilungen' => ['3' => 'Fußball', '4' => 'Tennis']]]);

        self::assertSame([['feld' => 'Abteilungen', 'alt' => 'Fußball', 'neu' => 'Fußball, Tennis']], VerlaufAenderungen::zwischen($alt, $neu));
    }

    public function testNoChangeMeansNoEntries(): void
    {
        $v = $this->version(1, new Mitglied(1, 'Muster'));

        self::assertSame([], VerlaufAenderungen::zwischen($v, $v));
    }
}
