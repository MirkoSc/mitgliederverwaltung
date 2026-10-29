<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BeitragNichtAenderbar;
use App\Domain\Beitragsart;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\Vermerk;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlweise;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Beitraege\Jahresbeitraege;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Creating and changing annual fees (docs/spec/08-beitraege.md section 2,
 * "Pflicht-Tests") against the real database. Members are made up.
 */
final class JahresbeitraegeTest extends DatabaseTestCase
{
    private Jahresbeitraege $service;
    private JahresbeitragRepository $beitraege;
    private MitgliedRepository $mitglieder;

    /** @var array<string, Beitragsart> the seeded fee types by name */
    private array $arten = [];

    private int $nummer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->service = Jahresbeitraege::mit($this->pdo());
        $this->beitraege = new JahresbeitragRepository($this->pdo());
        $this->mitglieder = new MitgliedRepository($this->pdo());
        foreach (new BeitragsartRepository($this->pdo())->alle() as $art) {
            $this->arten[$art->name] = $art;
        }
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function mitglied(?string $art = 'Aktiv', array $felder = []): int
    {
        $mitglied = new Mitglied(++$this->nummer, 'Muster' . $this->nummer)->mit([
            'beitragsartId' => $art === null ? null : $this->arten[$art]->id,
            ...$felder,
        ]);

        return (int) $this->mitglieder->speichern($mitglied, VersionsAnlass::angelegt(), 1)->mitglied->id;
    }

    private static function datum(string $tag): \DateTimeImmutable
    {
        return new \DateTimeImmutable($tag);
    }

    /**
     * @return iterable<string, array{int, string|null, int}>
     */
    public static function anteile(): iterable
    {
        yield 'January = full' => [8500, '2026-01-15', 8500];
        yield 'May = 8/12' => [8500, '2026-05-31', 5667]; // 56,666… rounds up
        yield 'December = 1/12' => [8500, '2026-12-01', 708]; // 7,083… rounds down
        yield 'half a cent rounds up' => [6, '2026-12-01', 1]; // 0,5 cent
        yield 'just below half rounds down' => [5, '2026-12-01', 0]; // 0,41… cent
        yield 'June of 45 €' => [4500, '2026-06-01', 2625]; // 7/12
        yield 'joined before the year = full' => [8500, '2019-08-01', 8500];
        yield 'unknown entry = full' => [8500, null, 8500];
    }

    #[DataProvider('anteile')]
    public function testTheProRataProposalByBegunMonths(int $betrag, ?string $eintritt, int $erwartet): void
    {
        self::assertSame($erwartet, Jahresbeitraege::anteiligerBetrag($betrag, $eintritt === null ? null : self::datum($eintritt), 2026));
    }

    public function testCreatingIsIdempotentAndCatchesUpNewMembers(): void
    {
        $alt = $this->mitglied('Aktiv', ['eintrittsdatum' => self::datum('2010-04-01')]);
        $passiv = $this->mitglied('Passiv');

        $erstes = $this->service->erzeugen(2026, 1);
        self::assertSame([2, 0], [$erstes->neu, $erstes->vorhanden]);
        self::assertSame(8500, $this->beitraege->fuer($alt, 2026)?->betragCent);
        self::assertSame(5000, $this->beitraege->fuer($passiv, 2026)?->betragCent);

        $zweites = $this->service->erzeugen(2026, 1);
        self::assertSame([0, 2], [$zweites->neu, $zweites->vorhanden]);
        self::assertCount(2, $this->beitraege->imJahr(2026));

        // A member who joins in May is caught up by pressing the button again,
        // with the pro-rata amount; the existing fees stay as they are.
        $neu = $this->mitglied('Aktiv', ['eintrittsdatum' => self::datum('2026-05-10')]);
        $this->service->betragAendern((int) $this->beitraege->fuer($alt, 2026)?->id, 4000, 'Härtefall', 1);

        $drittes = $this->service->erzeugen(2026, 1);
        self::assertSame([1, 2], [$drittes->neu, $drittes->vorhanden]);
        self::assertSame(5667, $this->beitraege->fuer($neu, 2026)?->betragCent);
        self::assertSame(4000, $this->beitraege->fuer($alt, 2026)?->betragCent, 'not overwritten');
        self::assertStringContainsString('anteilig 8/12', $this->beitraege->fuer($neu, 2026)?->vermerke[0]->text ?? '');
    }

    public function testFormerFeeFreeAndMembersWithoutFeeTypeGetNoFee(): void
    {
        $ehemalig = $this->mitglied('Aktiv', ['austrittsdatum' => self::datum('2025-12-31')]);
        $austrittAmStichtag = $this->mitglied('Aktiv', ['austrittsdatum' => self::datum('2026-01-01')]);
        $beitragsfrei = $this->mitglied('Beitragsfrei');
        $ohneArt = $this->mitglied(null);
        $spaeterEintritt = $this->mitglied('Aktiv', ['eintrittsdatum' => self::datum('2027-01-01')]);
        // Leaves during the year: the key date is 1 January, so the fee is due.
        $austrittImJahr = $this->mitglied('Passiv', ['austrittsdatum' => self::datum('2026-06-30')]);

        $ergebnis = $this->service->erzeugen(2026, 1);

        self::assertSame(1, $ergebnis->neu);
        foreach ([$ehemalig, $austrittAmStichtag, $beitragsfrei, $ohneArt, $spaeterEintritt] as $id) {
            self::assertNull($this->beitraege->fuer($id, 2026));
        }
        self::assertSame(5000, $this->beitraege->fuer($austrittImJahr, 2026)?->betragCent);
    }

    public function testTheAmountIsACopyOfTheFeeTypeAtThatMoment(): void
    {
        $id = $this->mitglied('Aktiv');
        $this->service->erzeugen(2026, 1);

        $arten = new BeitragsartRepository($this->pdo());
        $arten->speichern($this->arten['Aktiv']->mit(['betragCent' => 9000]));
        $this->service->erzeugen(2026, 1);
        $this->service->erzeugen(2027, 1);

        self::assertSame(8500, $this->beitraege->fuer($id, 2026)?->betragCent);
        self::assertSame(9000, $this->beitraege->fuer($id, 2027)?->betragCent);
    }

    public function testTheCreationNoteSaysWhoAndFromWhat(): void
    {
        $id = $this->mitglied('Aktiv');
        $this->service->erzeugen(2026, 42, new \DateTimeImmutable('2026-01-20 08:00:00'));

        $vermerke = $this->beitraege->fuer($id, 2026)?->vermerke ?? [];
        self::assertCount(1, $vermerke);
        self::assertSame(42, $vermerke[0]->von);
        self::assertSame('2026-01-20 08:00:00', $vermerke[0]->am->format('Y-m-d H:i:s'));
        self::assertStringContainsString('„Aktiv“', $vermerke[0]->text);
    }

    public function testChangesLeaveNotesAndFollowTheStatusRules(): void
    {
        $this->mitglied('Aktiv');
        $this->service->erzeugen(2026, 1);
        $id = (int) $this->beitraege->imJahr(2026)[0]->id;

        $this->service->betragAendern($id, 4250, 'halber Beitrag', 7);
        $this->service->bezahltVonHand($id, Zahlweise::Ueberweisung, self::datum('2026-02-14'), '', 7);

        try {
            $this->service->betragAendern($id, 100, '', 7);
            self::fail('amount changed while paid');
        } catch (BeitragNichtAenderbar) {
        }
        try {
            $this->service->ruecklastschrift($id, ' ', 7);
            self::fail('returned debit without a note');
        } catch (BeitragNichtAenderbar) {
        }

        $this->service->ruecklastschrift($id, 'Überweisung kam zurück', 8);
        $this->service->vermerken($id, 'Mahnung geschickt', 8);

        $beitrag = $this->beitraege->finde($id);
        self::assertNotNull($beitrag);
        self::assertSame(JahresbeitragStatus::Offen, $beitrag->status);
        self::assertSame(4250, $beitrag->betragCent);
        self::assertNull($beitrag->zahlweise);
        self::assertNull($beitrag->bezahltAm);
        self::assertSame(
            [
                [1, 'Erzeugt'],
                [7, 'Betrag von 85,00 € auf 42,50 € geändert: halber Beitrag'],
                [7, 'Bezahlt (Überweisung) am 14.02.2026'],
                [8, 'Zurück auf offen: Überweisung kam zurück'],
                [8, 'Mahnung geschickt'],
            ],
            array_map(
                static fn(Vermerk $v): array => [$v->von, str_starts_with($v->text, 'Erzeugt') ? 'Erzeugt' : str_replace("\u{00A0}", ' ', $v->text)],
                $beitrag->vermerke,
            ),
        );
    }

    public function testAnUnknownFeeIsReported(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service->vermerken(999999, 'gibt es nicht', 1);
    }
}
