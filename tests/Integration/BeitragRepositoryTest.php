<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Einzug;
use App\Domain\EinzugStatus;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\Nachweis;
use App\Domain\NachweisArt;
use App\Domain\Vermerk;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlweise;
use App\Repository\EhrungRepository;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\NachweisRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Annual fees, direct debits (docs/spec/08-beitraege.md section 6), proofs
 * (07 section 5) and honours (09 section 6) against the real database.
 */
final class BeitragRepositoryTest extends DatabaseTestCase
{
    private JahresbeitragRepository $beitraege;
    private EinzugRepository $einzuege;
    private MitgliedRepository $mitglieder;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->beitraege = new JahresbeitragRepository($this->pdo());
        $this->einzuege = new EinzugRepository($this->pdo());
        $this->mitglieder = new MitgliedRepository($this->pdo());
    }

    private function mitglied(int $nummer): int
    {
        return (int) $this->mitglieder->speichern(new Mitglied($nummer, 'Muster'), VersionsAnlass::angelegt(), 1)->mitglied->id;
    }

    private function einzug(): Einzug
    {
        return $this->einzuege->anlegen(new Einzug(
            status: EinzugStatus::Erzeugt,
            faelligAm: new \DateTimeImmutable('2026-03-02'),
            erstelltVon: 1,
            erstelltAm: new \DateTimeImmutable('2026-02-20 09:30:00'),
            anzahl: 1,
            summeCent: 8500,
            daten: [['mitgliedsnummer' => 1, 'betrag_cent' => 8500, 'name' => 'Muster']],
        ));
    }

    /** One fee per member and year; pressing "erzeugen" again adds nothing (08 section 2). */
    public function testCreatingIsIdempotentPerMemberAndYear(): void
    {
        $id = $this->mitglied(1);

        $neu = $this->beitraege->anlegenWennFehlt(new Jahresbeitrag($id, 2026, 8500));
        self::assertNotNull($neu?->id);
        self::assertNull($this->beitraege->anlegenWennFehlt(new Jahresbeitrag($id, 2026, 9999)));
        self::assertNotNull($this->beitraege->anlegenWennFehlt(new Jahresbeitrag($id, 2027, 8500)));

        self::assertSame(8500, $this->beitraege->fuer($id, 2026)?->betragCent, 'the first one stays');
        self::assertSame([2027, 2026], array_map(static fn(Jahresbeitrag $b): int => $b->jahr, $this->beitraege->fuerMitglied($id)));
        self::assertCount(1, $this->beitraege->imJahr(2026));
        self::assertCount(1, $this->beitraege->imJahr(2026, JahresbeitragStatus::Offen));
        self::assertCount(0, $this->beitraege->imJahr(2026, JahresbeitragStatus::Bezahlt));
    }

    public function testStatusPaymentAndNotesRoundTrip(): void
    {
        $id = $this->mitglied(1);
        $beitrag = $this->beitraege->anlegenWennFehlt(new Jahresbeitrag($id, 2026, 8500));
        self::assertNotNull($beitrag);

        $bezahlt = $beitrag
            ->mit(['status' => JahresbeitragStatus::Bezahlt, 'zahlweise' => Zahlweise::Bar, 'bezahltAm' => new \DateTimeImmutable('2026-04-01')])
            ->mitVermerk(new Vermerk(2, new \DateTimeImmutable('2026-04-01 18:00:00'), 'Bar beim Training „bezahlt“'));
        $this->beitraege->speichern($bezahlt);

        $gelesen = $this->beitraege->finde((int) $beitrag->id);
        self::assertSame(JahresbeitragStatus::Bezahlt, $gelesen?->status);
        self::assertSame(Zahlweise::Bar, $gelesen->zahlweise);
        self::assertSame('2026-04-01', $gelesen->bezahltAm?->format('Y-m-d'));
        self::assertCount(1, $gelesen->vermerke);
        self::assertSame(2, $gelesen->vermerke[0]->von);
        self::assertSame('Bar beim Training „bezahlt“', $gelesen->vermerke[0]->text);
        self::assertSame('2026-04-01 18:00:00', $gelesen->vermerke[0]->am->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->beitraege->mitStatus(JahresbeitragStatus::Bezahlt));
    }

    public function testANewFeeCannotBeSavedWithoutCreatingIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->beitraege->speichern(new Jahresbeitrag(1, 2026, 100));
    }

    public function testADirectDebitStoresItsFileOnceAndIsClosedOnce(): void
    {
        $einzug = $this->einzug();
        $id = (int) $einzug->id;

        self::assertTrue($this->einzuege->dateiSetzen($id, 900, 'MSG-1'));
        self::assertFalse($this->einzuege->dateiSetzen($id, 901, 'MSG-2'), 'the file is never replaced');
        self::assertSame(900, $this->einzuege->finde($id)?->dateiBlobId);
        self::assertSame('MSG-1', $this->einzuege->finde($id)?->msgId);

        self::assertTrue($this->einzuege->abschliessen($id, EinzugStatus::Bestaetigt, 3, new \DateTimeImmutable('2026-03-05 08:00:00')));
        self::assertFalse($this->einzuege->abschliessen($id, EinzugStatus::Verworfen, 3));

        $gelesen = $this->einzuege->finde($id);
        self::assertSame(EinzugStatus::Bestaetigt, $gelesen?->status);
        self::assertSame(3, $gelesen->bestaetigtVon);
        self::assertSame('2026-03-05 08:00:00', $gelesen->bestaetigtAm?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-02', $gelesen->faelligAm->format('Y-m-d'));
        self::assertSame(8500, $gelesen->summeCent);
        self::assertSame('Muster', $gelesen->daten[0]['name']);
    }

    public function testTheArchiveShowsDiscardedRunsToo(): void
    {
        $erster = $this->einzug();
        $zweiter = $this->einzug();
        $this->einzuege->abschliessen((int) $erster->id, EinzugStatus::Verworfen, 1);

        $archiv = $this->einzuege->alle();

        self::assertSame([$zweiter->id, $erster->id], array_map(static fn(Einzug $e): ?int => $e->id, $archiv));
        self::assertSame(EinzugStatus::Verworfen, $archiv[1]->status);
    }

    public function testRejectsReopeningARun(): void
    {
        $this->expectException(\LogicException::class);
        $this->einzuege->abschliessen((int) $this->einzug()->id, EinzugStatus::Erzeugt, 1);
    }

    /** Deleting a member keeps the direct debit and its own copy (08 section 3). */
    public function testADirectDebitOutlivesItsMembers(): void
    {
        $mitglied = $this->mitglied(1);
        $einzug = $this->einzug();
        $beitrag = $this->beitraege->anlegenWennFehlt(new Jahresbeitrag($mitglied, 2026, 8500));
        self::assertNotNull($beitrag);
        $this->beitraege->speichern($beitrag->mit(['status' => JahresbeitragStatus::ImEinzug, 'einzugId' => $einzug->id]));
        self::assertCount(1, $this->beitraege->desEinzugs((int) $einzug->id));

        $this->mitglieder->loeschen($mitglied);

        self::assertSame([], $this->beitraege->desEinzugs((int) $einzug->id));
        self::assertSame('Muster', $this->einzuege->finde((int) $einzug->id)?->daten[0]['name']);
    }

    public function testProofs(): void
    {
        $id = $this->mitglied(1);
        $repo = new NachweisRepository($this->pdo());

        $nachweis = $repo->anlegen(
            new Nachweis($id, NachweisArt::Studium, new \DateTimeImmutable('2027-09-30'), 'Immatrikulation', 55, 4, new \DateTimeImmutable('2000-01-01')),
            new \DateTimeImmutable('2026-10-28 10:00:00'),
        );
        $repo->anlegen(new Nachweis($id, NachweisArt::Rente), new \DateTimeImmutable('2026-10-29 10:00:00'));

        $gelesen = $repo->finde((int) $nachweis->id);
        self::assertSame(NachweisArt::Studium, $gelesen?->art);
        self::assertSame('2027-09-30', $gelesen->gueltigBis?->format('Y-m-d'));
        self::assertSame('Immatrikulation', $gelesen->bemerkung);
        self::assertSame(55, $gelesen->blobId);
        self::assertSame(4, $gelesen->vermerktVon);
        self::assertSame('2026-10-28 10:00:00', $gelesen->vermerktAm?->format('Y-m-d H:i:s'), 'set by the repository');

        self::assertSame([NachweisArt::Rente, NachweisArt::Studium], array_map(static fn(Nachweis $n): NachweisArt => $n->art, $repo->fuerMitglied($id)));
        self::assertCount(2, $repo->fuerMitglieder([$id, 999])[$id]);

        self::assertSame(55, $repo->loeschen((int) $nachweis->id));
        self::assertNull($repo->finde((int) $nachweis->id));
        self::assertNull($repo->loeschen((int) $nachweis->id));
    }

    public function testHonours(): void
    {
        $id = $this->mitglied(1);
        $repo = new EhrungRepository($this->pdo());

        self::assertTrue($repo->vermerken($id, 25, new \DateTimeImmutable('2026-05-01'), 2));
        self::assertFalse($repo->vermerken($id, 25, new \DateTimeImmutable('2026-06-01'), 3), 'once per level');
        self::assertTrue($repo->vermerken($id, 40, new \DateTimeImmutable('2041-05-01'), 2));

        $ehrungen = $repo->fuerMitglied($id);
        self::assertSame([25, 40], array_map(static fn(\App\Domain\Ehrung $e): int => $e->stufeJahre, $ehrungen));
        self::assertSame('2026-05-01', $ehrungen[0]->geehrtAm->format('Y-m-d'));
        self::assertSame(2, $ehrungen[0]->vermerktVon);
        self::assertSame([$id], array_keys($repo->derStufe(25)));

        self::assertTrue($repo->zuruecknehmen($id, 25));
        self::assertFalse($repo->zuruecknehmen($id, 25));
        self::assertSame([], $repo->derStufe(25));
    }
}
