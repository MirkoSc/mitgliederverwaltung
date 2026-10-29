<?php

declare(strict_types=1);

namespace App\Tests\Service\Beitraege;

use App\Service\Beitraege\Bankarbeitstage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bank working days (TARGET calendar) for the proposed due date of a direct
 * debit (docs/spec/08-beitraege.md section 3: next bank working day + 1).
 */
final class BankarbeitstageTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function ostern(): iterable
    {
        yield '2000' => [2000, '2000-04-23'];
        yield '2024' => [2024, '2024-03-31'];
        yield '2025' => [2025, '2025-04-20'];
        yield '2026' => [2026, '2026-04-05'];
        yield '2027' => [2027, '2027-03-28'];
        yield '2038' => [2038, '2038-04-25'];
    }

    #[DataProvider('ostern')]
    public function testEasterSunday(int $jahr, string $ostersonntag): void
    {
        self::assertSame($ostersonntag, Bankarbeitstage::ostersonntag($jahr)->format('Y-m-d'));
    }

    public function testClosingDaysOfTarget(): void
    {
        foreach (['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-12-25', '2026-12-26', '2026-02-14', '2026-02-15'] as $tag) {
            self::assertFalse(Bankarbeitstage::istBankarbeitstag(new \DateTimeImmutable($tag)), $tag);
        }
        // German holidays outside TARGET (Ascension, Whit Monday, 3 October)
        // and Christmas Eve are working days for SEPA.
        foreach (['2026-05-14', '2026-05-25', '2025-10-03', '2026-12-24', '2026-12-31', '2026-02-16'] as $tag) {
            self::assertTrue(Bankarbeitstage::istBankarbeitstag(new \DateTimeImmutable($tag)), $tag);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function vorschlaege(): iterable
    {
        yield 'Tuesday: Wednesday + 1 = Thursday' => ['2026-02-10', '2026-02-12'];
        yield 'Thursday: Friday + 1 = Monday' => ['2026-02-12', '2026-02-16'];
        yield 'Friday: Monday + 1 = Tuesday' => ['2026-02-13', '2026-02-17'];
        yield 'Saturday: Monday + 1 = Tuesday' => ['2026-02-14', '2026-02-17'];
        yield 'before Easter: Thursday -> Tuesday + 1 = Wednesday' => ['2026-04-02', '2026-04-08'];
        yield 'over New Year' => ['2026-12-23', '2026-12-28'];
        yield 'New Year itself' => ['2026-12-30', '2027-01-04'];
    }

    #[DataProvider('vorschlaege')]
    public function testTheProposalIsTheNextBankWorkingDayPlusOne(string $heute, string $vorschlag): void
    {
        self::assertSame($vorschlag, Bankarbeitstage::vorschlag(new \DateTimeImmutable($heute . ' 17:45'))->format('Y-m-d'));
    }
}
