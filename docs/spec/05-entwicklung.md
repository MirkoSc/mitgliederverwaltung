# 05 – Entwicklung

## 1. Lokale Umgebung

Voraussetzung nur Docker (PHP/Composer lokal nicht nötig):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker compose up --build                       # http://localhost:8080
docker compose run --rm app php vendor/bin/phpunit
node --test tests/js/*.test.js
docker compose exec app php bin/migrate.php
docker compose exec app php bin/build_setup.php
```

- `docker-compose.yml`: PHP 8.5 (Apache, fpm-ähnliche Einstellungen) +
  MariaDB 10.6. Produktionsnah: `disable_functions=exec,shell_exec,…`,
  `max_execution_time=30`, `wait_timeout=120`, Zeitzone `Europe/Berlin`.
- `docker/web/` = Docroot (Shim, `.htaccess`, `.user.ini`) – dieselben
  Dateien wie auf dem Server.
- `docker/shared/config.php` ist committet (Dev-Zugang zur lokalen
  MariaDB, kein Geheimnis); alles andere unter `docker/shared/` ist
  ignoriert. Ohne `config.php` startet der Installer.

## 2. Tests

- **PHPUnit** mit `failOnDeprecation`, `failOnWarning`,
  `failOnRisky`; Test-DB `mitgliederverwaltung_test` in der
  Docker-MariaDB.
- **JS**: reine Logik in eigenen Modulen, getestet mit `node --test
  tests/js` (keine npm-Abhängigkeiten). Oberflächen werden mobil
  (360 px), am PC und am PC im Barrierefrei-Modus geprüft.
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
  | Routen | eigene Datei `app/routes/<bereich>.php` (alle Dateien werden geladen) |
  | Zugriffsmatrix-Test | eigene Datei `tests/Http/routen-zugriff/<bereich>.txt` |
  | Rechte | `Permission`-Enum ist in #1 **vollständig** angelegt – nicht erweitern, nur nutzen; fehlt wirklich eins, im PR begründen |
  | Navigation | in #1 vollständig (Mitglieder, Listen, Beiträge, Einstellungen, Benutzermenü) |
  | Einstellungs-Abschnitte | eigene Klasse `app/src/Admin/Einstellungen/<Name>Abschnitt.php` + Template, automatisch gefunden |
  | Listen | eigene Klasse `app/src/Listen/<Name>Liste.php`, automatisch gefunden |
  | Reiter/Abschnitte am Mitglied | eigene Klasse `app/src/App/Mitglied/<Name>Reiter.php` bzw. `…Abschnitt.php`, automatisch gefunden |
  | CSS | Designsystem `public/css/app.css` gehört #9; Ergänzungen als eigene Datei `public/css/teile/<name>.css` (automatisch eingebunden) |
  | JS | eigene Datei `public/js/<name>.js` |
  | Migrationen | nur im zugewiesenen Nummernbereich des Issues (`docs/ARBEITSPLAN.md`); ohne Bereich `1NN` mit NN = Issue-Nummer. Der Runner sortiert numerisch und erlaubt Lücken. |

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

