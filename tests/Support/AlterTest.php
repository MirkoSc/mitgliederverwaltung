<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Alter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Age in whole years (docs/spec/07-mitglieder.md section 6). */
final class AlterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function faelle(): iterable
    {
        yield 'day before the 18th birthday' => ['2008-05-10', '2026-05-09', 17];
        yield 'exactly 18' => ['2008-05-10', '2026-05-10', 18];
        yield 'day after' => ['2008-05-10', '2026-05-11', 18];
        yield 'exactly 67' => ['1959-01-01', '2026-01-01', 67];
        yield 'day before 67' => ['1959-01-01', '2025-12-31', 66];
        yield 'born on the day' => ['2026-03-01', '2026-03-01', 0];
        yield '29 Feb, leap year, day before' => ['2008-02-29', '2024-02-28', 15];
        yield '29 Feb, leap year, birthday' => ['2008-02-29', '2024-02-29', 16];
        yield '29 Feb, non-leap year: 28 Feb counts' => ['2008-02-29', '2026-02-28', 18];
        yield '29 Feb, non-leap year, day before' => ['2008-02-29', '2026-02-27', 17];
    }

    #[DataProvider('faelle')]
    public function testAge(string $geburt, string $stichtag, int $alter): void
    {
        self::assertSame($alter, Alter::am(new \DateTimeImmutable($geburt), new \DateTimeImmutable($stichtag)));
    }

    public function testTheTimeOfDayDoesNotMatter(): void
    {
        self::assertSame(18, Alter::am(new \DateTimeImmutable('2008-05-10 23:59'), new \DateTimeImmutable('2026-05-10 00:00')));
    }

    public function testBirthdayInAYear(): void
    {
        $geburt = new \DateTimeImmutable('2008-02-29');

        self::assertSame('02-29', Alter::geburtstagImJahr($geburt, 2028));
        self::assertSame('02-28', Alter::geburtstagImJahr($geburt, 2027));
        self::assertSame('07-14', Alter::geburtstagImJahr(new \DateTimeImmutable('1990-07-14'), 2027));
    }
}
