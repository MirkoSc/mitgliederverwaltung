<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAktion;
use App\Domain\BlobStorage;
use App\Domain\Einzug;
use App\Domain\EinzugStatus;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlart;
use App\Domain\Zahlweise;
use App\Repository\BlobRepository;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Service\Beitraege\EinzugNichtMoeglich;
use App\Service\Beitraege\Einzuege;
use App\Service\Beitraege\Jahresbeitraege;
use App\Service\Beitraege\Sepa\Pain008;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Tests\Service\Beitraege\Pain008Test;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The direct debit against the real database and blob storage
 * (docs/spec/08-beitraege.md sections 3-6, "Pflicht-Tests"): compose,
 * create with the encrypted file, download the same bytes forever,
 * confirm, discard, archive. Every name, IBAN and the creditor identifier
 * are made up.
 */
final class EinzuegeTest extends DatabaseTestCase
{
    private const string ANNA = 'DE02120300000000202051';

    private const string BERT = 'DE12500105170648489890';

    private const string CARLA = 'DE89370400440532013000';

    private const string VEREIN = 'DE75512108001245126199';

    private string $blobDir;

    private Einzuege $service;

    private BlobService $blobs;

    private JahresbeitragRepository $beitraege;

    private MitgliedRepository $mitglieder;

    private EinzugRepository $einzuege;

    private int $nummer = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->blobDir = sys_get_temp_dir() . '/mv_einzug_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->aufbauen(BlobStorage::Fs);
        $this->vereinsdaten();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->blobDir . '/*') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->blobDir);
        parent::tearDown();
    }

    private function aufbauen(BlobStorage $speicher): void
    {
        $pdo = $this->pdo();
        $blobRepo = new BlobRepository($pdo);
        $this->blobs = new BlobService(
            $blobRepo,
            new DbBlobBackend($blobRepo),
            new FsBlobBackend($this->blobDir),
            new ServerCrypto(str_repeat('k', ServerCrypto::KEY_BYTES)),
            $speicher,
        );
        $this->beitraege = new JahresbeitragRepository($pdo);
        $this->mitglieder = new MitgliedRepository($pdo);
        $this->einzuege = new EinzugRepository($pdo);
        $this->service = new Einzuege(
            $pdo,
            $this->einzuege,
            $this->beitraege,
            $this->mitglieder,
            new VereinRepository($pdo, new SettingRepository($pdo)),
            $this->blobs,
            new AuditLog($pdo, str_repeat('a', 32)),
        );
    }

    /**
     * @param array<string, string> $felder
     */
    private function vereinsdaten(array $felder = []): void
    {
        new VereinRepository($this->pdo(), new SettingRepository($this->pdo()))->speichern(Vereinsdaten::ausGespeichertem([
            'vereinsname' => 'TSV Beispielstadt 1899 e.V.',
            'iban' => self::VEREIN,
            'bic' => 'BYLADEM1SWU',
            'glaeubiger_id' => Pain008Test::GLAEUBIGER_ID,
            'mandatsdatum' => '2016-01-01',
            ...$felder,
        ]));
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function mitglied(string $nachname, array $felder = []): Mitglied
    {
        $mitglied = new Mitglied(++$this->nummer, $nachname, vorname: 'Erika', eintrittsdatum: new \DateTimeImmutable('2015-03-01'), iban: self::ANNA)->mit($felder);

        return $this->mitglieder->speichern($mitglied, VersionsAnlass::angelegt(), 1)->mitglied;
    }

    private function beitrag(Mitglied $mitglied, int $cent = 8500, int $jahr = 2026): int
    {
        return (int) $this->beitraege->anlegenWennFehlt(new Jahresbeitrag((int) $mitglied->id, $jahr, $cent))?->id;
    }

    private static function jetzt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-02-10 10:15:00.123456');
    }

    /**
     * @param list<int> $ids
     */
    private function erzeugen(array $ids, string $faellig = '2026-02-13'): Einzug
    {
        return $this->service->erzeugen($ids, new \DateTimeImmutable($faellig), 7, self::jetzt());
    }

    private function datei(int $einzugId): string
    {
        $einzug = $this->einzuege->finde($einzugId);
        self::assertNotNull($einzug);
        $blob = $this->service->datei($einzug);
        self::assertNotNull($blob);

        return implode('', iterator_to_array($this->service->dateiLesen($blob), false));
    }

    private function anzahl(string $tabelle): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }

    public function testComposingSeparatesCollectableFromSelfPayersAndMissingIbans(): void
    {
        $anna = $this->beitrag($this->mitglied('Albrecht'));
        $vorjahr = $this->beitrag($this->mitglied('Albrecht', ['vorname' => 'Zoe']), 4500, 2025);
        $selbst = $this->beitrag($this->mitglied('Selbmann', ['zahlart' => Zahlart::Selbst]));
        $ohne = $this->beitrag($this->mitglied('Ohnesorg', ['iban' => '']));
        $falsch = $this->beitrag($this->mitglied('Falk', ['iban' => 'DE02120300000000202052']));
        $ohneMandat = $this->beitrag($this->mitglied('Neumann', ['eintrittsdatum' => null]));
        $bezahlt = $this->beitrag($this->mitglied('Zahl'));
        $schonBezahlt = $this->beitraege->finde($bezahlt);
        self::assertNotNull($schonBezahlt);
        $this->beitraege->speichern($schonBezahlt->mit(['status' => JahresbeitragStatus::Bezahlt]));
        // Without a default mandate date in the club data, the member
        // without entry date has no mandate date at all.
        $this->vereinsdaten(['mandatsdatum' => '']);

        $auswahl = $this->service->zusammenstellen(new \DateTimeImmutable('2026-02-10'));

        self::assertSame([$anna, $vorjahr], array_map(static fn($k): int => (int) $k->beitrag->id, $auswahl->auswaehlbar));
        self::assertSame(13000, $auswahl->summeCent());
        self::assertSame([2025, 2026], $auswahl->jahre());
        $gruende = [];
        foreach ($auswahl->nichtAuswaehlbar as $k) {
            $gruende[(int) $k->beitrag->id] = [$k->grund, $k->selbstKassieren];
        }
        self::assertSame([$falsch, $ohneMandat, $ohne, $selbst], array_keys($gruende), 'by name');
        self::assertSame(['zahlt selbst (Zahlart „selbst“)', true], $gruende[$selbst]);
        self::assertSame(['keine IBAN', true], $gruende[$ohne]);
        self::assertSame(['IBAN ungültig (Prüfziffer)', true], $gruende[$falsch]);
        self::assertFalse($gruende[$ohneMandat][1]);
        self::assertStringContainsString('kein Mandatsdatum', (string) $gruende[$ohneMandat][0]);
        self::assertSame('2026-02-12', $auswahl->vorschlagFaelligkeit->format('Y-m-d'), 'Tuesday: next bank working day + 1');
        self::assertSame(Einzuege::VERWENDUNGSZWECK, $auswahl->verwendungszweck);
        self::assertTrue($auswahl->moeglich());
    }

    public function testCreatingStoresTheFileEncryptedOnceAndPutsTheFeesIntoTheRun(): void
    {
        $a = $this->beitrag($this->mitglied('Albrecht', ['vorname' => 'Anna']), 8500);
        $b = $this->beitrag($this->mitglied('Brandt', ['vorname' => 'Bert', 'iban' => self::BERT]), 4500);
        $c = $this->beitrag($this->mitglied('Christ', ['vorname' => 'Carla', 'iban' => self::CARLA]), 7083, 2025);

        $einzug = $this->erzeugen([$b, $a, $c]);

        $id = (int) $einzug->id;
        self::assertSame(EinzugStatus::Erzeugt, $einzug->status);
        self::assertSame([3, 20083], [$einzug->anzahl, $einzug->summeCent]);
        self::assertSame(sprintf('E%d-20260210101500', $id), $einzug->msgId);
        self::assertNotNull($einzug->dateiBlobId);

        $gespeichert = $this->einzuege->finde($id);
        self::assertNotNull($gespeichert);
        self::assertSame('2026-02-10 10:15:00', $gespeichert->erstelltAm->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-13', $gespeichert->faelligAm->format('Y-m-d'));
        self::assertSame(7, $gespeichert->erstelltVon);
        self::assertSame($einzug->dateiBlobId, $gespeichert->dateiBlobId);
        self::assertSame(['Albrecht, Anna', 'Brandt, Bert', 'Christ, Carla'], array_map(static fn($p): string => $p->name, $gespeichert->posten()));
        self::assertSame([sprintf('E%d-1001-2026', $id), sprintf('E%d-1002-2026', $id), sprintf('E%d-1003-2025', $id)], array_map(static fn($p): string => $p->endToEndId, $gespeichert->posten()));
        self::assertSame([8500, 4500, 7083], array_map(static fn($p): int => $p->betragCent, $gespeichert->posten()));

        foreach ([$a, $b, $c] as $beitragId) {
            $beitrag = $this->beitraege->finde($beitragId);
            self::assertSame(JahresbeitragStatus::ImEinzug, $beitrag?->status);
            self::assertSame($id, $beitrag->einzugId);
            self::assertSame(sprintf('In Einzug Nr. %d aufgenommen, fällig am 13.02.2026', $id), $beitrag->vermerke[0]->text);
            self::assertSame(7, $beitrag->vermerke[0]->von);
        }

        // Stored encrypted: neither names nor IBANs readable in the stored bytes.
        $blob = $this->blobs->find((int) $einzug->dateiBlobId);
        self::assertNotNull($blob);
        self::assertSame('application/xml', $blob->mimeType);
        self::assertSame(sprintf('lastschrift-2026-%d.xml', $id), $blob->originalName);
        $chiffrat = implode('', iterator_to_array($this->blobs->readCipher($blob), false));
        foreach (['Albrecht', self::ANNA, 'CstmrDrctDbtInitn', Pain008Test::GLAEUBIGER_ID] as $klartext) {
            self::assertStringNotContainsString($klartext, $chiffrat);
        }

        $xml = $this->datei($id);
        self::assertSame([], Pain008Test::fehler($xml));
        $wert = Pain008Test::xpath($xml);
        self::assertSame('3', $wert('//p:GrpHdr/p:NbOfTxs'));
        self::assertSame('200.83', $wert('//p:GrpHdr/p:CtrlSum'));
        self::assertSame('200.83', $wert('//p:PmtInf/p:CtrlSum'));
        self::assertSame('3', (string) $wert('count(//p:DrctDbtTxInf)'));
        self::assertSame('2026-02-13', $wert('//p:ReqdColltnDt'));
        self::assertSame('TSV Beispielstadt 1899 e.V.', $wert('//p:Cdtr/p:Nm'));
        self::assertSame(self::VEREIN, $wert('//p:CdtrAcct/p:Id/p:IBAN'));
        self::assertSame('BYLADEM1SWU', $wert('//p:CdtrAgt/p:FinInstnId/p:BICFI'));
        self::assertSame(Pain008Test::GLAEUBIGER_ID, $wert('//p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:Id'));
        self::assertSame('1001', $wert('//p:DrctDbtTxInf[1]/p:DrctDbtTx/p:MndtRltdInf/p:MndtId'), 'default: the member number');
        self::assertSame('2015-03-01', $wert('//p:DrctDbtTxInf[1]/p:DrctDbtTx/p:MndtRltdInf/p:DtOfSgntr'), 'default: the entry date');
        self::assertSame('Mitgliedsbeitrag 2025 Nr. 1003 Carla Christ', $wert('//p:DrctDbtTxInf[3]/p:RmtInf/p:Ustrd'));
        self::assertSame(1, $this->anzahl('file_blob'));
        self::assertCount(1, glob($this->blobDir . '/*') ?: []);
    }

    public function testAccountHolderUmlautsBicAndOwnMandateGoIntoTheFile(): void
    {
        $mit = $this->beitrag($this->mitglied('Müller', [
            'vorname' => 'Jürgen',
            'kontoinhaber' => 'Käthe Groß-Österreicher',
            'bic' => 'cobade ffxxx',
            'mandatsreferenz' => 'MÜ-0815',
            'mandatsdatum' => new \DateTimeImmutable('2020-07-01'),
        ]));
        $ohne = $this->beitrag($this->mitglied('Weiß', ['vorname' => 'Åsa', 'iban' => self::BERT, 'eintrittsdatum' => null]));
        $this->vereinsdaten(['kontoinhaber' => 'Förderverein TSV & Freunde', 'bic' => '', 'verwendungszweck' => 'Beitrag {jahr} – {name} ({mitgliedsnummer})']);

        $einzug = $this->erzeugen([$mit, $ohne]);
        $xml = $this->datei((int) $einzug->id);

        self::assertSame([], Pain008Test::fehler($xml));
        $wert = Pain008Test::xpath($xml);
        self::assertSame('Foerderverein TSV + Freunde', $wert('//p:Cdtr/p:Nm'), 'account holder before club name, transliterated');
        self::assertSame('NOTPROVIDED', $wert('//p:CdtrAgt/p:FinInstnId/p:Othr/p:Id'));
        self::assertSame('Kaethe Gross-Oesterreicher', $wert('//p:DrctDbtTxInf[1]/p:Dbtr/p:Nm'), 'the different account holder');
        self::assertSame('COBADEFFXXX', $wert('//p:DrctDbtTxInf[1]/p:DbtrAgt/p:FinInstnId/p:BICFI'));
        self::assertSame('MUe-0815', $wert('//p:DrctDbtTxInf[1]/p:DrctDbtTx/p:MndtRltdInf/p:MndtId'));
        self::assertSame('2020-07-01', $wert('//p:DrctDbtTxInf[1]/p:DrctDbtTx/p:MndtRltdInf/p:DtOfSgntr'));
        self::assertSame('Beitrag 2026 - Juergen Mueller (1001)', $wert('//p:DrctDbtTxInf[1]/p:RmtInf/p:Ustrd'), 'the member, not the account holder');
        self::assertSame('Asa Weiss', $wert('//p:DrctDbtTxInf[2]/p:Dbtr/p:Nm'));
        self::assertSame('NOTPROVIDED', $wert('//p:DrctDbtTxInf[2]/p:DbtrAgt/p:FinInstnId/p:Othr/p:Id'));
        self::assertSame('2016-01-01', $wert('//p:DrctDbtTxInf[2]/p:DrctDbtTx/p:MndtRltdInf/p:DtOfSgntr'), 'no entry date: the club default');
    }

    #[DataProvider('speicher')]
    public function testEveryDownloadIsTheSameFileEvenAfterTheMemberChanged(BlobStorage $speicher): void
    {
        $this->aufbauen($speicher);
        $mitglied = $this->mitglied('Albrecht', ['vorname' => 'Anna']);
        $einzug = $this->erzeugen([$this->beitrag($mitglied)]);
        $erste = $this->datei((int) $einzug->id);

        $this->mitglieder->speichern(
            $mitglied->mit(['nachname' => 'Neuname', 'iban' => self::CARLA, 'mandatsreferenz' => 'NEU-1']),
            VersionsAnlass::geaendert(),
            1,
        );
        $this->vereinsdaten(['vereinsname' => 'Ganz anderer Verein', 'iban' => self::BERT]);

        self::assertSame($erste, $this->datei((int) $einzug->id));
        self::assertSame($erste, $this->datei((int) $einzug->id), 'any number of times');
        self::assertStringContainsString('Anna Albrecht', $erste);
        self::assertStringContainsString(self::ANNA, $erste);
        self::assertStringNotContainsString('Neuname', $erste);
        self::assertSame('Albrecht, Anna', $this->einzuege->finde((int) $einzug->id)?->posten()[0]->name, 'the archive keeps its copy');
    }

    /**
     * @return iterable<string, array{BlobStorage}>
     */
    public static function speicher(): iterable
    {
        yield 'fs' => [BlobStorage::Fs];
        yield 'db' => [BlobStorage::Db];
    }

    public function testWithoutCreditorIdentifierOrIbanNothingIsCreated(): void
    {
        $beitrag = $this->beitrag($this->mitglied('Albrecht'));

        foreach ([
            ['glaeubiger_id' => ''],
            ['iban' => ''],
            ['iban' => 'DE75512108001245126198'],
            ['glaeubiger_id' => 'GLAEUBIGER'],
            ['vereinsname' => '', 'kontoinhaber' => ''],
        ] as $fehlend) {
            $this->vereinsdaten($fehlend);
            self::assertFalse($this->service->zusammenstellen()->moeglich());
            try {
                $this->erzeugen([$beitrag]);
                self::fail('expected a refusal');
            } catch (EinzugNichtMoeglich $e) {
                self::assertStringContainsString('Vereinsdaten', $e->getMessage());
            }
        }

        $this->vereinsdaten(['glaeubiger_id' => '', 'iban' => '']);
        self::assertSame(['die IBAN des Vereinskontos', 'die Gläubiger-ID'], $this->service->zusammenstellen()->fehlendeVereinsdaten);
        self::assertSame(0, $this->anzahl('einzug'));
        self::assertSame(0, $this->anzahl('file_blob'));
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege->finde($beitrag)?->status);
    }

    public function testAChoiceThatChangedMeanwhileCreatesNothing(): void
    {
        $offen = $this->beitrag($this->mitglied('Albrecht'));
        $bezahlt = $this->beitrag($this->mitglied('Brandt'));
        $selbst = $this->beitrag($this->mitglied('Christ', ['zahlart' => Zahlart::Selbst]));
        Jahresbeitraege::mit($this->pdo())->bezahltVonHand($bezahlt, Zahlweise::Bar, new \DateTimeImmutable('2026-02-01'), '', 1);

        foreach ([[$offen, $bezahlt], [$offen, $selbst], [$offen, 999999], []] as $auswahl) {
            try {
                $this->erzeugen($auswahl);
                self::fail('expected a refusal');
            } catch (EinzugNichtMoeglich $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        self::assertSame(0, $this->anzahl('einzug'));
        self::assertSame(0, $this->anzahl('file_blob'));
        self::assertSame([], glob($this->blobDir . '/*') ?: [], 'no file left behind by the rollback');
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege->finde($offen)?->status);
        self::assertSame([], $this->beitraege->finde($offen)->vermerke);
    }

    public function testConfirmingPaysEveryFeeOnTheDueDateOnce(): void
    {
        $a = $this->beitrag($this->mitglied('Albrecht'));
        $b = $this->beitrag($this->mitglied('Brandt'));
        $id = (int) $this->erzeugen([$a, $b])->id;

        $einzug = $this->service->bestaetigen($id, 9, new \DateTimeImmutable('2026-02-20 08:00:00'));

        self::assertSame(EinzugStatus::Bestaetigt, $einzug->status);
        self::assertSame(9, $einzug->bestaetigtVon);
        self::assertSame('2026-02-20 08:00:00', $einzug->bestaetigtAm?->format('Y-m-d H:i:s'));
        foreach ([$a, $b] as $beitragId) {
            $beitrag = $this->beitraege->finde($beitragId);
            self::assertSame(JahresbeitragStatus::Bezahlt, $beitrag?->status);
            self::assertSame('2026-02-13', $beitrag->bezahltAm?->format('Y-m-d'), 'paid on the due date');
            self::assertNull($beitrag->zahlweise);
            self::assertSame($id, $beitrag->einzugId);
            self::assertSame(sprintf('Bezahlt per Einzug Nr. %d am 13.02.2026', $id), $beitrag->vermerke[1]->text);
        }

        foreach ([fn() => $this->service->bestaetigen($id, 9), fn() => $this->service->verwerfen($id, 9)] as $nochmal) {
            try {
                $nochmal();
                self::fail('a closed run stays closed');
            } catch (EinzugNichtMoeglich $e) {
                self::assertSame(sprintf('Der Einzug Nr. %d ist schon bestätigt.', $id), $e->getMessage());
            }
        }
        self::assertSame(9, $this->einzuege->finde($id)?->bestaetigtVon);

        // A returned debit runs per fee (08 section 2); the fee can go into the next run.
        Jahresbeitraege::mit($this->pdo())->ruecklastschrift($a, 'Konto erloschen', 1);
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege->finde($a)?->status);
        $naechster = $this->erzeugen([$a], '2026-03-02');
        self::assertSame($naechster->id, $this->beitraege->finde($a)?->einzugId);
        self::assertSame(EinzugStatus::Bestaetigt, $this->einzuege->finde($id)?->status, 'the confirmed run is closed for good');
    }

    public function testDiscardingReopensTheFeesAndKeepsTheRunWithItsFileInTheArchive(): void
    {
        $a = $this->beitrag($this->mitglied('Albrecht'));
        $erster = $this->erzeugen([$a]);
        $datei = $this->datei((int) $erster->id);

        $verworfen = $this->service->verwerfen((int) $erster->id, 9);

        self::assertSame(EinzugStatus::Verworfen, $verworfen->status);
        self::assertSame(9, $verworfen->bestaetigtVon, 'who discarded');
        $beitrag = $this->beitraege->finde($a);
        self::assertSame(JahresbeitragStatus::Offen, $beitrag?->status);
        self::assertNull($beitrag->einzugId);
        self::assertSame(sprintf('Einzug Nr. %d verworfen, wieder offen', (int) $erster->id), $beitrag->vermerke[1]->text);

        $zweiter = $this->erzeugen([$a]);
        $archiv = $this->einzuege->alle();
        self::assertSame([$zweiter->id, $erster->id], array_map(static fn(Einzug $e): ?int => $e->id, $archiv));
        self::assertSame([EinzugStatus::Erzeugt, EinzugStatus::Verworfen], array_map(static fn(Einzug $e): EinzugStatus => $e->status, $archiv));
        self::assertSame($datei, $this->datei((int) $erster->id), 'the discarded file stays as it was');
        self::assertNotSame($datei, $this->datei((int) $zweiter->id), 'a new run, a new file');
    }

    public function testDeletingAMemberKeepsTheRunItsFileAndItsCopy(): void
    {
        $mitglied = $this->mitglied('Albrecht', ['vorname' => 'Anna']);
        $andere = $this->beitrag($this->mitglied('Brandt'));
        $einzug = $this->erzeugen([$this->beitrag($mitglied), $andere]);
        $datei = $this->datei((int) $einzug->id);

        $this->mitglieder->loeschen((int) $mitglied->id);
        $this->service->bestaetigen((int) $einzug->id, 9);

        $archiviert = $this->einzuege->finde((int) $einzug->id);
        self::assertSame(2, $archiviert?->anzahl);
        self::assertSame('Albrecht, Anna', $archiviert->posten()[0]->name);
        self::assertSame($datei, $this->datei((int) $einzug->id));
        self::assertSame(JahresbeitragStatus::Bezahlt, $this->beitraege->finde($andere)?->status);
    }

    public function testCreateConfirmAndDiscardAreAudited(): void
    {
        $a = $this->beitrag($this->mitglied('Albrecht'), 8500);
        $b = $this->beitrag($this->mitglied('Brandt'), 4500);
        $erster = $this->erzeugen([$a]);
        $zweiter = $this->erzeugen([$b]);
        $this->service->bestaetigen((int) $erster->id, 9);
        $this->service->verwerfen((int) $zweiter->id, 9);

        $audit = new AuditLog($this->pdo(), str_repeat('a', 32));
        $eintraege = array_reverse($audit->neueste(10));
        self::assertSame(
            [AuditAktion::EinzugErzeugt->value, AuditAktion::EinzugErzeugt->value, AuditAktion::EinzugBestaetigt->value, AuditAktion::EinzugVerworfen->value],
            array_map(static fn($e): string => $e->action, $eintraege),
        );
        self::assertSame((string) $erster->id, $eintraege[0]->entityId);
        self::assertSame(['anzahl' => 1, 'summe_cent' => 8500], json_decode($eintraege[0]->details, true));
        self::assertTrue($audit->pruefen()->intakt());
    }

    public function testTheRemittanceTemplateIsFilledAndCut(): void
    {
        $mitglied = new Mitglied(4711, 'Ölmann', vorname: 'Zoë');

        self::assertSame('Mitgliedsbeitrag 2026 Nr. 4711 Zoe Oelmann', Einzuege::verwendungszweck(Einzuege::VERWENDUNGSZWECK, 2026, $mitglied));
        self::assertSame(Pain008::ZWECK_LAENGE, strlen(Einzuege::verwendungszweck(str_repeat('{name} ', 20), 2026, $mitglied)));
    }
}
