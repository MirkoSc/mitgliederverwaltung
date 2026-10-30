# 05 – Entwicklung

## 1. Lokale Umgebung

Voraussetzung nur Docker (PHP/Composer lokal nicht nötig):

```bash
docker compose run --rm app composer install    # vendor/ (Composer steckt im Image)
docker compose up --build                       # http://localhost:8080
docker compose run --rm app vendor/bin/phpunit
node --test 'tests/js/**/*.test.js'
docker compose exec app php bin/migrate.php
docker compose exec app php tools/benutzer-anlegen.php admin@example.org "Erika Beispiel"
```

Parallel arbeitende Agenten nehmen je ein eigenes Projekt (§6), z. B.
`docker compose -p issue-7 run --rm app vendor/bin/phpunit` – `run`
veröffentlicht keine Ports, die Datenbank nie.

- `docker-compose.yml`: PHP 8.5 mit Apache + MariaDB 10.6. Produktionsnah:
  `disable_functions=exec,shell_exec,…`, `max_execution_time=30`,
  `wait_timeout=120`; die Zeitzone setzt allein der Bootstrap (das Image
  steht absichtlich auf UTC, damit ein Umweg auffällt).
- Basis des Images ist das offizielle Drupal-Image (`php:8.5-apache` plus
  fertig übersetzte gd, zip, pdo_mysql, opcache); Drupal selbst wird
  entfernt. So braucht der Bau keinen Debian-Paketspiegel – in der
  Sandbox der Agenten ist der gesperrt.
- Composer im Image läuft über einen Wrapper ohne `disable_functions`
  (Composer braucht `proc_open`, die Anwendung nie).
- Ohne Internet im Container (Sandbox): `composer install` auf dem Host.
  Holt Composer dort Pakete als Git-Klon statt als ZIP, die `.git`-Ordner
  unter `vendor/` löschen (`find vendor -name .git -type d -prune -exec
  rm -rf {} +`) – sonst ruft PHPUnit `git` per `proc_open` auf, und das
  ist im Image gesperrt.
- `docker/web/` = Docroot (Shim, `.htaccess`, `.user.ini`) – dieselben
  Dateien wie auf dem Server.
- `docker/shared/config.php` ist committet (Dev-Zugang zur lokalen
  MariaDB, kein Geheimnis, `debug` an); alles andere unter
  `docker/shared/` ist ignoriert. Ohne `config.php` meldet jede Seite
  „Noch nicht eingerichtet" (ab #3: Installer).
- Ersten Admin in der lokalen Datenbank anlegen (bis der Installer, #3,
  das übernimmt): `docker compose exec app php tools/benutzer-anlegen.php
  admin@example.org "Erika Beispiel"` – das Passwort wird zweimal abgefragt
  (nicht als Argument, sonst steht es in der Shell-History). Weitere
  Benutzer per Einladung unter Einstellungen → Benutzer; ohne
  Mail-Einstellungen geht dabei keine Mail raus.

## 2. Tests

- **PHPUnit** mit `failOnDeprecation`, `failOnWarning`,
  `failOnRisky`; Test-DB `mitgliederverwaltung_test` in der
  Docker-MariaDB.
- **JS**: reine Logik in eigenen Modulen, getestet mit `node --test
  'tests/js/**/*.test.js'` (keine npm-Abhängigkeiten; ein Skript in
  `public/js/` exportiert seine Logik per `module.exports`, wenn es unter
  Node läuft – Muster: `public/js/benutzermenue.js`).
- **Feature-Tests** erben von `App\Tests\Support\AppTestCase`: echte
  Verdrahtung (alle Routen-Dateien, Guard, CSRF, Templates), angemeldet
  per `als(Rolle::Vorstand)` ohne echte Anmeldung; `get()`/`post()`
  (POST mit CSRF-Token, abschaltbar). Datenbank-Tests erben von
  `App\Tests\Support\DatabaseTestCase` (leere Test-DB je Test).
- Oberflächen werden mobil (360 px), am PC und am PC im
  Barrierefrei-Modus geprüft.
- **Architektur-Tests**: `NoShellExecutionTest`, `CspComplianceTest`,
  `ShimContentTest`, Routen-Zugriffsmatrix, CSRF auf jeder POST-Route.
- Testdaten nur erfunden. Keine echten Namen, Adressen, IBANs.

## 3. Übernahme aus den Schwesterprojekten

Diese Teile werden aus `MirkoSc/vereinsbelege` (bzw. dessen Ursprung
`MirkoSc/vereinskalender`) **kopiert und angepasst** (Namespace, Name,
Repository-URL), nicht neu erfunden:

| Bereich | Inhalt |
|---|---|
| Http | Kernel, Router, Request, Response, Session, Cookie, CsrfSchutz, LoginGuard, Zugriff |
| Config / Database | Config-Lader, ConnectionFactory (inkl. Neuverbindung wegen `wait_timeout`) |
| Service/Crypto | Server-Schlüssel (`secretbox`), Blob-Verschlüsselung (secretstream), Speicherformate – **ohne** Tresor, Blind Index, Wiederherstellungsschlüssel |
| Service/Account | Login, PasswordHasher, PasswordPolicy, MFA (TOTP, E-Mail-Code, Backup-Codes, Geräte), Reset, Einladung, PendingLogin (ohne Tresor-Teil) – **ohne** SessionVault |
| Service/Storage | Blobs (`fs`/`db`), Chunk-Upload |
| Service/Mail | SmtpTransport, Mail-Queue, PublicUrl |
| Service/Update, Migration, Backup, MaintenanceMode, RateLimiter, SystemCheck | wie 04 |
| Installer | setup.php-Vorlage, `bin/build_setup.php`, InstallController, InstallToken, ConfigWriter, FirstAdminSetup |
| Support | FileLogger, QrCode |
| Admin | Benutzer (feste Rollen statt Rollen-Editor), Mail, Rechtstexte, Backup, Update, Systemcheck, Audit-Log (Serverlogik) |
| Infrastruktur | Docker-Umgebung, `phpunit.xml`, CI-Workflow, Release-Workflow, `tools/release-signatur.php` |
| Daten | `app/data/haeufige-passwoerter.txt` |
| Oberfläche | Layout, Partials, `app.css` (als Ausgangspunkt, neu gestaltet), htmx (vendored), `jobs.js`, `update.js`, Seiten für Login/2FA/Installer/Update/Backup/Systemcheck/Benutzer |

Nicht übernommen: alles Fachliche aus Vereinsbelege (Belege, KI, Bank,
Lieferanten, Worker …) und aus dem Vereinskalender (Event-Store, Kalender,
Push, PWA, ICS).

Code wird **kopiert**, nicht als gemeinsames Paket geteilt (E-04). Ein
Sicherheitsfix in einem Projekt wird als Issue in den anderen nachgezogen.

## 4. CI

GitHub Actions bei Push/PR: Composer-Validierung, PHPUnit gegen MariaDB
(Service-Container), `node --test`, Syntaxprüfung aller PHP-Dateien,
Prüfung, dass `setup.php` aus der Vorlage aktuell ist. `main` ist
geschützt (PR + grüne CI Pflicht).

## 5. Konventionen

- Namespace `App\`, PSR-4 aus `app/src/`, `declare(strict_types=1)`.
- Klassen- und Methodennamen dürfen deutsch sein, wo sie Fachbegriffe
  tragen (wie in den Schwesterprojekten); Kommentare und Commits Englisch;
  UI-Texte Deutsch.
- SQL nur in Repositories, nur Prepared Statements.
- Fremdbibliotheken: reines PHP, PHP 8.5, aktiv gepflegt, GPL-kompatibel;
  Prüfung im PR notieren. Lieber kleine eigene Implementierung eines exakt
  spezifizierten Protokolls (SMTP, TOTP, QR) als eine große Abhängigkeit.
- JS-Bibliotheken vendored unter `public/js/vendor/` mit `README.md`
  (Version, Lizenz, SHA-256), nie per CDN.
- Lizenz GPLv3.

## 6. Parallele Entwicklung

Bis zu neun Issues werden gleichzeitig von getrennten Agenten bearbeitet
(`docs/ARBEITSPLAN.md`). Damit sie sich nicht gegenseitig blockieren:

- **Erweiterungspunkte statt zentraler Dateien** (angelegt in #1):

  | Was | Wo ein Issue etwas hinzufügt |
  |---|---|
  | Routen | eigene Datei `app/routes/<bereich>.php`, gibt `static function (App\Http\Routen $routen): void` zurück (alle Dateien werden geladen, in Namensreihenfolge); Controller entstehen erst im Handler aus `$routen->dienste()` |
  | Zugriffsmatrix-Test | eigene Datei `tests/Http/routen-zugriff/<bereich>.txt` (gleicher Name wie die Routen-Datei), eine Zeile je Route: `GET /pfad -> recht` |
  | Rechte | `Permission`-Enum ist in #1 **vollständig** angelegt (Tabelle in 02 §6) – nicht erweitern, nur nutzen; fehlt wirklich eins, im PR begründen |
  | Navigation | in #1 vollständig (`App\View\Navigation`: Mitglieder `/app/mitglieder`, Listen `/app/listen`, Karte `/app/karte` (#91), Beiträge `/app/beitraege`, Einstellungen `/admin`, Benutzermenü). Ein Eintrag erscheint inaktiv, bis seine Route existiert – wer die Route anlegt, schaltet ihn frei |
  | Einstellungs-Abschnitte | eigene Klasse `app/src/Admin/Einstellungen/<Name>Abschnitt.php` (implementiert `Abschnitt`) + Template, automatisch gefunden; Formulare posten an Routen der eigenen Routen-Datei |
  | Listen | eigene Klasse `app/src/Listen/<Name>Liste.php` (implementiert `Liste`: Filter, Spalten, Zeilen – Seite, Sortierung und csv/xlsx-Export liefert #24, siehe 09 §1), automatisch gefunden, erreichbar unter `/app/listen/<schluessel>` |
  | Reiter/Abschnitte am Mitglied | eigene Klasse `app/src/App/Mitglied/<Name>Reiter.php` bzw. `…Abschnitt.php` (implementiert `Reiter` bzw. `Abschnitt`), automatisch gefunden; Links und Formulare über `$einbettung` (06 §5), weil das Mitglied auf jeder Listen-Seite stehen kann |
  | Cron-Aufgaben | eigene Klasse `app/src/Service/Cron/<Name>Task.php` (implementiert `CronTask`), automatisch gefunden (01 §5) |
  | Dienste | was ein Bereich braucht, baut er selbst aus `App\Dienste` (`pdo()`, `einstellungen()`, `jobs()`, `view`, `session` …) und teilt es je Request über `$dienste->dienst(Klasse::class, fn)` – keine zentrale Verdrahtung |
  | CSS | Designsystem `public/css/app.css` gehört #9; Ergänzungen als eigene Datei `public/css/teile/<name>.css` (automatisch eingebunden, nach `app.css`); das Stylesheet einer vendored Bibliothek bindet nur ihre Seite ein, über `styles` im Render-Aufruf (vor `app.css`, z. B. Leaflet auf der Karte) |
  | JS | eigene Datei `public/js/<name>.js`; Seiten binden sie über `scripts` im Render-Aufruf ein. Alle Deklarationen in **einer Funktion** (`(function () { … })();`) – die Einstellungsseite lädt mehrere Skripte nebeneinander, ein doppeltes `const` auf oberster Ebene bricht das zweite ab (`tests/js/skripte-nebeneinander.test.js`) |
  | Migrationen | nur im zugewiesenen Nummernbereich des Issues (`docs/ARBEITSPLAN.md`); ohne Bereich `1NN` mit NN = Issue-Nummer. Der Runner sortiert numerisch, erlaubt Lücken und holt später gemergte kleinere Nummern nach (03 §4). |

  Alle Erweiterungen (Abschnitt, Liste, Reiter) teilen
  `App\Support\Erweiterung`: `erzeugen(Dienste)`, `schluessel()`
  (klein mit Bindestrich, eindeutig), `titel()`, `reihenfolge()`,
  `recht()`. Gefunden werden sie über den Dateinamen
  (`App\Support\Erweiterungen`).

- Gemeinsame Domain-Klassen und Repositories (#15) werden von späteren
  Issues **ergänzt**, nicht umgebaut. Größere Änderungen an fremdem Code
  sind ein eigenes Issue.
- **Tests isoliert**: jeder Agent nutzt ein eigenes Docker-Projekt
  (`docker compose -p issue-<nr> run --rm …`), keine festen Host-Ports
  außer beim bewussten `docker compose up`.
- **Merge-Konflikte** löst der Job, der zuletzt mergen will: `main`
  einmergen, Konflikte so lösen, dass beide Features erhalten bleiben,
  Tests erneut laufen lassen.
- Docker-Images aus `mirror.gcr.io` (z. B. `mirror.gcr.io/library/php:8.5-cli`),
  weil Docker Hub häufig drosselt.

