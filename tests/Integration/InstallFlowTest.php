<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Anwendung;
use App\Config\Config;
use App\Config\Paths;
use App\Dienste;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Installer\InstallController;
use App\Installer\InstallToken;
use App\Repository\BenutzerRepository;
use App\Repository\SettingRepository;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\ServerKeyGeheimnisSchutz;
use App\Service\Migration\Migrator;
use App\Service\Update\UpdateService;
use App\Support\FileLogger;
use App\Support\Version;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeMailTransport;
use App\Tests\Support\TestDatenbank;
use App\View\Stylesheets;
use App\View\View;

/**
 * Pflicht-Test of docs/spec/04-installation-und-update.md: the /install flow
 * through the installer's own kernel (App\Anwendung::installation()) against
 * a real database, in a throwaway copy of the server layout (web/, current/,
 * shared/) - token, "config.php only after confirmation", the channel from
 * setup.php, the Sicherungsschlüssel shown once, the mail step, the restore,
 * and the login afterwards.
 *
 * What setup.php itself does (download, checksum, unpack) is the code of
 * the updater (UpdateChainTest, ReleaseDownloaderTest, SetupPhpTest); the
 * browser walk-through is the manual checklist of the pull request.
 */
final class InstallFlowTest extends DatabaseTestCase
{
    private const string ADMIN_EMAIL = 'admina@example.org';

    private string $basis;

    private Paths $paths;

    private FakeMailTransport $mail;

    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $repo = dirname(__DIR__, 2);

        $this->basis = sys_get_temp_dir() . '/mv_install_' . bin2hex(random_bytes(6));
        // The release under test: what the installer reads below current/.
        foreach (['migrations' => '*.sql', 'app/data' => '*.txt', 'app/views/mail' => '*.php'] as $dir => $muster) {
            mkdir($this->basis . '/current/' . $dir, 0775, true);
            foreach (glob($repo . '/' . $dir . '/' . $muster) ?: [] as $datei) {
                copy($datei, $this->basis . '/current/' . $dir . '/' . basename($datei));
            }
        }
        mkdir($this->basis . '/web');
        mkdir($this->basis . '/shared/var/backups', 0775, true);
        // What setup.php leaves behind.
        file_put_contents($this->basis . '/web/setup.php', '<?php // bootstrap installer');
        file_put_contents($this->basis . '/shared/setup_kanal.txt', 'beta');

        $this->paths = new Paths($this->basis . '/current');
        $this->mail = new FakeMailTransport();
        $this->kernel = Anwendung::installation(
            $this->paths,
            new View($repo . '/app/views', '0.0.0-test', Stylesheets::aus($repo . '/public')),
            new FileLogger($this->basis . '/shared/var/log/app.log'),
            static fn(string $pfad): bool => is_file($pfad),
            $this->mail,
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        self::entfernen($this->basis);
    }

    // ---------------------------------------------------------- the token

    public function testWithoutTheTokenThereIsNothingButTheReleasePage(): void
    {
        $seite = $this->get('/install');
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Installation freigeben', $seite->body);
        self::assertFileExists($this->paths->installTokenFile(), 'an install without setup.php gets a token as well');

        // Every step refuses, before it looks at a single field.
        self::assertSame(403, $this->post('/install/datenbank', $this->dbFelder())->status);
        self::assertSame(403, $this->post('/install/neu')->status);
        self::assertArrayNotHasKey(InstallController::SESSION_KEY, $_SESSION);

        $falsch = $this->get('/install', ['token' => str_repeat('0', 32)]);
        self::assertStringContainsString('stimmt nicht', $falsch->body);
        self::assertSame(422, $this->post('/install/freigabe', ['token' => 'falsch'])->status);
    }

    public function testTheTokenFromTheRedirectOfSetupPhpReleasesTheSessionAndLeavesTheAddressBar(): void
    {
        $token = new InstallToken($this->paths->installTokenFile())->ensure();

        self::assertWeiterleitungNach('/install', $this->get('/install', ['token' => $token]));
        self::assertStringContainsString('Datenbank verbinden', $this->get('/install')->body);
    }

    public function testTheTokenCanBeTypedInFromTheFile(): void
    {
        $token = new InstallToken($this->paths->installTokenFile())->ensure();
        $this->get('/install');

        self::assertWeiterleitungNach('/install', $this->post('/install/freigabe', ['token' => strtoupper($token)]));
        self::assertStringContainsString('Datenbank verbinden', $this->get('/install')->body);
    }

    public function testEveryPostOfTheInstallerNeedsTheCsrfToken(): void
    {
        $this->freigeben();
        foreach (['freigabe', 'datenbank', 'art', 'admin', 'schluessel', 'mail', 'wiederherstellen', 'wiederherstellen/schritt', 'neu'] as $schritt) {
            self::assertSame(403, $this->post('/install/' . $schritt, [], mitToken: false)->status, $schritt);
        }
    }

    public function testEveryOtherAddressLeadsToTheInstaller(): void
    {
        foreach (['/', '/anmelden', '/app/mitglieder', '/admin'] as $pfad) {
            self::assertWeiterleitungNach('/install', $this->get($pfad));
        }
    }

    // ---------------------------------------------------- fresh install

    public function testAFreshInstallationFromTheDatabaseToTheFirstLogin(): void
    {
        $this->freigeben();

        // 1. A wrong password is reported, and not kept.
        $falsch = $this->post('/install/datenbank', ['db_password' => 'falsch'] + $this->dbFelder());
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('Die Verbindung ist fehlgeschlagen', $falsch->body);
        self::assertStringNotContainsString('value="falsch"', $falsch->body);
        // config.php cannot hold an empty password, so step 1 asks for one.
        self::assertSame(422, $this->post('/install/datenbank', ['db_password' => ''] + $this->dbFelder())->status);

        self::assertWeiterleitungNach('/install', $this->post('/install/datenbank', $this->dbFelder()));
        self::assertStringContainsString('Art der Installation', $this->get('/install')->body);
        // A step out of order answers with the step the run is at.
        self::assertSame(409, $this->post('/install/admin', $this->adminFelder())->status);

        // 3. + 4.
        self::assertWeiterleitungNach('/install', $this->post('/install/art', ['art' => 'frisch']));
        $kurz = $this->post('/install/admin', ['passwort' => 'kurz', 'passwort_wiederholung' => 'kurz'] + $this->adminFelder());
        self::assertSame(422, $kurz->status);
        self::assertStringContainsString('value="' . self::ADMIN_EMAIL . '"', $kurz->body, 'the address comes back, the password does not');

        $schluesselSeite = $this->post('/install/admin', $this->adminFelder());
        self::assertSame(200, $schluesselSeite->status);
        $schluessel = self::schluesselAus($schluesselSeite);
        self::assertSame(32, strlen((string) base64_decode($schluessel, true)));
        self::assertFileDoesNotExist($this->paths->configFile(), 'config.php only at the very end');

        // Shown once: a reload has the step, not the key.
        $nochmal = $this->get('/install');
        self::assertStringContainsString('bereits angezeigt', $nochmal->body);
        self::assertStringNotContainsString(substr($schluessel, 0, 4) . '</span>', $nochmal->body);
        self::assertStringNotContainsString(implode(' ', str_split($schluessel, 4)), $nochmal->body);

        self::assertSame(422, $this->post('/install/schluessel', ['aktion' => 'bestaetigt'])->status);
        self::assertWeiterleitungNach('/install', $this->post('/install/schluessel', ['aktion' => 'bestaetigt', 'notiert' => '1']));

        // 5. A test mail first, then save and finish.
        $mailSeite = $this->get('/install');
        self::assertStringContainsString('Mailversand', $mailSeite->body);
        self::assertStringContainsString('value="' . self::ADMIN_EMAIL . '"', $mailSeite->body, 'the admin as sender and test recipient');
        $test = $this->post('/install/mail', ['aktion' => 'testen'] + $this->mailFelder());
        self::assertStringContainsString('Die Testmail ist versendet', $test->body);
        self::assertCount(1, $this->mail->gesendet);
        self::assertSame(self::ADMIN_EMAIL, $this->mail->gesendet[0]->to);
        self::assertFileDoesNotExist($this->paths->configFile());

        $fertig = $this->post('/install/mail', ['aktion' => 'speichern', 'passwort' => ''] + $this->mailFelder());
        self::assertSame(200, $fertig->status);
        self::assertStringContainsString('Installation abgeschlossen', $fertig->body);

        // 6. config.php with the key that was shown, the cron URL once.
        $config = Config::fromFile($this->paths->configFile());
        self::assertSame(base64_decode($schluessel, true), $config->serverKey);
        self::assertSame(DatabaseTestCase::dbName(), $config->dbName);
        self::assertStringContainsString('/cron?token=' . $config->cronToken, $fertig->body);
        foreach ([$this->basis . '/web/setup.php', $this->paths->setupChannelFile(), $this->paths->installTokenFile()] as $rest) {
            self::assertFileDoesNotExist($rest);
        }
        self::assertSame([], $_SESSION[InstallController::SESSION_KEY] ?? [], 'the session forgets the key and the database password');

        $pdo = $this->pdo();
        self::assertSame('beta', new SettingRepository($pdo)->get(UpdateService::SETTING_CHANNEL), 'the channel of setup.php');
        // The SMTP password is stored under the server key of config.php.
        $mail = new MailSettingsRepository(new SettingRepository($pdo), new ServerKeyGeheimnisSchutz($config->serverKey))->get();
        self::assertSame('smtp-geheim', $mail->passwort);
        self::assertStringNotContainsString('smtp-geheim', (string) $pdo->query("SELECT value FROM setting WHERE name = 'mail_smtp_passwort_enc'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'benutzer.angelegt'")->fetchColumn());

        // The first login with the new admin, in the application proper.
        $this->anmeldenMit($config, self::ADMIN_EMAIL, Benutzerdaten::PASSWORT);
    }

    public function testAKeyThatWasNotNotedCanBeReplacedBeforeAnythingUsesIt(): void
    {
        $this->bisZumAdmin();
        $erster = self::schluesselAus($this->post('/install/admin', $this->adminFelder()));

        $neu = $this->post('/install/schluessel', ['aktion' => 'neu']);
        $zweiter = self::schluesselAus($neu);
        self::assertNotSame($erster, $zweiter);

        $this->post('/install/schluessel', ['aktion' => 'bestaetigt', 'notiert' => '1']);
        $fertig = $this->post('/install/mail', ['aktion' => 'ueberspringen']);
        self::assertStringContainsString('Der Mailversand ist noch nicht eingerichtet', $fertig->body);
        self::assertSame(base64_decode($zweiter, true), Config::fromFile($this->paths->configFile())->serverKey);
        self::assertCount(0, $this->mail->gesendet);
    }

    public function testAFreshInstallationRefusesADatabaseThatHasAccounts(): void
    {
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        Benutzerdaten::anlegen($this->pdo());
        $this->bisZumAdmin();

        $antwort = $this->post('/install/admin', $this->adminFelder());
        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('enthält bereits eine Installation', $antwort->body);
    }

    public function testStartingOverRemovesTheHalfCreatedAdmin(): void
    {
        $this->bisZumAdmin();
        $this->post('/install/admin', $this->adminFelder());
        self::assertTrue(new BenutzerRepository($this->pdo())->gibtEs());

        self::assertWeiterleitungNach('/install', $this->post('/install/neu'));
        self::assertFalse(new BenutzerRepository($this->pdo())->gibtEs());
        self::assertStringContainsString('Datenbank verbinden', $this->get('/install')->body);

        // And the second attempt goes through.
        $this->bisZumAdmin();
        self::assertSame(200, $this->post('/install/admin', $this->adminFelder())->status);
    }

    /** A mail password saved under the key of an abandoned run must not survive under the new one. */
    public function testStartingOverAfterTheMailTestLeavesNoPasswordTheNewKeyCannotRead(): void
    {
        $this->bisZumAdmin();
        $this->post('/install/admin', $this->adminFelder());
        $this->post('/install/schluessel', ['aktion' => 'bestaetigt', 'notiert' => '1']);
        $this->post('/install/mail', ['aktion' => 'testen'] + $this->mailFelder());
        $this->post('/install/neu');

        $this->bisZumAdmin();
        $this->post('/install/admin', $this->adminFelder());
        $this->post('/install/schluessel', ['aktion' => 'bestaetigt', 'notiert' => '1']);
        self::assertStringContainsString('Wird verschlüsselt gespeichert', $this->get('/install')->body, 'the old password reads as not set');
        $this->post('/install/mail', ['aktion' => 'speichern', 'passwort' => ''] + $this->mailFelder());

        self::assertSame('', (string) new SettingRepository($this->pdo())->text('mail_smtp_passwort_enc'));
    }

    // ---------------------------------------------------------- restore

    public function testARestoreTakesTheKeyOfTheBackupAndEndsInALogin(): void
    {
        [$backup, $schluessel] = $this->backupEinerAnderenInstallation();
        $this->bisZurArt();
        $this->post('/install/art', ['art' => 'backup']);
        self::assertStringContainsString('Backup auswählen', $this->get('/install')->body);

        // A wrong key fails at the header, before anything is restored.
        $falsch = $this->post('/install/wiederherstellen', ['schluessel' => base64_encode(random_bytes(32))], ['backup' => $backup]);
        self::assertSame(422, $falsch->status);
        self::assertFalse(self::tabelleDa($this->pdo(), 'user'));
        self::assertSame(422, $this->post('/install/wiederherstellen', ['schluessel' => 'zu kurz'], ['backup' => $backup])->status);

        // The key as printed: groups of four, any line breaks.
        $ausdruck = implode(' ', str_split(base64_encode($schluessel), 4));
        self::assertWeiterleitungNach('/install', $this->post('/install/wiederherstellen', ['schluessel' => wordwrap($ausdruck, 20, "\n")], ['backup' => $backup]));
        self::assertStringContainsString('id="restore"', $this->get('/install')->body);

        $stand = $this->wiederherstellenBisFertig();
        self::assertNotEmpty($stand['cron_url']);
        $config = Config::fromFile($this->paths->configFile());
        self::assertSame($schluessel, $config->serverKey, 'config.php gets the key of the backup');
        self::assertStringEndsWith('/cron?token=' . $config->cronToken, $stand['cron_url']);
        self::assertFileDoesNotExist($this->paths->installTokenFile());
        self::assertSame([], glob($this->paths->backupDir() . '/wiederherstellung/*.enc'), 'the uploaded copy is gone');
        self::assertSame('beta', new SettingRepository($this->pdo())->get(UpdateService::SETTING_CHANNEL));
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'restore.durchgefuehrt'")->fetchColumn());

        $this->anmeldenMit($config, 'erika@example.org', Benutzerdaten::PASSWORT);
    }

    /** setup.php creates shared/var/backups/; an install without it must not trip over its absence. */
    public function testTheRestoreFormNeedsNoBackupFolder(): void
    {
        rmdir($this->paths->backupDir());
        $this->bisZurArt();
        $this->post('/install/art', ['art' => 'backup']);

        $seite = $this->get('/install');
        self::assertSame(200, $seite->status);
        self::assertStringNotContainsString('name="backup_datei"', $seite->body);
    }

    public function testARestoreFromABackupPutThereByFtpWithTheOldConfigPhp(): void
    {
        [$backup, $schluessel] = $this->backupEinerAnderenInstallation();
        $name = 'backup_20260301_100000.zip.enc';
        rename($backup, $this->paths->backupDir() . '/' . $name);
        $alteConfig = $this->basis . '/alte-config.php';
        file_put_contents($alteConfig, "<?php return ['server_key' => '" . base64_encode($schluessel) . "'];");

        $this->bisZurArt();
        $this->post('/install/art', ['art' => 'backup']);
        self::assertStringContainsString('<option value="' . $name . '">', $this->get('/install')->body);
        self::assertSame(422, $this->post('/install/wiederherstellen', ['backup_datei' => '../config.php'], ['config' => $alteConfig])->status);

        self::assertWeiterleitungNach('/install', $this->post('/install/wiederherstellen', ['backup_datei' => $name], ['config' => $alteConfig]));
        $this->wiederherstellenBisFertig();

        self::assertSame($schluessel, Config::fromFile($this->paths->configFile())->serverKey);
        self::assertFileExists($this->paths->backupDir() . '/' . $name, 'the admin\'s own file stays');
    }

    // ---------------------------------------------------------- helpers

    private function freigeben(): void
    {
        $token = new InstallToken($this->paths->installTokenFile())->ensure();
        $this->get('/install', ['token' => $token]);
    }

    private function bisZurArt(): void
    {
        $this->freigeben();
        self::assertWeiterleitungNach('/install', $this->post('/install/datenbank', $this->dbFelder()));
    }

    private function bisZumAdmin(): void
    {
        $this->bisZurArt();
        self::assertWeiterleitungNach('/install', $this->post('/install/art', ['art' => 'frisch']));
    }

    /** @return array<string, mixed> the last answer of the chain */
    private function wiederherstellenBisFertig(): array
    {
        for ($i = 0; $i < 500; $i++) {
            $antwort = $this->post('/install/wiederherstellen/schritt');
            self::assertSame(200, $antwort->status, $antwort->body);
            $stand = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
            if ($stand['fertig'] === true) {
                return $stand;
            }
        }
        self::fail('the restore never finished');
    }

    /**
     * An encrypted backup of another installation with one account, made
     * the way the application makes it - then the database is wiped.
     *
     * @return array{string, string} path of the backup, its raw server key
     */
    private function backupEinerAnderenInstallation(): array
    {
        $pdo = $this->pdo();
        new Migrator($pdo, $this->migrationsDir())->migrate();
        Benutzerdaten::anlegen($pdo);
        $dir = $this->basis . '/andere';
        mkdir($dir . '/backups', 0775, true);
        $schluessel = random_bytes(32);

        $name = new BackupErstellung(
            new BackupService($pdo, $dir . '/backups', '9.9.9-test', $dir . '/blobs', $dir . '/logo'),
            $pdo,
            new ServerCrypto($schluessel),
        )->komplett();
        TestDatenbank::alleTabellenLoeschen($pdo);

        return [$dir . '/backups/' . $name, $schluessel];
    }

    private function anmeldenMit(Config $config, string $email, string $passwort): void
    {
        $_SESSION = [];
        $repo = dirname(__DIR__, 2);
        $paths = new Paths($repo);
        $pdo = $this->pdo();
        $dienste = new Dienste(
            $paths,
            $config,
            new Version('0.0.0-test'),
            new View($paths->viewsDir(), '0.0.0-test', Stylesheets::aus($paths->publicDir())),
            new Session(),
            new FileLogger($this->basis . '/shared/var/log/app.log'),
            static fn(): \PDO => $pdo,
        );
        $kernel = Anwendung::kernel($dienste);
        $session = new Session();
        $session->start();
        $antwort = $kernel->handle(new Request(HttpMethod::Post, '/anmelden', post: [
            '_csrf' => $session->csrfToken(),
            'email' => $email,
            'passwort' => $passwort,
        ]));

        self::assertInstanceOf(Response::class, $antwort);
        // The restored account has its second factor: reaching that step
        // means the password was accepted (the code step has its own tests).
        $ziel = ($antwort->headers['Location'] ?? null) === '/anmelden/bestaetigen' ? '/anmelden/bestaetigen' : '/app/mitglieder';
        self::assertWeiterleitungNach($ziel, $antwort);
    }

    private static function schluesselAus(Response $seite): string
    {
        self::assertSame(1, preg_match('#<p class="sicherungskarte-schluessel"[^>]*>(.*?)</p>#s', $seite->body, $treffer), 'no key on the page');
        preg_match_all('#<span>([^<]+)</span>#', $treffer[1], $gruppen);

        return implode('', $gruppen[1]);
    }

    /** @return array<string, string> */
    private function dbFelder(): array
    {
        /** @var array{host: string, port: int, name: string, user: string, password: string} $db */
        $db = DatabaseTestCase::configData()['db'];

        return [
            'db_host' => $db['host'],
            'db_port' => (string) $db['port'],
            'db_name' => $db['name'],
            'db_user' => $db['user'],
            'db_password' => $db['password'],
        ];
    }

    /** @return array<string, string> */
    private function adminFelder(): array
    {
        return [
            'name' => 'Admina Beispiel',
            'email' => self::ADMIN_EMAIL,
            'passwort' => Benutzerdaten::PASSWORT,
            'passwort_wiederholung' => Benutzerdaten::PASSWORT,
        ];
    }

    /** @return array<string, string> */
    private function mailFelder(): array
    {
        return [
            'host' => 'smtp.example.org',
            'port' => '587',
            'sicherheit' => 'starttls',
            'benutzer' => 'verein@example.org',
            'passwort' => 'smtp-geheim',
            'absender' => self::ADMIN_EMAIL,
            'antwort_an' => '',
            'vereinsname' => 'Sportverein Beispielstadt',
            'oeffentliche_url' => 'https://mitglieder.example.org',
            'empfaenger' => self::ADMIN_EMAIL,
        ];
    }

    private static function tabelleDa(\PDO $pdo, string $tabelle): bool
    {
        $abfrage = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $abfrage->execute([$tabelle]);

        return (int) $abfrage->fetchColumn() > 0;
    }

    /** @param array<string, string> $query */
    private function get(string $pfad, array $query = []): Response
    {
        return $this->aufrufen(new Request(HttpMethod::Get, $pfad, query: $query));
    }

    /**
     * @param array<string, string> $felder
     * @param array<string, string> $dateien field => local file, as an upload
     */
    private function post(string $pfad, array $felder = [], array $dateien = [], bool $mitToken = true): Response
    {
        if ($mitToken) {
            $session = new Session();
            $session->start();
            $felder['_csrf'] = $session->csrfToken();
        }
        $files = [];
        foreach ($dateien as $feld => $pfadDatei) {
            $files[$feld] = ['tmp_name' => $pfadDatei, 'error' => \UPLOAD_ERR_OK, 'name' => basename($pfadDatei), 'size' => (int) filesize($pfadDatei)];
        }

        return $this->aufrufen(new Request(HttpMethod::Post, $pfad, post: $felder, files: $files, ip: '192.0.2.10'));
    }

    private function aufrufen(Request $request): Response
    {
        $antwort = $this->kernel->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private static function assertWeiterleitungNach(string $ziel, Response $antwort): void
    {
        self::assertSame(302, $antwort->status, 'expected a redirect, got: ' . substr(strip_tags($antwort->body), 0, 600));
        self::assertSame($ziel, $antwort->headers['Location'] ?? null);
    }

    private static function entfernen(string $pfad): void
    {
        if (!is_dir($pfad)) {
            @unlink($pfad);

            return;
        }
        foreach (scandir($pfad) ?: [] as $eintrag) {
            if ($eintrag !== '.' && $eintrag !== '..') {
                self::entfernen($pfad . '/' . $eintrag);
            }
        }
        @rmdir($pfad);
    }
}
