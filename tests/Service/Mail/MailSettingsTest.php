<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Repository\SettingRepository;
use App\Service\Mail\MailSettings;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\ServerKeyGeheimnisSchutz;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/** SMTP settings through the `setting` table: the password only as ciphertext (01 section 6). */
final class MailSettingsTest extends DatabaseTestCase
{
    private MailSettingsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->repo = $this->repoMitSchluessel(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function repoMitSchluessel(string $schluessel): MailSettingsRepository
    {
        return new MailSettingsRepository(new SettingRepository($this->pdo()), new ServerKeyGeheimnisSchutz($schluessel));
    }

    private function speichern(?string $passwort, ?MailSettingsRepository $repo = null): void
    {
        ($repo ?? $this->repo)->speichern(
            host: 'smtp.example.org',
            port: 465,
            sicherheit: SmtpSecurity::Implizit,
            benutzer: 'benutzer',
            neuesPasswort: $passwort,
            absender: 'verein@example.org',
            antwortAn: '',
            vereinsname: 'Sportverein Beispielstadt',
            oeffentlicheUrl: 'https://mitglieder.example.org/',
        );
    }

    public function testDefaultsBeforeAnythingWasSaved(): void
    {
        $einstellungen = $this->repo->get();

        self::assertSame(587, $einstellungen->port);
        self::assertSame(SmtpSecurity::Starttls, $einstellungen->sicherheit);
        self::assertFalse($einstellungen->passwortGesetzt);
        self::assertFalse($einstellungen->istVollstaendig());
    }

    public function testSettingsAndPasswordRoundTrip(): void
    {
        $this->speichern('geheim');

        $einstellungen = $this->repo->get();
        self::assertSame('smtp.example.org', $einstellungen->host);
        self::assertSame(465, $einstellungen->port);
        self::assertSame(SmtpSecurity::Implizit, $einstellungen->sicherheit);
        self::assertSame('geheim', $einstellungen->passwort);
        self::assertTrue($einstellungen->passwortGesetzt);
        self::assertSame('https://mitglieder.example.org', $einstellungen->oeffentlicheUrl);
        self::assertTrue($einstellungen->istVollstaendig());
    }

    public function testThePasswordIsNeverStoredInPlaintext(): void
    {
        $this->speichern('geheimes-passwort-42');

        $alles = implode('|', $this->pdo()->query('SELECT value FROM setting')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringNotContainsString('geheimes-passwort-42', $alles);
    }

    public function testNullKeepsAndEmptyStringRemovesThePassword(): void
    {
        $this->speichern('geheim');

        $this->speichern(null);
        self::assertSame('geheim', $this->repo->get()->passwort);

        $this->speichern('');
        self::assertFalse($this->repo->get()->passwortGesetzt);
    }

    /** Another server key (a restore without config.php) shows as "not set", not as an error. */
    public function testAPasswordOfAnotherServerKeyCountsAsNotSet(): void
    {
        $this->speichern('geheim');

        $anderer = $this->repoMitSchluessel(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        self::assertFalse($anderer->get()->passwortGesetzt);
        self::assertSame('', $anderer->get()->passwort);
    }

    public function testDebugInfoHidesThePassword(): void
    {
        $this->speichern('geheim');

        self::assertStringNotContainsString('geheim', print_r($this->repo->get(), true));
    }

    public function testFormValidation(): void
    {
        $fehler = static fn(string $host = 'h', int $port = 587, ?SmtpSecurity $s = SmtpSecurity::Starttls, string $absender = 'a@example.org', string $antwort = '', string $url = ''): ?string
            => MailSettings::eingabeFehler($host, $port, $s, $absender, $antwort, $url);

        self::assertNull($fehler());
        self::assertNotNull($fehler(absender: 'kein-absender'));
        self::assertNotNull($fehler(antwort: 'x'));
        self::assertNotNull($fehler(host: ''));
        self::assertNotNull($fehler(port: 0));
        self::assertNotNull($fehler(port: 70000));
        self::assertNotNull($fehler(s: null));
        self::assertNotNull($fehler(url: 'http://example.org'));
        self::assertNotNull($fehler(url: 'https://example.org/pfad'));
        self::assertNull($fehler(url: 'https://example.org'));
    }
}
