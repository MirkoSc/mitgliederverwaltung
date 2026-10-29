<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\MfaMethode;
use App\Repository\BenutzerRepository;
use App\Repository\RateLimitRepository;
use App\Repository\ZweiterFaktorRepository;
use App\Service\Account\BackupCodes;
use App\Service\Account\Nachweis;
use App\Service\Account\Totp;
use App\Service\Account\ZweiterFaktor;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/**
 * The second factor against the real tables (docs/spec/02-sicherheit.md,
 * Pflicht-Tests: "2FA (TOTP-Einmalverwendung, E-Mail-Code-Limit,
 * Backup-Codes, Gerät merken)").
 */
final class ZweiterFaktorTest extends DatabaseTestCase
{
    private ZweiterFaktor $faktor;

    private ZweiterFaktorRepository $repo;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->repo = new ZweiterFaktorRepository($this->pdo());
        $this->faktor = new ZweiterFaktor(
            $this->repo,
            new BenutzerRepository($this->pdo()),
            new ServerCrypto(str_repeat('k', 32)),
            new RateLimiter(new RateLimitRepository($this->pdo())),
        );
        $this->id = Benutzerdaten::anlegen($this->pdo(), mfa: null);
    }

    private static function am(string $zeit): \DateTimeImmutable
    {
        return new \DateTimeImmutable($zeit);
    }

    /** @return array{string, list<string>} the secret and the backup codes */
    private function appEinrichten(\DateTimeImmutable $am): array
    {
        $geheimnis = $this->faktor->neuesAppGeheimnis();
        $codes = $this->faktor->richteAppEin($this->id, $geheimnis, Totp::code($geheimnis, $am), $am);
        self::assertNotNull($codes);

        return [$geheimnis, $codes];
    }

    private function methode(): ?MfaMethode
    {
        return new BenutzerRepository($this->pdo())->findById($this->id)?->mfaMethod;
    }

    public function testAnAppCodeWorksOnceAndTheSetupCodeNotAtAll(): void
    {
        $einrichtung = self::am('2026-03-01 10:00:00');
        [$geheimnis] = $this->appEinrichten($einrichtung);
        self::assertSame(MfaMethode::App, $this->methode());

        // The code that confirmed the setup is spent already.
        self::assertNull($this->faktor->pruefe($this->id, Totp::code($geheimnis, $einrichtung), $einrichtung->modify('+10 seconds')));

        $spaeter = self::am('2026-03-01 10:05:00');
        $code = Totp::code($geheimnis, $spaeter);
        self::assertSame(Nachweis::App, $this->faktor->pruefe($this->id, $code, $spaeter));
        self::assertNull($this->faktor->pruefe($this->id, $code, $spaeter), 'each time step once');
        self::assertNull($this->faktor->pruefe($this->id, $code, $spaeter->modify('+25 seconds')), 'not within the tolerance either');

        $danach = $spaeter->modify('+30 seconds');
        self::assertSame(Nachweis::App, $this->faktor->pruefe($this->id, Totp::code($geheimnis, $danach), $danach));
    }

    public function testAWrongFirstCodeSetsNothingUp(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        $geheimnis = $this->faktor->neuesAppGeheimnis();
        $falsch = Totp::code($geheimnis, $am->modify('+5 minutes'));

        self::assertNull($this->faktor->richteAppEin($this->id, $geheimnis, $falsch, $am));
        self::assertNull($this->methode());
        self::assertNull($this->repo->appGeheimnis($this->id));
    }

    public function testTheAppSecretIsStoredEncrypted(): void
    {
        [$geheimnis] = $this->appEinrichten(self::am('2026-03-01 10:00:00'));

        $gespeichert = (string) $this->repo->appGeheimnis($this->id);
        self::assertStringNotContainsString($geheimnis, $gespeichert);
        self::assertSame($geheimnis, new ServerCrypto(str_repeat('k', 32))->decrypt($gespeichert));
    }

    public function testAMailCodeWorksOnceWithinTenMinutes(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        $code = (string) $this->faktor->neuerMailCode($this->id, $am);
        self::assertMatchesRegularExpression('/^\d{6}$/D', $code);

        self::assertTrue($this->faktor->pruefeMailCode($this->id, $code, $am->modify('+9 minutes')));
        self::assertFalse($this->faktor->pruefeMailCode($this->id, $code, $am->modify('+9 minutes')), 'single use');

        $neu = (string) $this->faktor->neuerMailCode($this->id, $am);
        self::assertFalse($this->faktor->pruefeMailCode($this->id, $neu, $am->modify('+10 minutes')), 'expired after 10 minutes');
    }

    /** "max. 5 Versuche": after five wrong tries even the right code is dead. */
    public function testAMailCodeDiesAfterFiveWrongTries(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        $code = (string) $this->faktor->neuerMailCode($this->id, $am);
        $falsch = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->faktor->pruefeMailCode($this->id, $falsch, $am));
        }
        self::assertFalse($this->faktor->pruefeMailCode($this->id, $code, $am));

        // A fresh code starts with five tries again.
        $neu = (string) $this->faktor->neuerMailCode($this->id, $am);
        self::assertFalse($this->faktor->pruefeMailCode($this->id, $code === $neu ? $falsch : $code, $am));
        self::assertTrue($this->faktor->pruefeMailCode($this->id, $neu, $am));
    }

    public function testOnlyAFewMailCodesPerWindow(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        for ($i = 0; $i < ZweiterFaktor::MAILS_JE_KONTO; $i++) {
            self::assertNotNull($this->faktor->neuerMailCode($this->id, $am));
        }

        self::assertNull($this->faktor->neuerMailCode($this->id, $am));
        self::assertNotNull($this->faktor->neuerMailCode($this->id, $am->modify('+15 minutes')), 'the next window');
    }

    public function testOnlyTheHashOfAMailCodeIsStored(): void
    {
        $code = (string) $this->faktor->neuerMailCode($this->id);

        $zeile = $this->pdo()->query('SELECT code_hash FROM mfa_email_code')->fetchColumn();
        self::assertIsString($zeile);
        self::assertSame(32, strlen($zeile));
        self::assertNotSame(hash('sha256', $code, true), $zeile, 'keyed, not a plain hash a reader could test six digits against');
    }

    public function testTenBackupCodesEachWorkOnceAndANewSetReplacesThem(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        $codes = $this->faktor->richteMailEin($this->id, $am);
        self::assertCount(BackupCodes::COUNT, $codes);
        self::assertSame(MfaMethode::Email, $this->methode());
        self::assertSame(10, $this->faktor->offeneBackupCodes($this->id));

        // Typed by hand: lower case, without the dash.
        self::assertSame(Nachweis::BackupCode, $this->faktor->pruefe($this->id, strtolower(str_replace('-', '', $codes[3])), $am));
        self::assertNull($this->faktor->pruefe($this->id, $codes[3], $am), 'spent');
        self::assertSame(9, $this->faktor->offeneBackupCodes($this->id));

        $neu = $this->faktor->neueBackupCodes($this->id, $am);
        self::assertNull($this->faktor->pruefe($this->id, $codes[4], $am), 'the old set is gone');
        self::assertSame(Nachweis::BackupCode, $this->faktor->pruefe($this->id, $neu[0], $am));
    }

    public function testBackupCodesBelongToTheirAccount(): void
    {
        $codes = $this->faktor->richteMailEin($this->id);
        $andere = Benutzerdaten::anlegen($this->pdo(), 'volker@example.org');

        self::assertNull($this->faktor->pruefe($andere, $codes[0]));
    }

    /** Switching methods removes the other one's secret: an abandoned app stops working. */
    public function testSwitchingToTheMailRemovesTheApp(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        [$geheimnis] = $this->appEinrichten($am);

        $this->faktor->richteMailEin($this->id, $am);

        self::assertSame(MfaMethode::Email, $this->methode());
        $spaeter = $am->modify('+2 minutes');
        self::assertNull($this->faktor->pruefe($this->id, Totp::code($geheimnis, $spaeter), $spaeter));
    }

    public function testARememberedDeviceHoldsThirtyDays(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        $token = $this->faktor->merkeGeraet($this->id, $am);

        self::assertTrue($this->faktor->geraetGemerkt($this->id, $token, $am->modify('+29 days')));
        self::assertFalse($this->faktor->geraetGemerkt($this->id, $token, $am->modify('+30 days')));
        self::assertFalse($this->faktor->geraetGemerkt($this->id, 'fremd', $am));
        self::assertFalse($this->faktor->geraetGemerkt($this->id, null, $am));
        self::assertFalse($this->faktor->geraetGemerkt(Benutzerdaten::anlegen($this->pdo(), 'volker@example.org'), $token, $am), 'another account');
        self::assertSame(1, $this->faktor->gemerkteGeraete($this->id, $am));

        $this->faktor->vergesseGeraete($this->id);
        self::assertFalse($this->faktor->geraetGemerkt($this->id, $token, $am));
    }

    public function testTheCodeAttemptsAreLimitedPerAccount(): void
    {
        for ($i = 0; $i < ZweiterFaktor::JE_KONTO; $i++) {
            self::assertTrue($this->faktor->versuch('198.51.100.' . $i, $this->id));
        }
        self::assertFalse($this->faktor->versuch('198.51.100.99', $this->id));

        $this->faktor->erfolg($this->id);
        self::assertTrue($this->faktor->versuch('198.51.100.99', $this->id));
    }

    public function testTheReConfirmationNeedsPasswordAndCode(): void
    {
        $codes = $this->faktor->richteMailEin($this->id);
        $benutzer = new BenutzerRepository($this->pdo())->findById($this->id);
        self::assertNotNull($benutzer);

        self::assertSame('Das Passwort stimmt nicht.', $this->faktor->bestaetige($benutzer, 'falsch', $codes[0], '198.51.100.7'));
        self::assertSame(10, $this->faktor->offeneBackupCodes($this->id), 'a wrong password spends no code');
        self::assertSame('Der Code ist ungültig oder abgelaufen.', $this->faktor->bestaetige($benutzer, Benutzerdaten::PASSWORT, '123456', '198.51.100.7'));
        self::assertNull($this->faktor->bestaetige($benutzer, Benutzerdaten::PASSWORT, $codes[0], '198.51.100.7'));
    }

    public function testTheCronSweepsExpiredCodesAndDevices(): void
    {
        $am = self::am('2026-03-01 10:00:00');
        $this->faktor->neuerMailCode($this->id, $am);
        $this->faktor->merkeGeraet($this->id, $am);

        self::assertSame(0, $this->repo->loescheAbgelaufeneMailCodes($am->modify('+5 minutes')) + $this->repo->loescheAbgelaufeneGeraete($am->modify('+5 minutes')));
        self::assertSame(2, $this->repo->loescheAbgelaufeneMailCodes($am->modify('+31 days')) + $this->repo->loescheAbgelaufeneGeraete($am->modify('+31 days')));
    }
}
