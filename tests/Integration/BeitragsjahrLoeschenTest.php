<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BeitragNichtAenderbar;
use App\Domain\BlobStorage;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlweise;
use App\Repository\BlobRepository;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Service\Beitraege\BeitragsjahrLoeschen;
use App\Service\Beitraege\Einzuege;
use App\Service\Beitraege\Jahresbeitraege;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Tests\Service\Beitraege\Pain008Test;
use App\Tests\Support\DatabaseTestCase;

/**
 * "Beiträge <Jahr> löschen" and several fees paid at once
 * (docs/spec/08-beitraege.md section 2a, #90) against the real database
 * and blob storage. Every name and IBAN is made up.
 */
final class BeitragsjahrLoeschenTest extends DatabaseTestCase
{
    private const string IBAN = 'DE02120300000000202051';

    private string $blobDir;

    private BeitragsjahrLoeschen $loeschen;

    private Einzuege $einzugService;

    private Jahresbeitraege $jahresbeitraege;

    private BlobService $blobs;

    private JahresbeitragRepository $beitraege;

    private EinzugRepository $einzuege;

    private MitgliedRepository $mitglieder;

    private int $nummer = 2000;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();
        new Migrator($pdo, $this->migrationsDir())->migrate();
        $this->blobDir = sys_get_temp_dir() . '/mv_jahr_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $blobRepo = new BlobRepository($pdo);
        $this->blobs = new BlobService(
            $blobRepo,
            new DbBlobBackend($blobRepo),
            new FsBlobBackend($this->blobDir),
            new ServerCrypto(str_repeat('k', ServerCrypto::KEY_BYTES)),
            BlobStorage::Fs,
        );
        $audit = new AuditLog($pdo, str_repeat('a', 32));
        $this->beitraege = new JahresbeitragRepository($pdo);
        $this->einzuege = new EinzugRepository($pdo);
        $this->mitglieder = new MitgliedRepository($pdo);
        $verein = new VereinRepository($pdo, new SettingRepository($pdo));
        $verein->speichern(Vereinsdaten::ausGespeichertem([
            'vereinsname' => 'TSV Beispielstadt 1899 e.V.',
            'iban' => 'DE75512108001245126199',
            'glaeubiger_id' => Pain008Test::GLAEUBIGER_ID,
            'mandatsdatum' => '2016-01-01',
        ]));
        $this->einzugService = new Einzuege($pdo, $this->einzuege, $this->beitraege, $this->mitglieder, $verein, $this->blobs, $audit);
        $this->loeschen = new BeitragsjahrLoeschen($pdo, $this->beitraege, $this->einzuege, $blobRepo, $this->blobs, $audit);
        $this->jahresbeitraege = Jahresbeitraege::mit($pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            @unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $ordner) {
            @rmdir($ordner);
        }
        @rmdir($this->blobDir);
        parent::tearDown();
    }

    private function mitglied(string $nachname, ?int $beitragsartId = null): Mitglied
    {
        return $this->mitglieder->speichern(
            new Mitglied(++$this->nummer, $nachname, vorname: 'Erika', eintrittsdatum: new \DateTimeImmutable('2015-03-01'), iban: self::IBAN, beitragsartId: $beitragsartId),
            VersionsAnlass::angelegt(),
            1,
        )->mitglied;
    }

    private function beitrag(Mitglied $mitglied, int $jahr, int $cent = 8500): int
    {
        return (int) $this->beitraege->anlegenWennFehlt(new Jahresbeitrag((int) $mitglied->id, $jahr, $cent))?->id;
    }

    /** @param list<int> $ids */
    private function einzug(array $ids): int
    {
        return (int) $this->einzugService->erzeugen($ids, new \DateTimeImmutable('2026-03-02'), 7, new \DateTimeImmutable('2026-02-10 10:00:00'))->id;
    }

    private function blobDateien(): int
    {
        return count(glob($this->blobDir . '/*/*') ?: []);
    }

    private function anzahl(string $sql): int
    {
        return (int) $this->pdo()->query($sql)->fetchColumn();
    }

    public function testAWrongConfirmationWordDeletesNothing(): void
    {
        $this->beitrag($this->mitglied('Albrecht'), 2026);

        foreach (['', 'loeschen', 'LÖSCHE', 'ja', 'LÖSCHEN!'] as $wort) {
            try {
                $this->loeschen->loeschen(2026, $wort, 1);
                self::fail('deleted with "' . $wort . '"');
            } catch (BeitragNichtAenderbar $e) {
                self::assertStringContainsString('LÖSCHEN', $e->getMessage());
            }
        }

        self::assertCount(1, $this->beitraege->imJahr(2026));
        self::assertSame(0, $this->anzahl("SELECT COUNT(*) FROM audit_log WHERE action = 'beitraege.jahr_geloescht'"));
    }

    public function testTheConfirmationWordIgnoresCaseAndSurroundingSpace(): void
    {
        self::assertTrue(BeitragsjahrLoeschen::bestaetigt('LÖSCHEN'));
        self::assertTrue(BeitragsjahrLoeschen::bestaetigt('löschen'));
        self::assertTrue(BeitragsjahrLoeschen::bestaetigt('  Löschen '));
        self::assertFalse(BeitragsjahrLoeschen::bestaetigt('loschen'));
    }

    public function testDeletingAPaidYearWithARunRemovesEverythingOfThatYearAndNothingOfOthers(): void
    {
        $anna = $this->mitglied('Albrecht', $this->aktivId());
        $bert = $this->mitglied('Brandt');
        $carla = $this->mitglied('Christ');

        // 2026: one collected by direct debit, one paid in cash, one open.
        $a26 = $this->beitrag($anna, 2026);
        $b26 = $this->beitrag($bert, 2026, 4500);
        $c26 = $this->beitrag($carla, 2026, 2500);
        $einzug26 = $this->einzug([$a26]);
        $this->einzugService->bestaetigen($einzug26, 7);
        $this->jahresbeitraege->bezahltVonHand($b26, Zahlweise::Bar, new \DateTimeImmutable('2026-03-05'), 'Kasse', 7);
        // A discarded run of 2026 as well - "jedem Zustand".
        $verworfen26 = $this->einzug([$c26]);
        $this->einzugService->verwerfen($verworfen26, 7);

        // 2025: its own run, paid - must stay untouched.
        $a25 = $this->beitrag($anna, 2025);
        $einzug25 = $this->einzug([$a25]);
        $this->einzugService->bestaetigen($einzug25, 7);
        $datei25 = $this->einzuege->finde($einzug25)?->dateiBlobId;
        self::assertSame(3, $this->blobDateien());

        $umfang = $this->loeschen->umfang(2026);
        self::assertSame([3, 2, 15500], [$umfang->anzahl, $umfang->bezahlt, $umfang->summeCent]);
        self::assertSame([$verworfen26, $einzug26], array_map(static fn($e): int => (int) $e->id, $umfang->einzuege), 'newest first');
        self::assertSame([], $umfang->gemischt);
        self::assertTrue($umfang->hatLastschriftdatei());

        $geloescht = $this->loeschen->loeschen(2026, 'löschen', 7, '203.0.113.9');

        self::assertSame(3, $geloescht->anzahl);
        self::assertSame([], $this->beitraege->imJahr(2026));
        self::assertNull($this->einzuege->finde($einzug26));
        self::assertNull($this->einzuege->finde($verworfen26));
        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM file_blob'), 'only the file of 2025 is left');
        self::assertSame(1, $this->blobDateien(), 'the encrypted files of 2026 are gone from disk');

        // 2025 is untouched: its fee, its run, its file - byte for byte readable.
        $vorjahr = $this->beitraege->finde($a25);
        self::assertSame(JahresbeitragStatus::Bezahlt, $vorjahr?->status);
        self::assertSame($einzug25, $vorjahr->einzugId);
        $run25 = $this->einzuege->finde($einzug25);
        self::assertNotNull($run25);
        self::assertSame($datei25, $run25->dateiBlobId);
        $blob = $this->einzugService->datei($run25);
        self::assertNotNull($blob);
        self::assertStringContainsString('<MsgId>E' . $einzug25 . '-', implode('', iterator_to_array($this->einzugService->dateiLesen($blob), false)));

        // The audit entry: the year and figures, no member data.
        $zeile = $this->pdo()->query("SELECT user_id, entity, entity_id, details FROM audit_log WHERE action = 'beitraege.jahr_geloescht'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($zeile);
        self::assertSame(['7', 'beitragsjahr', '2026'], [(string) $zeile['user_id'], $zeile['entity'], (string) $zeile['entity_id']]);
        self::assertSame(
            ['jahr' => 2026, 'beitraege' => 3, 'bezahlt' => 2, 'summe_cent' => 15500, 'einzuege' => [$verworfen26, $einzug26], 'dateien' => 2],
            json_decode((string) $zeile['details'], true),
        );
        self::assertStringNotContainsString('Albrecht', (string) $zeile['details']);

        // The year can be created again.
        self::assertSame(1, $this->jahresbeitraege->erzeugen(2026, 7)->neu);
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege->fuer((int) $anna->id, 2026)?->status);
    }

    public function testARunThatAlsoHoldsAnotherYearStays(): void
    {
        $anna = $this->mitglied('Albrecht');
        $a25 = $this->beitrag($anna, 2025, 4500);
        $a26 = $this->beitrag($anna, 2026);
        $gemischt = $this->einzug([$a25, $a26]);

        $umfang = $this->loeschen->loeschen(2026, 'LÖSCHEN', 7);

        self::assertSame([], $umfang->einzuege);
        self::assertSame([$gemischt], array_map(static fn($e): int => (int) $e->id, $umfang->gemischt));
        self::assertNull($this->beitraege->finde($a26));
        self::assertSame($gemischt, $this->beitraege->finde($a25)?->einzugId, 'the other year still points to its run');
        self::assertNotNull($this->einzuege->finde($gemischt));
        self::assertSame(1, $this->blobDateien());
    }

    public function testAYearWithoutAnythingCannotBeDeleted(): void
    {
        $this->beitrag($this->mitglied('Albrecht'), 2025);

        $this->expectException(BeitragNichtAenderbar::class);
        $this->loeschen->loeschen(2026, 'LÖSCHEN', 7);
    }

    public function testMarkingSeveralAsPaidChangesExactlyTheChosenOnes(): void
    {
        $ids = [];
        foreach (['Albrecht', 'Brandt', 'Christ', 'Dorn'] as $name) {
            $ids[$name] = $this->beitrag($this->mitglied($name), 2026);
        }

        $bezahlt = $this->jahresbeitraege->mehrereBezahltVonHand(
            [$ids['Brandt'], $ids['Albrecht'], $ids['Brandt']],
            Zahlweise::Ueberweisung,
            new \DateTimeImmutable('2026-04-01'),
            'Kontoauszug 4',
            7,
        );

        self::assertSame([$ids['Brandt'], $ids['Albrecht']], array_map(static fn($b): int => (int) $b->id, $bezahlt));
        foreach (['Albrecht', 'Brandt'] as $name) {
            $beitrag = $this->beitraege->finde($ids[$name]);
            self::assertSame(JahresbeitragStatus::Bezahlt, $beitrag?->status, $name);
            self::assertSame(Zahlweise::Ueberweisung, $beitrag->zahlweise);
            self::assertSame('2026-04-01', $beitrag->bezahltAm?->format('Y-m-d'));
            self::assertSame('Bezahlt (Überweisung) am 01.04.2026: Kontoauszug 4', $beitrag->vermerke[0]->text);
            self::assertSame(7, $beitrag->vermerke[0]->von);
        }
        foreach (['Christ', 'Dorn'] as $name) {
            self::assertSame(JahresbeitragStatus::Offen, $this->beitraege->finde($ids[$name])?->status, $name);
        }
    }

    public function testMarkingSeveralAsPaidChangesNothingWhenOneIsNoLongerOpen(): void
    {
        $offen = $this->beitrag($this->mitglied('Albrecht'), 2026);
        $imEinzug = $this->beitrag($this->mitglied('Brandt'), 2026);
        $this->einzug([$imEinzug]);

        foreach ([[$offen, $imEinzug], [$offen, 999999], []] as $auswahl) {
            try {
                $this->jahresbeitraege->mehrereBezahltVonHand($auswahl, Zahlweise::Bar, new \DateTimeImmutable('2026-04-01'), '', 7);
                self::fail('changed with ' . json_encode($auswahl));
            } catch (BeitragNichtAenderbar) {
            }
        }

        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege->finde($offen)?->status);
        self::assertSame([], $this->beitraege->finde($offen)->vermerke);
        self::assertSame(JahresbeitragStatus::ImEinzug, $this->beitraege->finde($imEinzug)?->status);
    }

    private function aktivId(): int
    {
        return (int) $this->pdo()->query("SELECT id FROM beitragsart WHERE name = 'Aktiv'")->fetchColumn();
    }
}
