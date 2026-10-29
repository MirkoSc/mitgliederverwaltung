<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Paths;
use App\Domain\AuditAktion;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\BenutzerRepository;
use App\Repository\MailQueueRepository;
use App\Repository\SettingRepository;
use App\Service\Account\BenutzerRegelVerstoss;
use App\Service\Account\BenutzerVerwaltung;
use App\Service\Account\PasswordPolicy;
use App\Service\Audit\AuditLog;
use App\Service\Backup\BackupLaeuftBereits;
use App\Service\Backup\BackupService;
use App\Service\Backup\FalscherSchluessel;
use App\Service\Backup\KeinBackup;
use App\Service\Backup\RestoreService;
use App\Service\Backup\Wiederherstellung;
use App\Service\Mail\MailSettings;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\MailTransport;
use App\Service\Mail\Mailer;
use App\Service\Mail\PublicUrl;
use App\Service\Mail\ServerKeyGeheimnisSchutz;
use App\Service\Mail\SmtpSecurity;
use App\Service\Mail\SmtpTransport;
use App\Service\Migration\Migrator;
use App\Service\Update\DenyHtaccess;
use App\Service\Update\UpdateService;
use App\View\Area;
use App\View\View;

/**
 * /install - the only thing that runs while shared/config.php is missing
 * (docs/spec/04-installation-und-update.md section 2, docs/spec/01-plattform.md
 * section 3). Taken over from Vereinsbelege, without its vault and with one
 * request per step of the spec:
 *
 *   1. database credentials + connection test       POST /install/datenbank
 *   2. server key, kept in the session until 6.     (same request)
 *   3. fresh installation or restore                POST /install/art
 *   4. fresh: migrations + first admin              POST /install/admin
 *      - the server key is shown ONCE, to print     POST /install/schluessel
 *   5. mail settings + test mail, skippable         POST /install/mail
 *   6. config.php, setup.php and token deleted      (end of 5.)
 *
 *   restore: backup + its key                       POST /install/wiederherstellen
 *            the step chain of App\Service\Backup\Wiederherstellung,
 *            one request per step (public/js/install.js),
 *            then 6. with the key of the backup       POST /install/wiederherstellen/schritt
 *
 * Nothing before 6. writes config.php, so a run that breaks off leaves the
 * installer open; "Neu beginnen" (POST /install/neu) starts over.
 *
 * Every step needs a session released by the installation token
 * (App\Installer\InstallToken): setup.php hands it over in its redirect
 * (`/install?token=…`), otherwise it is typed in from
 * shared/install_token.txt (POST /install/freigabe). The CSRF check of every
 * POST sits in App\Anwendung::installation(), as for the application's own
 * routes.
 *
 * The session holds the database password and the server key until 6. -
 * like Vereinsbelege's installer, and only for the minutes of the
 * installation. Never in the session: the admin's password, the test
 * mail's settings (they go to the database at once).
 */
final readonly class InstallController
{
    /** $_SESSION key of the state of this run. */
    public const string SESSION_KEY = 'install';

    private const string FALSCHES_TOKEN = 'Dieses Installations-Token stimmt nicht. Bitte den Inhalt der Datei shared/install_token.txt genau übernehmen.';

    /** Name of an uploaded backup while the restore chain reads it. */
    private const string HOCHGELADEN = 'hochgeladen.zip.enc';

    public function __construct(
        private View $view,
        private Paths $paths,
        private Session $session,
        /**
         * Replaces is_uploaded_file() in tests: PHP only answers true for a
         * real multipart upload, which a test cannot produce.
         *
         * @var (\Closure(string): bool)|null
         */
        private ?\Closure $istUpload = null,
        /** Test seam for the test mail, like Mailer's own. */
        private ?MailTransport $mailTransport = null,
    ) {
    }

    // ------------------------------------------------------------ pages

    public function zeigen(Request $request): ResponseInterface
    {
        $this->session->start();

        $token = $this->token();
        if (!$token->isReleased()) {
            try {
                $token->ensure();
            } catch (\RuntimeException) {
                return $this->freigabeSeite(['token' => 'Das Installations-Token lässt sich nicht anlegen: shared/ ist nicht beschreibbar. Bitte die Schreibrechte per FTP prüfen.'], 500);
            }
            // The hand-over from setup.php. Redirected at once, so the token
            // does not stay in the address bar, the history or a Referer.
            $angeboten = $request->query['token'] ?? null;
            if (is_string($angeboten) && $token->matches($angeboten)) {
                $token->release();

                return Response::redirect('/install');
            }

            return $this->freigabeSeite(is_string($angeboten) ? ['token' => self::FALSCHES_TOKEN] : []);
        }

        return $this->seite($this->schritt());
    }

    /**
     * POST /install/freigabe: the token typed in from shared/install_token.txt
     * - for whoever did not come straight from setup.php.
     */
    public function freigabe(Request $request): ResponseInterface
    {
        $token = $this->token();
        if (!$token->matches($request->feld('token'))) {
            return $this->freigabeSeite(['token' => self::FALSCHES_TOKEN], 422);
        }
        $token->release();

        return Response::redirect('/install');
    }

    // ------------------------------------------------------- 1. database

    public function datenbank(Request $request): ResponseInterface
    {
        if (($falsch = $this->erwarte(InstallSchritt::Datenbank)) !== null) {
            return $falsch;
        }

        $db = [
            'host' => $request->feld('db_host'),
            'port' => (int) ($request->feld('db_port') ?: '3306'),
            'name' => $request->feld('db_name'),
            'user' => $request->feld('db_user'),
            // Not trimmed: spaces may belong to a password.
            'password' => is_string($request->post['db_password'] ?? null) ? $request->post['db_password'] : '',
        ];
        $eingabe = ['db' => [...$db, 'password' => '']];

        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            return $this->seite(InstallSchritt::Datenbank, ['db' => 'Bitte Server, Datenbankname und Benutzer angeben.'], 422, $eingabe);
        }
        if ($db['port'] < 1 || $db['port'] > 65535) {
            return $this->seite(InstallSchritt::Datenbank, ['db' => 'Der Port muss zwischen 1 und 65535 liegen.'], 422, $eingabe);
        }
        try {
            self::verbinden($db);
        } catch (\PDOException $e) {
            // The driver's message names host and database, never the
            // password - and there is no member data here yet.
            return $this->seite(InstallSchritt::Datenbank, ['db' => 'Die Verbindung ist fehlgeschlagen: ' . $e->getMessage()], 422, $eingabe);
        }

        // 2. The server key: created now, written to config.php at the very
        // end. A restore replaces it with the key of the backup.
        $_SESSION[self::SESSION_KEY] = [
            'schritt' => InstallSchritt::Art->value,
            'db' => $db,
            'server_key' => ConfigWriter::neuerServerSchluessel(),
            'kanal' => $this->kanalAusSetup(),
            // For the default of the public address in the mail step.
            'origin' => $request->ownOrigin(),
        ];

        return Response::redirect('/install');
    }

    // ---------------------------------------------- 3. fresh or restore

    public function art(Request $request): ResponseInterface
    {
        if (($falsch = $this->erwarte(InstallSchritt::Art)) !== null) {
            return $falsch;
        }
        $this->weiterZu($request->feld('art') === 'backup' ? InstallSchritt::Wiederherstellung : InstallSchritt::Admin);

        return Response::redirect('/install');
    }

    // ------------------------------------------------- 4. the first admin

    public function admin(Request $request): ResponseInterface
    {
        if (($falsch = $this->erwarte(InstallSchritt::Admin)) !== null) {
            return $falsch;
        }
        $eingabe = ['admin' => ['email' => $request->feld('email'), 'name' => $request->feld('name')]];

        try {
            $pdo = $this->pdo();
            if (self::hatBenutzer($pdo)) {
                return $this->seite(InstallSchritt::Admin, ['admin' => 'Diese Datenbank enthält bereits eine Installation. Bitte eine leere Datenbank verwenden oder ein Backup einspielen.'], 422, $eingabe);
            }
            // Fresh install: every migration from 0 (03 section 4).
            new Migrator($pdo, $this->paths->migrationsDir())->migrate();
            $id = new BenutzerVerwaltung(
                $pdo,
                new PasswordPolicy($this->paths->dataDir() . '/haeufige-passwoerter.txt'),
            )->ersterAdmin(
                $eingabe['admin']['email'],
                $eingabe['admin']['name'],
                self::passwortFeld($request, 'passwort'),
                self::passwortFeld($request, 'passwort_wiederholung'),
            );
        } catch (BenutzerRegelVerstoss $e) {
            return $this->seite(InstallSchritt::Admin, ['admin' => $e->getMessage()], 422, $eingabe);
        } catch (\PDOException $e) {
            return $this->seite(InstallSchritt::Admin, ['admin' => self::datenbankFehler($e)], 500, $eingabe);
        }

        $_SESSION[self::SESSION_KEY]['admin_id'] = $id;
        $_SESSION[self::SESSION_KEY]['admin_email'] = strtolower($eingabe['admin']['email']);
        $this->weiterZu(InstallSchritt::Schluessel);

        // The one time the key is shown: in the answer to this POST, never
        // again on GET /install (04 section 2, 02 section 2).
        return $this->seite(InstallSchritt::Schluessel, daten: ['schluessel' => $this->stand()['server_key']]);
    }

    /**
     * After the key page: `bestaetigt` moves on to the mail step; `neu`
     * makes a new key and shows it once - possible because nothing has been
     * encrypted with the old one yet (the mail password comes in step 5).
     */
    public function schluessel(Request $request): ResponseInterface
    {
        if (($falsch = $this->erwarte(InstallSchritt::Schluessel)) !== null) {
            return $falsch;
        }

        if ($request->feld('aktion') === 'neu') {
            $_SESSION[self::SESSION_KEY]['server_key'] = ConfigWriter::neuerServerSchluessel();

            return $this->seite(InstallSchritt::Schluessel, daten: ['schluessel' => $this->stand()['server_key']]);
        }
        if ($request->feld('notiert') !== '1') {
            return $this->seite(InstallSchritt::Schluessel, ['schluessel' => 'Bitte bestätigen Sie, dass der Sicherungsschlüssel ausgedruckt oder notiert ist.'], 422);
        }
        $this->weiterZu(InstallSchritt::Mail);

        return Response::redirect('/install');
    }

    // ------------------------------------------------------ 5. mail

    /**
     * `aktion`: `testen` (save, send the test mail, stay), `speichern`
     * (save, finish) or `ueberspringen` (finish without mail - then the
     * second factor can only be the app, 02 section 3).
     */
    public function mail(Request $request): ResponseInterface
    {
        if (($falsch = $this->erwarte(InstallSchritt::Mail)) !== null) {
            return $falsch;
        }
        $aktion = $request->feld('aktion');
        if ($aktion === 'ueberspringen') {
            return $this->fertigSeite($request);
        }

        $host = $request->feld('host');
        $port = (int) $request->feld('port');
        $sicherheit = SmtpSecurity::tryFrom($request->feld('sicherheit'));
        $absender = $request->feld('absender');
        $antwortAn = $request->feld('antwort_an');
        $url = $request->feld('oeffentliche_url');
        $empfaenger = $request->feld('empfaenger');

        $fehler = MailSettings::eingabeFehler($host, $port, $sicherheit, $absender, $antwortAn, $url);
        if ($fehler === null && $aktion === 'testen' && filter_var($empfaenger, FILTER_VALIDATE_EMAIL) === false) {
            $fehler = 'Bitte eine gültige Empfängeradresse für die Testmail angeben.';
        }

        try {
            $einstellungen = $this->mailEinstellungen($this->pdo());
            if ($fehler !== null || $sicherheit === null) {
                // What was typed comes back - never the password.
                $gespeichert = $einstellungen->get();

                return $this->seite(InstallSchritt::Mail, ['mail' => $fehler ?? 'Bitte eine Verschlüsselung auswählen.'], 422, ['mail' => new MailSettings(
                    host: $host,
                    port: $port,
                    sicherheit: $sicherheit ?? $gespeichert->sicherheit,
                    benutzer: $request->feld('benutzer'),
                    passwort: '',
                    passwortGesetzt: $gespeichert->passwortGesetzt,
                    absender: $absender,
                    antwortAn: $antwortAn,
                    vereinsname: $request->feld('vereinsname'),
                    oeffentlicheUrl: $url,
                ), 'empfaenger' => $empfaenger]);
            }

            $passwort = self::passwortFeld($request, 'passwort');
            $einstellungen->speichern(
                host: $host,
                port: $port,
                sicherheit: $sicherheit,
                benutzer: $request->feld('benutzer'),
                // Empty keeps a password an earlier test of this step saved.
                neuesPasswort: $passwort === '' ? null : $passwort,
                absender: $absender,
                antwortAn: $antwortAn,
                vereinsname: $request->feld('vereinsname'),
                oeffentlicheUrl: $url,
            );

            if ($aktion !== 'testen') {
                return $this->fertigSeite($request);
            }

            $pdo = $this->pdo();
            $ergebnis = new Mailer(
                new MailQueueRepository($pdo),
                $this->mailEinstellungen($pdo),
                new MailTemplates($this->paths->viewsDir() . '/mail'),
                $this->mailTransport ?? new SmtpTransport(),
            )->sendeTestmail($empfaenger);
        } catch (\PDOException $e) {
            return $this->seite(InstallSchritt::Mail, ['mail' => self::datenbankFehler($e)], 500);
        }

        return $this->seite(InstallSchritt::Mail, daten: [
            'empfaenger' => $empfaenger,
            'testergebnis' => $ergebnis->erfolg
                ? ['ok' => true, 'text' => 'Die Testmail ist versendet. Bitte den Posteingang prüfen und dann abschließen.']
                : ['ok' => false, 'text' => 'Die Testmail konnte nicht versendet werden: ' . ($ergebnis->fehlermeldung ?? 'unbekannter Fehler.')],
        ]);
    }

    // ------------------------------------------------------- restore

    /**
     * The backup comes as an upload or - when it is larger than PHP takes
     * in one upload - from shared/var/backups/, put there by FTP. Its key
     * is typed in (the printed Sicherungsschlüssel) or comes as the old
     * config.php. Both are checked before anything is restored: a wrong key
     * fails at the header of the file (Wiederherstellung::starten()).
     */
    public function wiederherstellen(Request $request): ResponseInterface
    {
        if (($falsch = $this->erwarte(InstallSchritt::Wiederherstellung)) !== null) {
            return $falsch;
        }

        $schluessel = null;
        $config = $this->hochgeladen($request, 'config');
        if ($config !== null) {
            // A config.php is a few hundred bytes; whatever is bigger is not one.
            $schluessel = RestoreService::schluesselAusConfig((string) file_get_contents($config, length: 64 * 1024));
        } elseif ($request->feld('schluessel') !== '') {
            $schluessel = RestoreService::schluesselAusEingabe($request->feld('schluessel'));
        }
        if ($schluessel === null) {
            return $this->seite(InstallSchritt::Wiederherstellung, ['schluessel' => 'Bitte den Sicherungsschlüssel eintragen (44 Zeichen) oder die config.php der alten Installation hochladen.'], 422);
        }

        $hochgeladen = $this->hochgeladen($request, 'backup');
        $auswahl = $request->feld('backup_datei');
        if ($hochgeladen !== null && $auswahl !== '') {
            return $this->seite(InstallSchritt::Wiederherstellung, ['backup' => 'Bitte entweder ein Backup hochladen oder eines aus shared/var/backups/ auswählen, nicht beides.'], 422);
        }
        if ($hochgeladen !== null) {
            $datei = $this->arbeitsDir() . '/' . self::HOCHGELADEN;
            if (!is_dir(dirname($datei)) && !@mkdir(dirname($datei), 0700, true) && !is_dir(dirname($datei))) {
                return $this->seite(InstallSchritt::Wiederherstellung, ['backup' => 'shared/var/backups/ ist nicht beschreibbar.'], 500);
            }
            if (!@copy($hochgeladen, $datei)) {
                return $this->seite(InstallSchritt::Wiederherstellung, ['backup' => 'Das Backup kann nicht zwischengespeichert werden.'], 500);
            }
            @chmod($datei, 0600);
        } elseif (in_array($auswahl, $this->backupsAufDemServer(), true)) {
            $datei = $this->paths->backupDir() . '/' . $auswahl;
        } else {
            return $this->seite(InstallSchritt::Wiederherstellung, ['backup' => 'Bitte ein Backup hochladen oder eines aus shared/var/backups/ auswählen.'], 422);
        }

        try {
            Wiederherstellung::fuer($this->paths)->starten($datei, $schluessel);
        } catch (FalscherSchluessel | KeinBackup $e) {
            if ($hochgeladen !== null) {
                @unlink($datei);
            }

            return $this->seite(InstallSchritt::Wiederherstellung, [$e instanceof FalscherSchluessel ? 'schluessel' : 'backup' => $e->getMessage()], 422);
        } catch (BackupLaeuftBereits $e) {
            return $this->seite(InstallSchritt::Wiederherstellung, ['backup' => $e->getMessage()], 409);
        }

        $_SESSION[self::SESSION_KEY]['server_key'] = base64_encode($schluessel);
        $_SESSION[self::SESSION_KEY]['hochgeladen'] = $hochgeladen !== null;
        $this->weiterZu(InstallSchritt::WiederherstellungLaeuft);

        return Response::redirect('/install');
    }

    /**
     * One step of the restore chain, called by public/js/install.js until
     * it answers `fertig`. The last one writes config.php.
     */
    public function wiederherstellenSchritt(Request $request): ResponseInterface
    {
        if (!$this->token()->isReleased() || $this->schritt() !== InstallSchritt::WiederherstellungLaeuft) {
            return Response::json(['fehler' => 'Es läuft keine Wiederherstellung. Bitte die Seite neu laden.'], 409);
        }
        $schluessel = (string) base64_decode($this->stand()['server_key'], true);
        $kette = Wiederherstellung::fuer($this->paths);

        try {
            $stand = $kette->schritt($this->pdo(), $schluessel);
        } catch (BackupLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\PDOException $e) {
            // Never getMessage(): a failed INSERT quotes the row - member data.
            return Response::json(['fehler' => self::datenbankFehler($e)], 500);
        } catch (\RuntimeException $e) {
            return Response::json(['fehler' => $e->getMessage()], 500);
        }

        $antwort = [
            'fertig' => false,
            'phase' => (string) $stand['phase'],
            'fortschritt' => Wiederherstellung::fortschritt($stand),
        ];
        if ($stand['phase'] !== Wiederherstellung::PHASE_FERTIG) {
            return Response::json($antwort);
        }

        if (($_SESSION[self::SESSION_KEY]['hochgeladen'] ?? false) === true) {
            @unlink($this->arbeitsDir() . '/' . self::HOCHGELADEN);
        }
        $fertig = $this->abschliessen($request, AuditAktion::RestoreDurchgefuehrt, [
            'app_version' => (string) ($stand['manifest']['app_version'] ?? ''),
            'migrationen' => (int) $stand['migrationen'],
        ]);
        if (is_string($fertig)) {
            return Response::json(['fehler' => $fertig], 500);
        }

        return Response::json([
            ...$antwort,
            'fertig' => true,
            'cron_url' => $fertig['cronUrl'],
            'setup_uebrig' => $fertig['setupUebrig'],
            'abgelehnt' => (int) $stand['abgelehnt'],
        ]);
    }

    // ------------------------------------------------------- start over

    /**
     * "Neu beginnen": forgets the run. A first admin already created goes
     * again, so the next attempt finds an empty account table; the
     * migrated tables stay (migrating is idempotent). A running restore is
     * discarded with its plaintext work files.
     */
    public function neu(Request $request): ResponseInterface
    {
        if (!$this->token()->isReleased()) {
            return $this->freigabeSeite([], 403);
        }
        $stand = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($stand) && is_int($stand['admin_id'] ?? null)) {
            try {
                new BenutzerRepository($this->pdo())->delete($stand['admin_id']);
            } catch (\PDOException) {
                // The database is gone: then there is nothing to tidy up.
            }
        }
        try {
            Wiederherstellung::fuer($this->paths)->abbrechen();
        } catch (BackupLaeuftBereits) {
            // A step is running right now; its work files go with the next start.
        }
        @unlink($this->arbeitsDir() . '/' . self::HOCHGELADEN);
        unset($_SESSION[self::SESSION_KEY]);

        return Response::redirect('/install');
    }

    // -------------------------------------------------------- 6. finish

    /** Step 6 for a fresh installation: the finished page, or the mail step with the error. */
    private function fertigSeite(Request $request): Response
    {
        $fertig = $this->abschliessen($request, AuditAktion::BenutzerAngelegt);

        return is_string($fertig)
            ? $this->seite(InstallSchritt::Mail, ['abschluss' => $fertig], 500)
            : $this->render('install/fertig', [...$fertig, 'wiederherstellung' => false], 200);
    }

    /**
     * Step 6: config.php - which closes /install for good -, the channel
     * from setup.php, one audit entry, the leftovers of setup.php.
     *
     * @param array<string, scalar> $details
     * @return array{cronUrl: string, setupUebrig: bool, mailEingerichtet: bool}|string
     *         what the finished page shows, or why config.php was not written
     */
    private function abschliessen(Request $request, AuditAktion $aktion, array $details = []): array|string
    {
        $stand = $this->stand();
        $schluessel = (string) base64_decode($stand['server_key'], true);

        try {
            $pdo = $this->pdo();
            new SettingRepository($pdo)->set(UpdateService::SETTING_CHANNEL, $stand['kanal']);
            $mailEingerichtet = $this->mailEinstellungen($pdo)->get()->istVollstaendig();
            $cronToken = ConfigWriter::schreiben($this->paths->configFile(), $stand['db'], $stand['server_key']);
        } catch (\PDOException $e) {
            return self::datenbankFehler($e);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $adminId = $stand['admin_id'] ?? null;
        try {
            new AuditLog($pdo, $schluessel)->protokollieren(
                $aktion,
                $adminId,
                $adminId === null ? null : 'user',
                $adminId,
                [...$details, 'durch' => 'installer'],
                $request->ip,
            );
        } catch (\Throwable) {
            // config.php exists: the installation is done either way. A
            // missing first audit row is not worth a broken finish.
        }

        foreach ([$this->paths->webDir() . '/setup.php', $this->paths->setupChannelFile(), $this->paths->installTokenFile()] as $datei) {
            if (is_file($datei)) {
                @unlink($datei);
            }
        }
        unset($_SESSION[self::SESSION_KEY], $_SESSION[InstallToken::SESSION_KEY]);

        return [
            'cronUrl' => $request->ownOrigin() . '/cron?token=' . $cronToken,
            // Some hosts let the FTP user own it, and PHP may not delete it.
            'setupUebrig' => is_file($this->paths->webDir() . '/setup.php'),
            'mailEingerichtet' => $mailEingerichtet,
        ];
    }

    // -------------------------------------------------------- helpers

    /**
     * The page for a step that does not match the session's: the token page
     * without release, otherwise the step the run is really at (409) - a
     * second tab or the back button must not skip or repeat a step.
     */
    private function erwarte(InstallSchritt $schritt): ?Response
    {
        if (!$this->token()->isReleased()) {
            return $this->freigabeSeite([], 403);
        }
        $aktuell = $this->schritt();

        return $aktuell === $schritt ? null : $this->seite($aktuell, status: 409);
    }

    private function schritt(): InstallSchritt
    {
        $stand = $_SESSION[self::SESSION_KEY] ?? null;
        $schritt = is_array($stand) ? InstallSchritt::tryFrom((string) ($stand['schritt'] ?? '')) : null;

        return $schritt ?? InstallSchritt::Datenbank;
    }

    private function weiterZu(InstallSchritt $schritt): void
    {
        $_SESSION[self::SESSION_KEY]['schritt'] = $schritt->value;
    }

    /**
     * @return array{db: array{host: string, port: int, name: string, user: string, password: string}, server_key: string, kanal: string, origin?: string, admin_id?: int, admin_email?: string, hochgeladen?: bool}
     */
    private function stand(): array
    {
        /** @var array{db: array{host: string, port: int, name: string, user: string, password: string}, server_key: string, kanal: string, origin?: string, admin_id?: int, admin_email?: string, hochgeladen?: bool} $stand */
        $stand = $_SESSION[self::SESSION_KEY];

        return $stand;
    }

    private function pdo(): \PDO
    {
        return self::verbinden($this->stand()['db']);
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     */
    private static function verbinden(array $db): \PDO
    {
        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            $db['user'],
            $db['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }

    /** Whether the database holds an account already (a table missing = none). */
    private static function hatBenutzer(\PDO $pdo): bool
    {
        try {
            return new BenutzerRepository($pdo)->gibtEs();
        } catch (\PDOException) {
            return false;
        }
    }

    /** Never the driver's message: it may quote row data. */
    private static function datenbankFehler(\PDOException $e): string
    {
        return sprintf('Datenbankfehler (SQLSTATE %s, Code %s). Bitte die Seite neu laden und es noch einmal versuchen.', (string) $e->getCode(), (string) ($e->errorInfo[1] ?? '?'));
    }

    private static function passwortFeld(Request $request, string $name): string
    {
        $wert = $request->post[$name] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function mailEinstellungen(\PDO $pdo): MailSettingsRepository
    {
        return new MailSettingsRepository(
            new SettingRepository($pdo),
            new ServerKeyGeheimnisSchutz((string) base64_decode($this->stand()['server_key'], true)),
        );
    }

    /** The path of an uploaded file, or null - only what PHP received as an upload. */
    private function hochgeladen(Request $request, string $feld): ?string
    {
        $datei = $request->files[$feld] ?? null;
        if (!is_array($datei) || ($datei['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            return null;
        }
        $pfad = (string) ($datei['tmp_name'] ?? '');
        $istUpload = $this->istUpload ?? is_uploaded_file(...);

        return $pfad !== '' && $istUpload($pfad) ? $pfad : null;
    }

    /** @return list<string> names of the backups in shared/var/backups/, newest first */
    private function backupsAufDemServer(): array
    {
        $namen = [];
        if (!is_dir($this->paths->backupDir())) {
            return [];
        }
        foreach (scandir($this->paths->backupDir()) ?: [] as $name) {
            $pfad = $this->paths->backupDir() . '/' . $name;
            if (preg_match(BackupService::NAME, $name) === 1 && is_file($pfad) && !is_link($pfad)) {
                $namen[] = $name;
            }
        }
        rsort($namen, SORT_STRING);

        return $namen;
    }

    private function arbeitsDir(): string
    {
        return $this->paths->backupDir() . '/wiederherstellung';
    }

    /**
     * The channel chosen in setup.php (shared/setup_kanal.txt): without it an
     * instance installed from a pre-release would afterwards look for
     * updates on stable.
     */
    private function kanalAusSetup(): string
    {
        $datei = $this->paths->setupChannelFile();

        return is_file($datei) && trim((string) file_get_contents($datei)) === 'beta' ? 'beta' : 'stable';
    }

    private function token(): InstallToken
    {
        return new InstallToken($this->paths->installTokenFile());
    }

    /**
     * @param array<string, string> $fehler
     */
    private function freigabeSeite(array $fehler = [], int $status = 200): Response
    {
        return $this->render('install/freigabe', ['fehler' => $fehler], $status);
    }

    /**
     * @param array<string, string> $fehler
     * @param array<string, mixed> $eingabe what the form shows again - never a password
     * @param array<string, mixed> $daten
     */
    private function seite(InstallSchritt $schritt, array $fehler = [], int $status = 200, array $eingabe = [], array $daten = []): Response
    {
        $stand = $_SESSION[self::SESSION_KEY] ?? [];
        $extra = match ($schritt) {
            InstallSchritt::Datenbank => ['db' => $eingabe['db'] ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '']],
            InstallSchritt::Admin => ['admin' => $eingabe['admin'] ?? ['email' => '', 'name' => '']],
            InstallSchritt::Mail => [
                'mail' => $eingabe['mail'] ?? $this->gespeicherteMail(),
                'empfaenger' => $eingabe['empfaenger'] ?? (string) ($stand['admin_email'] ?? ''),
                'sicherheiten' => SmtpSecurity::cases(),
            ],
            InstallSchritt::Wiederherstellung => [
                'backups' => $this->backupsAufDemServer(),
                'uploadGrenze' => self::uploadGrenze(),
            ],
            default => [],
        };

        return $this->render('install/' . $schritt->value, [
            'schritt' => $schritt,
            'fehler' => $fehler,
            'kanal' => is_array($stand) ? (string) ($stand['kanal'] ?? $this->kanalAusSetup()) : $this->kanalAusSetup(),
            ...$extra,
            ...$daten,
        ], $status);
    }

    /** What the mail form shows first: what is saved, else the admin as sender. */
    private function gespeicherteMail(): MailSettings
    {
        try {
            $gespeichert = $this->mailEinstellungen($this->pdo())->get();
        } catch (\PDOException) {
            $gespeichert = new MailSettings('', 587, SmtpSecurity::Starttls, '', '', false, '', '', '');
        }
        if ($gespeichert->absender !== '' || $gespeichert->host !== '') {
            return $gespeichert;
        }

        return new MailSettings(
            host: '',
            port: $gespeichert->port,
            sicherheit: $gespeichert->sicherheit,
            benutzer: '',
            passwort: '',
            passwortGesetzt: $gespeichert->passwortGesetzt,
            absender: (string) ($this->stand()['admin_email'] ?? ''),
            antwortAn: '',
            vereinsname: '',
            oeffentlicheUrl: PublicUrl::normalize((string) ($this->stand()['origin'] ?? '')) ?? '',
        );
    }

    /** The smaller of upload_max_filesize and post_max_size as PHP writes it, '' if unknown. */
    private static function uploadGrenze(): string
    {
        $werte = array_filter([(string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size')]);
        if ($werte === []) {
            return '';
        }
        usort($werte, static fn(string $a, string $b): int => self::bytes($a) <=> self::bytes($b));

        return $werte[0];
    }

    private static function bytes(string $wert): int
    {
        $zahl = (int) $wert;

        return match (strtolower(substr(trim($wert), -1))) {
            'g' => $zahl * 1024 ** 3,
            'm' => $zahl * 1024 ** 2,
            'k' => $zahl * 1024,
            default => $zahl,
        };
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function render(string $vorlage, array $daten, int $status): Response
    {
        // Every installer page runs with a session, whose cache limiter
        // sends no-store (Session::OPTIONEN) - the key page included.
        // setup.php wrote them already; repeated on every page so an
        // installation whose setup.php could not gets another try - and a
        // visible warning instead of a silently reachable shared/.
        $ungeschuetzt = DenyHtaccess::ensureAll(dirname($this->paths->releaseRoot));

        return Response::html($this->view->render($vorlage, [
            'title' => 'Installation',
            'ungeschuetzt' => $ungeschuetzt === [] ? '' : DenyHtaccess::describe($ungeschuetzt),
            'csrf' => $this->session->csrfToken(),
            'scripts' => ['/js/install.js'],
            ...$daten,
        ], Area::Oeffentlich), $status);
    }
}
