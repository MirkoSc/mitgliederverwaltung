<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAktion;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Installer\ConfigWriter;
use App\Repository\BlobRepository;
use App\Repository\SchluesselwechselRepository;
use App\Repository\SettingRepository;
use App\Repository\ZweiterFaktorRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Schluesselwechsel;
use App\Service\Crypto\SchluesselwechselNichtMoeglich;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailException;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\ServerKeyGeheimnisSchutz;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/**
 * Changing the server key (issue #80) against a real database and a real
 * config.php: afterwards every file, authenticator secret and the SMTP
 * password open under the new key and nothing under the old one - and at
 * every point in between, however the chain is broken off, everything
 * stays readable with what config.php holds.
 */
final class SchluesselwechselTest extends DatabaseTestCase
{
    private const string APP_GEHEIMNIS = 'JBSWY3DPEHPK3PXP-geheim';

    private const string SMTP_PASSWORT = 'smtp-passwort-erfunden';

    private string $wurzel;

    private string $config;

    private string $alterSchluessel;

    private \DateTimeImmutable $jetzt;

    private bool $backupLaeuft = false;

    private int $benutzer;

    /** @var array<int, string> blob id => content */
    private array $dateien = [];

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->wurzel = sys_get_temp_dir() . '/mv_schluessel_' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/shared/var/blobs', 0775, true);
        $this->config = $this->wurzel . '/shared/config.php';
        $this->alterSchluessel = random_bytes(ServerCrypto::KEY_BYTES);
        ConfigWriter::schreiben($this->config, ['host' => 'db', 'port' => 3306, 'name' => 'x', 'user' => 'x', 'password' => 'x'], base64_encode($this->alterSchluessel));
        $this->jetzt = new \DateTimeImmutable();

        $crypto = new ServerCrypto($this->alterSchluessel);
        foreach ([BlobStorage::Fs, BlobStorage::Db, BlobStorage::Fs] as $i => $speicher) {
            $inhalt = 'Nachweis ' . $i . ' ' . str_repeat('x', 100 * $i);
            $this->dateien[$this->blobs($crypto)->storeString($inhalt, new BlobMeta('application/pdf', 'scan.pdf'), $speicher)->id] = $inhalt;
        }

        $this->benutzer = Benutzerdaten::anlegen($this->pdo());
        $faktoren = new ZweiterFaktorRepository($this->pdo());
        $faktoren->speichereApp($this->benutzer, $crypto->encrypt(self::APP_GEHEIMNIS), 0, $this->jetzt);
        $vorher = $this->jetzt->modify('-1 hour');
        $faktoren->ersetzeBackupCodes($this->benutzer, [$crypto->hmac('alt-1'), $crypto->hmac('alt-2')], $vorher);
        $faktoren->merkeGeraet($this->benutzer, $crypto->hmac('geraet'), $this->jetzt->modify('+30 days'), $vorher);

        $this->mail(new ServerKeyGeheimnisSchutz($this->alterSchluessel))->speichern(
            'smtp.example.org', 587, SmtpSecurity::Starttls, 'verein', self::SMTP_PASSWORT,
            'verein@example.org', '', 'Sportverein Beispielstadt', 'https://mitglieder.example.org/',
        );
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->wurzel, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $datei) {
            if ($datei instanceof \SplFileInfo) {
                $datei->isDir() ? rmdir($datei->getPathname()) : unlink($datei->getPathname());
            }
        }
        rmdir($this->wurzel);
        parent::tearDown();
    }

    public function testAfterTheChangeEverythingOpensWithTheNewKeyAndNothingWithTheOld(): void
    {
        $wechsel = $this->wechsel();
        $wechsel->starten($this->benutzer);
        // a backup code set up under the new key while the chain runs
        $neuerCode = new ServerCrypto(ConfigWriter::schluesselLesen($this->config)[0])->hmac('neu');
        $this->pdo()->prepare('INSERT INTO mfa_backup_code (user_id, code_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$this->benutzer, $neuerCode, $this->jetzt->modify('+1 minute')->format('Y-m-d H:i:s')]);

        $ausgabe = $this->durchlaufen($wechsel);

        [$neu, $alt] = ConfigWriter::schluesselLesen($this->config);
        self::assertNull($alt, 'the old key is gone from config.php');
        self::assertNotSame($this->alterSchluessel, $neu);
        self::assertSame(base64_encode($neu), $ausgabe, 'the new key is shown at the end');
        self::assertFalse($wechsel->laeuft());
        self::assertSame(Schluesselwechsel::PHASE_FERTIG, $wechsel->stand()['phase'] ?? null);
        self::assertSame(['dateien' => 3, 'app' => 1, 'smtp' => 1], $wechsel->stand()['umgeschluesselt'] ?? null);

        $this->assertAllesLesbar(new ServerCrypto($neu), new ServerKeyGeheimnisSchutz($neu));
        $this->assertNichtsLesbar(new ServerCrypto($this->alterSchluessel), new ServerKeyGeheimnisSchutz($this->alterSchluessel));

        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM mfa_backup_code')?->fetchColumn(), 'only the code made under the new key is left');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM trusted_device')?->fetchColumn());
        self::assertSame(
            [AuditAktion::SchluesselwechselBegonnen->value, AuditAktion::SchluesselGewechselt->value],
            $this->pdo()->query('SELECT action FROM audit_log ORDER BY id')?->fetchAll(\PDO::FETCH_COLUMN),
        );
        $stand = (string) file_get_contents($this->wurzel . '/shared/var/schluesselwechsel.json');
        self::assertStringNotContainsString(base64_encode($neu), $stand, 'no key in the state file');
        self::assertStringNotContainsString(base64_encode($this->alterSchluessel), $stand);
    }

    public function testBrokenOffAtAnyPointEverythingStaysReadableAndTheChainGoesOn(): void
    {
        $this->wechsel()->starten($this->benutzer);
        $schritte = 0;
        do {
            // every step a fresh service, as a fresh request would build it,
            // and once the state file lost on top
            if ($schritte === 3) {
                @unlink($this->wurzel . '/shared/var/schluesselwechsel.json');
            }
            [$neu, $alt] = ConfigWriter::schluesselLesen($this->config);
            $this->assertAllesLesbar(new ServerCrypto($neu, $alt), new ServerKeyGeheimnisSchutz($neu, $alt));
            $this->wechsel()->schritt();
            self::assertLessThan(50, ++$schritte, 'the chain ends');
        } while ($this->wechsel()->laeuft());

        [$neu, $alt] = ConfigWriter::schluesselLesen($this->config);
        self::assertNull($alt);
        $this->assertAllesLesbar(new ServerCrypto($neu), new ServerKeyGeheimnisSchutz($neu));
    }

    public function testACrashRightAfterTheFinalWriteOfConfigIsRecordedOnTheNextStep(): void
    {
        $wechsel = $this->wechsel(gnadenfrist: 60);
        $wechsel->starten($this->benutzer);
        $this->bisWarten($wechsel);
        $this->jetzt = $this->jetzt->modify('+61 seconds');
        // the final pass, cut off right after config.php lost the old key
        // and before the state file says so
        for ($i = 0; $i < 50; $i++) {
            $stand = $wechsel->stand();
            [$neu] = ConfigWriter::schluesselLesen($this->config);
            if (($stand['durchgang'] ?? 0) === 2 && $stand['bereich'] === 'smtp') {
                ConfigWriter::schluesselSchreiben($this->config, $neu, null);
                break;
            }
            $wechsel->schritt();
        }
        self::assertLessThan(50, $i);

        self::assertSame(base64_encode($neu), $wechsel->schritt());
        self::assertSame(Schluesselwechsel::PHASE_FERTIG, $wechsel->stand()['phase'] ?? null);
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'server_schluessel.gewechselt'")?->fetchColumn());
        $this->assertAllesLesbar(new ServerCrypto($neu), new ServerKeyGeheimnisSchutz($neu));
        self::assertNull($wechsel->schritt(), 'a finished change does nothing more');
    }

    public function testAValueWrittenUnderTheOldKeyMeanwhileIsCaughtByTheFinalPass(): void
    {
        $wechsel = $this->wechsel(gnadenfrist: 60);
        $wechsel->starten($this->benutzer);
        $this->bisWarten($wechsel);

        // a request that read config.php before the change stores a file
        $spaet = $this->blobs(new ServerCrypto($this->alterSchluessel))->storeString('spät', new BlobMeta('application/pdf', 'spaet.pdf'));
        $this->dateien[$spaet->id] = 'spät';

        $this->jetzt = $this->jetzt->modify('+30 seconds');
        $wechsel->schritt();
        self::assertTrue($wechsel->laeuft(), 'the old key stays until the waiting time is over');
        self::assertSame(Schluesselwechsel::PHASE_WARTEN, $wechsel->stand()['phase'] ?? null);
        self::assertSame(30, $wechsel->wartezeit((array) $wechsel->stand()));

        $this->jetzt = $this->jetzt->modify('+31 seconds');
        $this->durchlaufen($wechsel);

        [$neu, $alt] = ConfigWriter::schluesselLesen($this->config);
        self::assertNull($alt);
        $this->assertAllesLesbar(new ServerCrypto($neu), new ServerKeyGeheimnisSchutz($neu));
    }

    public function testStartingAgainContinuesInsteadOfMakingAnotherKey(): void
    {
        $wechsel = $this->wechsel(gnadenfrist: 60);
        $wechsel->starten($this->benutzer);
        $wechsel->schritt();
        $vorher = ConfigWriter::schluesselLesen($this->config);
        $stand = $wechsel->stand();

        $wechsel->starten($this->benutzer);

        self::assertSame($vorher, ConfigWriter::schluesselLesen($this->config));
        self::assertSame($stand['seit'] ?? null, $wechsel->stand()['seit'] ?? null, 'the waiting time is not restarted');
        self::assertSame($this->alterSchluessel, $vorher[1]);
    }

    public function testNoStartWhileABackupIsBeingMade(): void
    {
        $this->backupLaeuft = true;

        try {
            $this->wechsel()->starten($this->benutzer);
            self::fail('expected the start to be refused');
        } catch (SchluesselwechselNichtMoeglich $e) {
            self::assertStringContainsString('Backup', $e->getMessage());
        }
        self::assertSame([$this->alterSchluessel, null], ConfigWriter::schluesselLesen($this->config));
    }

    public function testTheChainWaitsForARunningBackupBeforeItDropsTheOldKey(): void
    {
        $wechsel = $this->wechsel();
        $wechsel->starten($this->benutzer);
        $this->backupLaeuft = true;
        for ($i = 0; $i < 10; $i++) {
            $wechsel->schritt();
        }
        self::assertTrue($wechsel->laeuft());

        $this->backupLaeuft = false;
        $this->durchlaufen($wechsel);
        self::assertFalse($wechsel->laeuft());
    }

    public function testWithoutAChangeAStepDoesNothing(): void
    {
        self::assertNull($this->wechsel()->schritt());
        self::assertNull($this->wechsel()->stand());
        self::assertSame([$this->alterSchluessel, null], ConfigWriter::schluesselLesen($this->config));
    }

    public function testAValueNeitherKeyOpensIsCountedAndLeftAlone(): void
    {
        $fremd = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES))->encrypt(random_bytes(32));
        $id = array_key_first($this->dateien);
        $this->pdo()->prepare('UPDATE file_blob SET key_enc = ? WHERE id = ?')->execute([$fremd, $id]);
        unset($this->dateien[$id]);

        $wechsel = $this->wechsel();
        $wechsel->starten($this->benutzer);
        $this->durchlaufen($wechsel);

        self::assertSame(1, $wechsel->stand()['unlesbar'] ?? null);
        self::assertSame($fremd, $this->pdo()->query('SELECT key_enc FROM file_blob WHERE id = ' . $id)?->fetchColumn());
        [$neu] = ConfigWriter::schluesselLesen($this->config);
        $this->assertAllesLesbar(new ServerCrypto($neu), new ServerKeyGeheimnisSchutz($neu));
    }

    // ------------------------------------------------------------ helpers

    private function wechsel(int $gnadenfrist = 0): Schluesselwechsel
    {
        return new Schluesselwechsel(
            configDatei: $this->config,
            statusDir: $this->wurzel . '/shared/var',
            repo: new SchluesselwechselRepository($this->pdo()),
            audit: new AuditLog($this->pdo(), $this->alterSchluessel),
            backupLaeuft: fn(): bool => $this->backupLaeuft,
            clock: fn(): \DateTimeImmutable => $this->jetzt,
            gnadenfrist: $gnadenfrist,
            // two values and one query per step: the chain takes many steps
            portion: 2,
            portionSekunden: 0.0,
        );
    }

    private function durchlaufen(Schluesselwechsel $wechsel): ?string
    {
        for ($i = 0; $i < 50 && $wechsel->laeuft(); $i++) {
            $schluessel = $wechsel->schritt();
            if ($schluessel !== null) {
                return $schluessel;
            }
        }
        self::fail('the chain did not end');
    }

    private function bisWarten(Schluesselwechsel $wechsel): void
    {
        for ($i = 0; $i < 50 && ($wechsel->stand()['phase'] ?? null) !== Schluesselwechsel::PHASE_WARTEN; $i++) {
            $wechsel->schritt();
        }
        self::assertSame(Schluesselwechsel::PHASE_WARTEN, $wechsel->stand()['phase'] ?? null);
    }

    private function blobs(ServerCrypto $crypto): BlobService
    {
        $repo = new BlobRepository($this->pdo());

        return new BlobService($repo, new DbBlobBackend($repo), new FsBlobBackend($this->wurzel . '/shared/var/blobs'), $crypto);
    }

    private function mail(ServerKeyGeheimnisSchutz $schutz): MailSettingsRepository
    {
        return new MailSettingsRepository(new SettingRepository($this->pdo()), $schutz);
    }

    private function assertAllesLesbar(ServerCrypto $crypto, ServerKeyGeheimnisSchutz $schutz): void
    {
        $blobs = $this->blobs($crypto);
        foreach ($this->dateien as $id => $inhalt) {
            $blob = $blobs->find($id);
            self::assertNotNull($blob);
            self::assertSame($inhalt, implode('', iterator_to_array($blobs->openRead($blob), false)));
        }
        $geheimnis = new ZweiterFaktorRepository($this->pdo())->appGeheimnis($this->benutzer);
        self::assertSame(self::APP_GEHEIMNIS, $crypto->decrypt((string) $geheimnis));
        self::assertSame(self::SMTP_PASSWORT, $this->mail($schutz)->get()->passwort);
    }

    private function assertNichtsLesbar(ServerCrypto $crypto, ServerKeyGeheimnisSchutz $schutz): void
    {
        $blobs = $this->blobs($crypto);
        foreach (array_keys($this->dateien) as $id) {
            $blob = $blobs->find($id);
            self::assertNotNull($blob);
            try {
                iterator_to_array($blobs->openRead($blob), false);
                self::fail('a file opened under the old key');
            } catch (CryptoException) {
            }
        }
        try {
            $crypto->decrypt((string) new ZweiterFaktorRepository($this->pdo())->appGeheimnis($this->benutzer));
            self::fail('the authenticator secret opened under the old key');
        } catch (CryptoException) {
        }
        try {
            $schutz->oeffnen((string) new SchluesselwechselRepository($this->pdo())->smtpPasswort());
            self::fail('the SMTP password opened under the old key');
        } catch (MailException) {
        }
    }
}
