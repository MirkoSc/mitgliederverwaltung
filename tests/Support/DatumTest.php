<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Datum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Dates in local time, Europe/Berlin (CLAUDE.md section 5). */
final class DatumTest extends TestCase
{
    public function testShowsDatesFromTheDatabaseAndFromObjects(): void
    {
        self::assertSame('31.12.2026', Datum::anzeigen('2026-12-31'));
        self::assertSame('01.02.2026', Datum::anzeigen('2026-02-01 23:59:00'));
        self::assertSame('31.12.2026', Datum::anzeigen(new \DateTimeImmutable('2026-12-31 08:00:00')));
        self::assertSame('', Datum::anzeigen(null));
        self::assertSame('', Datum::anzeigen(''));
        self::assertSame('31.12.2026', datum('2026-12-31'), 'the template helper');
    }

    public function testPointsInTimeAreShownInLocalTime(): void
    {
        $utc = new \DateTimeImmutable('2026-07-01 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('01.07.2026, 14:00', Datum::zeitpunktAnzeigen($utc), 'summer time, UTC+2');
        self::assertSame('31.12.2026, 23:05', zeitpunkt('2026-12-31 23:05:00'));
    }

    public function testSomethingThatIsNoDateIsShownAsItIs(): void
    {
        self::assertSame('unbekannt', Datum::anzeigen('unbekannt'));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function eingaben(): array
    {
        return [
            'deutsch' => ['31.12.2026', '2026-12-31'],
            'ohne fuehrende null' => ['1.2.2026', '2026-02-01'],
            'datumsfeld' => ['2026-12-31', '2026-12-31'],
            'mit leerzeichen' => [' 31.12.2026 ', '2026-12-31'],
            'schaltjahr' => ['29.02.2028', '2028-02-29'],
            'kein schaltjahr' => ['29.02.2026', null],
            'gibt es nicht' => ['31.04.2026', null],
            'monat 13' => ['01.13.2026', null],
            'zweistelliges jahr' => ['31.12.26', null],
            'leer' => ['', null],
            'text' => ['morgen', null],
            'jahr null' => ['01.01.0000', null],
        ];
    }

    #[DataProvider('eingaben')]
    public function testReadsWhatPeopleTypeAndRejectsDaysThatDoNotExist(string $eingabe, ?string $erwartet): void
    {
        $datum = Datum::ausEingabe($eingabe);

        self::assertSame($erwartet, $datum === null ? null : Datum::fuerDatenbank($datum));
        if ($datum !== null) {
            self::assertSame('Europe/Berlin', $datum->getTimezone()->getName());
            self::assertSame('00:00:00', $datum->format('H:i:s'));
        }
    }

    public function testPointsInTimeAreStoredInLocalTime(): void
    {
        $utc = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-01-15 11:00:00', Datum::zeitpunktFuerDatenbank($utc), 'winter time, UTC+1');
    }
}
