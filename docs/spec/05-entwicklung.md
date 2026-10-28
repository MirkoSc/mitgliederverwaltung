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
