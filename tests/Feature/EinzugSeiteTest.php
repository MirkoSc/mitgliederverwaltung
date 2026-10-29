<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\EinzugStatus;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlart;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Beitraege\Bankarbeitstage;
use App\Service\Storage\BlobService;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Tests\Service\Beitraege\Pain008Test;
use App\Tests\Support\AppTestCase;

/**
 * The direct debit through the whole application (docs/spec/08-beitraege.md
 * sections 3 and 4, issue #30): the section on the page "Beiträge", create,
 * the page of one run, the download, confirm, discard, the archive - and
 * 403 for the Vorstand on every route. Every name and IBAN is made up.
 */
final class EinzugSeiteTest extends AppTestCase
{
    private const string ANNA = 'DE02120300000000202051';

    private int $nummer = 0;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Files into the database: nothing is left behind on disk.
        $this->dienste->einstellungen()->set(BlobService::SETTING_BACKEND, 'db');
    }

    private function vereinsdaten(bool $vollstaendig = true): void
    {
        VereinRepository::aus($this->dienste)->speichern(Vereinsdaten::ausGespeichertem([
            'vereinsname' => 'TSV Beispielstadt 1899 e.V.',
            'iban' => $vollstaendig ? 'DE75512108001245126199' : '',
            'glaeubiger_id' => $vollstaendig ? Pain008Test::GLAEUBIGER_ID : '',
        ]));
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function beitrag(string $nachname, array $felder = [], int $cent = 8500): int
    {
        $mitglied = new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied(++$this->nummer, $nachname, vorname: 'Erika', eintrittsdatum: new \DateTimeImmutable('2015-03-01'), iban: self::ANNA)->mit($felder),
            VersionsAnlass::angelegt(),
            null,
        )->mitglied;

        return (int) $this->beitraege()->anlegenWennFehlt(new Jahresbeitrag((int) $mitglied->id, 2026, $cent))?->id;
    }

    private function beitraege(): JahresbeitragRepository
    {
        return new JahresbeitragRepository($this->dienste->pdo());
    }

    private function einzuege(): EinzugRepository
    {
        return new EinzugRepository($this->dienste->pdo());
    }

    private function faellig(): string
    {
        return Bankarbeitstage::vorschlag(new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /**
     * @param list<int> $beitraege
     * @param array<string, string> $mehr
     */
    private function erzeugen(array $beitraege, array $mehr = []): Response
    {
        $session = new Session();
        $session->start();
        $post = ['_csrf' => $session->csrfToken(), 'faellig_am' => $this->faellig(), 'beitrag' => array_map(strval(...), $beitraege), 'auswahl_vollstaendig' => '1', ...$mehr];

        return $this->aufrufen(new Request(HttpMethod::Post, '/app/beitraege/einzug', post: $post));
    }

    private function neuesterEinzug(): int
    {
        return (int) ($this->einzuege()->alle()[0]->id ?? 0);
    }

    /** The body of a download, piece by piece as the browser gets it. */
    private function herunterladen(int $id): string
    {
        $antwort = $this->kernel->handle(new Request(HttpMethod::Get, "/app/beitraege/einzuege/$id/datei"));
        self::assertInstanceOf(StreamResponse::class, $antwort);
        self::assertSame(200, $antwort->status);
        self::assertSame('application/xml', $antwort->headers['Content-Type']);
        self::assertSame('no-store', $antwort->headers['Cache-Control']);
        self::assertSame(sprintf('attachment; filename="lastschrift-%s-%d.xml"', substr($this->faellig(), 0, 4), $id), $antwort->headers['Content-Disposition']);
        $inhalt = '';
        foreach ($antwort->chunks as $stueck) {
            $inhalt .= $stueck;
        }
        self::assertSame((string) strlen($inhalt), $antwort->headers['Content-Length']);

        return $inhalt;
    }

    private function flash(): string
    {
        return (string) $this->dienste->session->pullFlash()?->text;
    }

    public function testTheVorstandGets403OnEveryRouteOfTheDirectDebit(): void
    {
        $this->vereinsdaten();
        $beitrag = $this->beitrag('Musterfrau');
        $this->als(Rolle::Admin);
        $this->erzeugen([$beitrag]);
        $id = $this->neuesterEinzug();
        $this->abmelden();
        $this->als(Rolle::Vorstand);

        foreach (["/app/beitraege/einzuege/$id", "/app/beitraege/einzuege/$id/datei"] as $pfad) {
            self::assertSame(403, $this->kernel->handle(new Request(HttpMethod::Get, $pfad))->status, $pfad);
        }
        foreach (['/app/beitraege/einzug', "/app/beitraege/einzuege/$id/bestaetigen", "/app/beitraege/einzuege/$id/verwerfen"] as $pfad) {
            self::assertSame(403, $this->post($pfad, ['sicher' => '1', 'faellig_am' => $this->faellig(), 'auswahl_vollstaendig' => '1'])->status, $pfad);
        }
        self::assertSame(EinzugStatus::Erzeugt, $this->einzuege()->finde($id)?->status);
        self::assertCount(1, $this->einzuege()->alle());
        self::assertSame(JahresbeitragStatus::ImEinzug, $this->beitraege()->finde($beitrag)?->status);
    }

    public function testWithoutCreditorIdentifierThePageSaysWhereToEnterItAndOffersNoButton(): void
    {
        $this->vereinsdaten(false);
        $beitrag = $this->beitrag('Musterfrau');
        $this->als(Rolle::Admin);

        $seite = $this->get('/app/beitraege')->body;
        self::assertStringContainsString('Noch keine Lastschriftdatei möglich.', $seite);
        self::assertStringContainsString('die IBAN des Vereinskontos, die Gläubiger-ID', $seite);
        self::assertStringContainsString('href="/admin#verein"', $seite);
        self::assertStringNotContainsString('Einzug erzeugen</button>', $seite);

        $antwort = $this->erzeugen([$beitrag]);
        self::assertWeiterleitungNach('/app/beitraege#einzug', $antwort);
        self::assertStringContainsString('Gläubiger-ID', $this->flash());
        self::assertSame([], $this->einzuege()->alle());
    }

    public function testThePageComposesTheOpenFeesAllPreselected(): void
    {
        $this->vereinsdaten();
        $anna = $this->beitrag('Albrecht', ['vorname' => 'Anna']);
        $this->beitrag('Selbmann', ['zahlart' => Zahlart::Selbst]);
        $this->beitrag('Ohnesorg', ['iban' => '']);
        $this->als(Rolle::Admin);

        $seite = $this->get('/app/beitraege')->body;

        self::assertStringContainsString('Einzug per Lastschrift', $seite);
        self::assertStringContainsString(sprintf('name="beitrag[]" value="%d" checked', $anna), $seite);
        self::assertStringContainsString('Albrecht, Anna', $seite);
        self::assertStringContainsString(sprintf('name="faellig_am" value="%s"', $this->faellig()), $seite);
        self::assertStringContainsString('Mitgliedsbeitrag {jahr} Nr. {mitgliedsnummer} {name}', $seite);
        self::assertStringContainsString('Einzug erzeugen</button>', $seite);
        self::assertStringContainsString('2 offene Beiträge lassen sich nicht per Lastschrift einziehen', $seite);
        self::assertStringContainsString('zahlt selbst', $seite);
        self::assertStringContainsString('keine IBAN', $seite);
        self::assertStringContainsString('„Selbst kassieren“', $seite);
        self::assertSame(1, substr_count($seite, 'name="beitrag[]"'), 'only the collectable fee has a checkbox');
        self::assertStringContainsString('Noch kein Einzug erzeugt.', $seite);
        // The tick list arrives complete only with the field after it.
        self::assertLessThan(strpos($seite, 'name="auswahl_vollstaendig"'), strrpos($seite, 'name="beitrag[]"'));
    }

    public function testCreateDownloadAnyNumberOfTimesAndTheFileStaysTheSame(): void
    {
        $this->vereinsdaten();
        $anna = $this->beitrag('Albrecht', ['vorname' => 'Anna']);
        $bert = $this->beitrag('Brandt', ['vorname' => 'Bert'], 4500);
        $konto = $this->als(Rolle::Admin);

        $antwort = $this->erzeugen([$anna, $bert]);

        $id = $this->neuesterEinzug();
        self::assertWeiterleitungNach('/app/beitraege/einzuege/' . $id, $antwort);
        self::assertStringStartsWith(sprintf('Einzug Nr. %d erzeugt: 2 Beiträge, 130,00', $id), $this->flash());
        self::assertSame($konto->id, $this->einzuege()->finde($id)?->erstelltVon);

        $seite = $this->get('/app/beitraege/einzuege/' . $id)->body;
        self::assertStringContainsString('Einzug Nr. ' . $id, $seite);
        self::assertStringContainsString('Lastschriftdatei herunterladen', $seite);
        self::assertStringContainsString('Einzug bestätigen', $seite);
        self::assertStringContainsString('Einzug verwerfen', $seite);
        self::assertStringContainsString('Albrecht, Anna', $seite);
        self::assertStringContainsString(sprintf('E%d-1-2026', $id), $seite, 'the reference a returned debit names');
        self::assertStringContainsString('/app/beitraege/' . $anna, $seite);

        $datei = $this->herunterladen($id);
        self::assertSame([], Pain008Test::fehler($datei));
        self::assertStringContainsString('<Nm>Anna Albrecht</Nm>', $datei);

        new MitgliedRepository($this->dienste->pdo())->speichern(
            (new MitgliedRepository($this->dienste->pdo())->finde((int) $this->beitraege()->finde($anna)?->mitgliedId))?->mit(['nachname' => 'Neuname', 'iban' => 'DE12500105170648489890']) ?? throw new \LogicException(),
            VersionsAnlass::geaendert(),
            null,
        );
        self::assertSame($datei, $this->herunterladen($id), 'byte for byte the file that went to the bank');

        $beitraegeSeite = $this->get('/app/beitraege')->body;
        self::assertStringContainsString('Erzeugt, wartet auf die Bank', $beitraegeSeite);
        self::assertStringContainsString('id="einzug-' . $id . '"', $beitraegeSeite);
        self::assertStringContainsString('Keine offenen Beiträge, die per Lastschrift eingezogen werden können.', $beitraegeSeite);

        $beitragSeite = $this->get('/app/beitraege/' . $anna)->body;
        self::assertStringContainsString('href="/app/beitraege/einzuege/' . $id . '"', $beitragSeite);
    }

    public function testAnIncompleteChoiceOrADateThatIsNoneCreatesNothing(): void
    {
        $this->vereinsdaten();
        $beitrag = $this->beitrag('Musterfrau');
        $this->als(Rolle::Admin);

        self::assertWeiterleitungNach('/app/beitraege#einzug', $this->erzeugen([$beitrag], ['auswahl_vollstaendig' => '']));
        self::assertStringContainsString('nicht vollständig', $this->flash());

        $this->erzeugen([$beitrag], ['faellig_am' => '31.02.2026']);
        self::assertStringContainsString('gültiges Fälligkeitsdatum', $this->flash());

        $this->erzeugen([]);
        self::assertStringContainsString('mindestens einen Beitrag', $this->flash());

        self::assertSame([], $this->einzuege()->alle());
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege()->finde($beitrag)?->status);
    }

    public function testAnEarlyDueDateCreatesTheRunWithAWarning(): void
    {
        $this->vereinsdaten();
        $beitrag = $this->beitrag('Musterfrau');
        $this->als(Rolle::Admin);

        $this->erzeugen([$beitrag], ['faellig_am' => new \DateTimeImmutable('today')->format('Y-m-d')]);

        self::assertStringContainsString('Achtung: Die Fälligkeit', $this->flash());
        self::assertCount(1, $this->einzuege()->alle());
    }

    public function testConfirmingNeedsTheTickThenPaysTheFees(): void
    {
        $this->vereinsdaten();
        $beitrag = $this->beitrag('Musterfrau');
        $konto = $this->als(Rolle::Admin);
        $this->erzeugen([$beitrag]);
        $id = $this->neuesterEinzug();
        $hier = '/app/beitraege/einzuege/' . $id;

        self::assertWeiterleitungNach($hier, $this->post("$hier/bestaetigen", ['zurueck' => $hier]));
        self::assertStringContainsString('Häkchen', $this->flash());
        self::assertSame(EinzugStatus::Erzeugt, $this->einzuege()->finde($id)?->status);

        self::assertWeiterleitungNach($hier, $this->post("$hier/bestaetigen", ['zurueck' => $hier, 'sicher' => '1']));
        self::assertStringStartsWith(sprintf('Einzug Nr. %d bestätigt', $id), $this->flash());
        $einzug = $this->einzuege()->finde($id);
        self::assertSame(EinzugStatus::Bestaetigt, $einzug?->status);
        self::assertSame($konto->id, $einzug->bestaetigtVon);
        $b = $this->beitraege()->finde($beitrag);
        self::assertSame(JahresbeitragStatus::Bezahlt, $b?->status);
        self::assertSame($this->faellig(), $b->bezahltAm?->format('Y-m-d'));

        // Closed: no more buttons, a second try only says so.
        $seite = $this->get($hier)->body;
        self::assertStringNotContainsString('Einzug bestätigen', $seite);
        self::assertStringContainsString('bestätigt', $seite);
        self::assertWeiterleitungNach('/app/beitraege', $this->post("$hier/verwerfen", ['sicher' => '1', 'zurueck' => 'https://example.org/']));
        self::assertStringContainsString('schon bestätigt', $this->flash());
    }

    public function testADiscardedRunStaysInTheArchiveWithItsFile(): void
    {
        $this->vereinsdaten();
        $beitrag = $this->beitrag('Musterfrau');
        $this->als(Rolle::Admin);
        $this->erzeugen([$beitrag]);
        $id = $this->neuesterEinzug();
        $datei = $this->herunterladen($id);

        self::assertWeiterleitungNach('/app/beitraege', $this->post("/app/beitraege/einzuege/$id/verwerfen", ['sicher' => '1']));
        self::assertStringContainsString('wieder offen', $this->flash());
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege()->finde($beitrag)?->status);

        $seite = $this->get('/app/beitraege')->body;
        $archiv = substr($seite, (int) strpos($seite, 'id="einzug-archiv"'));
        self::assertStringContainsString('1 Einzug, neueste zuerst, auch verworfene', $archiv);
        self::assertStringContainsString('<span class="etikett">verworfen</span>', $archiv);
        self::assertStringContainsString(sprintf('/app/beitraege/einzuege/%d/datei', $id), $archiv);
        self::assertStringContainsString(sprintf('lastschrift-%s-%d.xml', substr($this->faellig(), 0, 4), $id), $archiv);
        self::assertStringContainsString(sprintf('name="beitrag[]" value="%d" checked', $beitrag), $seite, 'open again, ready for the next run');
        self::assertSame($datei, $this->herunterladen($id));
    }

    public function testAnUnknownRunIsNotFound(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(404, $this->get('/app/beitraege/einzuege/999')->status);
        self::assertSame(404, $this->kernel->handle(new Request(HttpMethod::Get, '/app/beitraege/einzuege/999/datei'))->status);
        self::assertSame(404, $this->post('/app/beitraege/einzuege/999/bestaetigen', ['sicher' => '1'])->status);
    }
}
