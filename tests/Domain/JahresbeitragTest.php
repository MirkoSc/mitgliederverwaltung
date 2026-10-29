<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\BeitragNichtAenderbar;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Vermerk;
use App\Domain\Zahlweise;
use PHPUnit\Framework\TestCase;

/**
 * The status transitions of an annual fee (docs/spec/08-beitraege.md
 * section 2, "Pflicht-Tests"): offen -> im_einzug -> bezahlt, discarding a
 * direct debit, paid by hand, returned debit, amount only while open.
 */
final class JahresbeitragTest extends TestCase
{
    private static function offen(): Jahresbeitrag
    {
        return new Jahresbeitrag(mitgliedId: 1, jahr: 2026, betragCent: 8500, id: 7);
    }

    private static function vermerk(string $text = 'Notiz'): Vermerk
    {
        return new Vermerk(3, new \DateTimeImmutable('2026-02-01 10:00:00'), $text);
    }

    public function testTheWayThroughADirectDebit(): void
    {
        $imEinzug = self::offen()->inEinzug(12);
        self::assertSame(JahresbeitragStatus::ImEinzug, $imEinzug->status);
        self::assertSame(12, $imEinzug->einzugId);

        $bezahlt = $imEinzug->bezahltPerEinzug(new \DateTimeImmutable('2026-03-02'));
        self::assertSame(JahresbeitragStatus::Bezahlt, $bezahlt->status);
        self::assertSame('2026-03-02', $bezahlt->bezahltAm?->format('Y-m-d'));
        self::assertNull($bezahlt->zahlweise);
        self::assertSame(12, $bezahlt->einzugId, 'paid by that direct debit');
    }

    public function testDiscardingTheDirectDebitMakesItOpenAgain(): void
    {
        $zurueck = self::offen()->inEinzug(12)->ausEinzugZurueck(self::vermerk('Einzug verworfen'));

        self::assertSame(JahresbeitragStatus::Offen, $zurueck->status);
        self::assertNull($zurueck->einzugId);
        self::assertSame(['Einzug verworfen'], array_map(static fn(Vermerk $v): string => $v->text, $zurueck->vermerke));
        self::assertSame(JahresbeitragStatus::ImEinzug, $zurueck->inEinzug(13)->status, 'can go into the next one');
    }

    public function testPaidByHandAndReturnedDebit(): void
    {
        $bezahlt = self::offen()->bezahltVonHand(Zahlweise::Bar, new \DateTimeImmutable('2026-02-03'), self::vermerk('bar im Training'));
        self::assertSame(JahresbeitragStatus::Bezahlt, $bezahlt->status);
        self::assertSame(Zahlweise::Bar, $bezahlt->zahlweise);
        self::assertSame('2026-02-03', $bezahlt->bezahltAm?->format('Y-m-d'));
        self::assertCount(1, $bezahlt->vermerke);

        $offen = $bezahlt->ruecklastschrift(self::vermerk('Bank meldet Rücklastschrift'));
        self::assertSame(JahresbeitragStatus::Offen, $offen->status);
        self::assertNull($offen->zahlweise);
        self::assertNull($offen->bezahltAm);
        self::assertNull($offen->einzugId);
        self::assertCount(2, $offen->vermerke);
        self::assertSame(8500, $offen->betragCent);
    }

    public function testAReturnedDebitAfterADirectDebitCanBeCollectedAgain(): void
    {
        $offen = self::offen()->inEinzug(12)->bezahltPerEinzug(new \DateTimeImmutable('2026-03-02'))
            ->ruecklastschrift(self::vermerk('Konto erloschen'));

        self::assertSame(JahresbeitragStatus::Offen, $offen->status);
        self::assertNull($offen->einzugId);
        self::assertSame(20, $offen->inEinzug(20)->einzugId);
    }

    public function testAReturnedDebitNeedsANote(): void
    {
        $bezahlt = self::offen()->bezahltVonHand(Zahlweise::Ueberweisung, new \DateTimeImmutable('2026-02-03'), self::vermerk());

        $this->expectException(BeitragNichtAenderbar::class);
        $bezahlt->ruecklastschrift(self::vermerk('  '));
    }

    public function testTheAmountChangesOnlyWhileOpen(): void
    {
        $geaendert = self::offen()->mitBetrag(5667, self::vermerk('Betrag geändert'));
        self::assertSame(5667, $geaendert->betragCent);
        self::assertCount(1, $geaendert->vermerke);

        $nichtOffen = [
            'im Einzug' => self::offen()->inEinzug(1),
            'bezahlt' => self::offen()->bezahltVonHand(Zahlweise::Bar, new \DateTimeImmutable('2026-02-03'), self::vermerk()),
        ];
        foreach ($nichtOffen as $fall => $beitrag) {
            self::assertFalse($beitrag->betragAenderbar(), $fall);
            try {
                $beitrag->mitBetrag(100, self::vermerk());
                self::fail('amount changed while ' . $fall);
            } catch (BeitragNichtAenderbar) {
                self::assertSame(8500, $beitrag->betragCent);
            }
        }
    }

    public function testTheAmountMustBePositive(): void
    {
        $this->expectException(BeitragNichtAenderbar::class);
        self::offen()->mitBetrag(0, self::vermerk());
    }

    /**
     * @return iterable<string, array{\Closure(Jahresbeitrag): Jahresbeitrag, \Closure(Jahresbeitrag): Jahresbeitrag}>
     */
    public static function verboteneUebergaenge(): iterable
    {
        $bezahlt = static fn(Jahresbeitrag $b): Jahresbeitrag => $b->bezahltVonHand(Zahlweise::Bar, new \DateTimeImmutable('2026-02-03'), self::vermerk());
        $imEinzug = static fn(Jahresbeitrag $b): Jahresbeitrag => $b->inEinzug(1);
        $offen = static fn(Jahresbeitrag $b): Jahresbeitrag => $b;

        yield 'paid by hand while in a direct debit' => [$imEinzug, $bezahlt];
        yield 'paid by hand twice' => [$bezahlt, $bezahlt];
        yield 'into a direct debit when paid' => [$bezahlt, $imEinzug];
        yield 'into a second direct debit' => [$imEinzug, $imEinzug];
        yield 'confirmed without a direct debit' => [$offen, static fn(Jahresbeitrag $b): Jahresbeitrag => $b->bezahltPerEinzug(new \DateTimeImmutable('2026-03-02'))];
        yield 'discarded without a direct debit' => [$offen, static fn(Jahresbeitrag $b): Jahresbeitrag => $b->ausEinzugZurueck()];
        yield 'returned debit while open' => [$offen, static fn(Jahresbeitrag $b): Jahresbeitrag => $b->ruecklastschrift(self::vermerk())];
        yield 'returned debit while in a direct debit' => [$imEinzug, static fn(Jahresbeitrag $b): Jahresbeitrag => $b->ruecklastschrift(self::vermerk())];
    }

    /**
     * @param \Closure(Jahresbeitrag): Jahresbeitrag $vorher
     * @param \Closure(Jahresbeitrag): Jahresbeitrag $uebergang
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verboteneUebergaenge')]
    public function testTransitionsTheStatusDoesNotAllowAreRefused(\Closure $vorher, \Closure $uebergang): void
    {
        $beitrag = $vorher(self::offen());

        $this->expectException(BeitragNichtAenderbar::class);
        $uebergang($beitrag);
    }
}
