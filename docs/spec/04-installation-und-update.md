# 04 – Installation, Update, Release, Backup

Übernahme aus Vereinsbelege (`docs/spec/06-betrieb.md` §1–2 dort), das
seinerseits auf dem Vereinskalender aufbaut (dortige CLAUDE.md §2, §9,
§10). Mechanik und Klassen werden übernommen, nur Namen/Repository
angepasst. Bei Detailfragen gilt Vereinsbelege als Referenz.

## 1. Release

- Versionierung `vX.Y.Z` (SemVer), `VERSION`-Datei im Repo (`0.0.0-dev`,
  kommt mit dem Projektgerüst).
- **Release-Workflow** (GitHub Action bei Tag `v*`):
  - Job `build` (nur `contents: read`, kein Secret): `composer install
    --no-dev --optimize-autoloader`, `VERSION` schreiben, ZIP
    `mitgliederverwaltung-vX.Y.Z.zip` (app/, public/, vendor/, bin/,
    migrations/, docker/web/, `.htaccess` der Release-Wurzel, VERSION),
    `checksums.txt` (SHA-256 des ZIP **und** jeder Datei darin als
    `release/<pfad>`), `setup.php`.
  - Job `publish` (`contents: write`, Secret `RELEASE_SIGNING_KEY`):
    signiert `checksums.txt` mit Ed25519 → `checksums.txt.sig`,
    veröffentlicht alles als **Pre-Release**.
- Nicht im ZIP: `tests/`, `tools/`, `docs/`, Docker-Dateien außer
  `docker/web/`.
- **Kanäle:**
  - `beta` = neuestes Release inkl. Pre-Releases (Testinstanz).
  - `stable` = `/releases/latest` (ignoriert Pre-Releases, Produktivinstanz).
  - Ablauf: Tag → Pre-Release → Testinstanz (beta) aktualisiert und prüft →
    auf GitHub als „latest" markieren → Produktivinstanz (stable) zieht
    nach. Der Kanal ist ein Setting (`update_kanal`), Wahl schon in
    `setup.php`.
- Öffentlicher Signaturschlüssel im Code (`ReleaseSignatur::PUBLIC_KEY`);
  solange leer: Hinweis statt Sperre. Werkzeug
  `tools/release-signatur.php` (Schlüsselpaar erzeugen, signieren).

## 2. Erstinstallation

1. **`setup.php`** (eigenes Release-Asset, Nextcloud-Stil) per FTP in den
   DocumentRoot laden und **sofort** im Browser aufrufen:
   - Umgebungs-Checkliste (PHP-Version, Erweiterungen, Schreibrechte,
     `rename()`, HTTPS).
   - Kanal wählen (`stable`/`beta`) → `shared/setup_kanal.txt`.
   - `.htaccess` mit `Require all denied` in `shared/` und `releases/`
     **vor** dem Download.
   - Release laden, Signatur + SHA-256 prüfen, nach `releases/vX.Y.Z/`
     entpacken, `rename()` nach `current/`, Docroot-Dateien aus
     `docker/web/` kopieren, `shared/` anlegen.
   - **Installations-Token** (128 Bit) nach `shared/install_token.txt`
     (0600), Weiterleitung auf `/install?token=…`.
   `setup.php` ist aus denselben Klassen gebaut wie der Updater
   (`bin/build_setup.php` bettet `ReleaseDownloader`, `ReleaseSignatur`,
   `DenyHtaccess` ein; CI prüft, dass `setup.php` aktuell ist).
2. **`/install`** (nur solange `config.php` fehlt, nur mit Token-Freigabe):
   1. DB-Zugangsdaten + Verbindungstest.
   2. Server-Schlüssel erzeugen (in der Session bis zum Abschluss).
   3. „Frische Installation" oder „Backup einspielen".
   4. Frisch: Migrationen ab 0, erster Admin (E-Mail, Name, Passwort).
      Der **Server-Schlüssel wird einmal zum Ausdrucken angezeigt**
      („Sicherungsschlüssel" – ohne ihn lassen sich Backups und Dateien
      nicht wiederherstellen, 02 §2).
   5. Mail-Einstellungen + Testmail (überspringbar – aber ohne Mail gibt es
      keinen E-Mail-Code als zweiten Faktor, dann nur die App, 02 §3).
   6. `config.php` schreiben → Installer gesperrt; `setup.php` und Token
      löschen (sonst Hinweis).
   Jeder Schritt ist ein eigener Request; ein abgebrochener Durchlauf
   kann „neu beginnen".
3. Erster Login inkl. Einrichtung des zweiten Faktors.

## 3. Self-Updater

Admin-Seite `/admin/update` (Recht `admin.system`), Schrittkette per AJAX,
Stand in `shared/update_state.json`, jeder Schritt < 30 s und
wiederholbar:

| Schritt | Inhalt |
|---|---|
| `check` | GitHub Releases API (nur dieses Repo), Kanal beachten, nur neuere Version |
| `download` | ZIP + `checksums.txt` + `.sig` nach `shared/var/update/`, Signatur prüfen |
| `extract` | ZIP/Signatur erneut prüfen, nach `releases/vX.Y.Z/` entpacken, Liste ins Release legen |
| `backup` | Backup unmittelbar vor dem Umschalten (§6); Fehler bricht ab |
| `switch` | `maintenance.flag` → `rename(current, releases/_prev)` → `rename(neu, current)` → `opcache_reset()` |
| `migrate` | alle Migrationen > `schema_version`; bei Fehler bleibt Wartungsmodus, Admin wählt Wiederholen oder Rollback |
| `finish` | Docroot-Dateien auffrischen, Selbsttest (Startseite → 200), Flag weg, Download aufräumen, letzte 2 Releases behalten |

- **Download-Härtung**: nur `https` zu den GitHub-Hosts, erste Adresse muss
  zu `MirkoSc/mitgliederverwaltung` gehören, jeder Weiterleitungssprung
  geprüft (max. 5), Zielversion muss neuer als die laufende sein (kein
  Downgrade über manipulierten Stand).
- **Docroot-Dateien**: der Shim wird immer auf den Stand des Releases
  gebracht (Kopie als Konstante `ReleaseSwitcher::SHIM`, weil das Release
  beim Umschalten kurz fehlt; `ShimContentTest` hält alle Kopien gleich);
  `.htaccess`/`.user.ini` nur, solange sie Byte für Byte der Vorlage des
  vorherigen Releases entsprechen – Handänderungen bleiben und werden
  gemeldet. Schlägt der Selbsttest fehl, wird der alte Shim zurückgeschrieben.
- **Rollback** = `_prev` zurück-renamen (Code läuft dank kompatibler
  Migrationen auf dem neuen Schema); Daten-Rollback nur per Restore.
- Absturz zwischen den `rename()`-Aufrufen ist aus dem Dateisystem
  rekonstruierbar; die Kette repariert beim nächsten Aufruf.

## 4. Wartungsmodus

`App\Service\MaintenanceMode`, Flag `shared/maintenance.flag` (JSON: Grund,
Start). Der Shim beantwortet alles außer `/admin` und den Assets, die die
Update-Seite braucht, mit 503.
Das Admin-Layout zeigt bei gesetztem Flag auf jeder Seite ein Banner mit
„Freigeben" (`POST /admin/wartung/aufheben`) – ein abgestürztes Update darf
die Instanz nicht dauerhaft sperren.

Der Shim leitet außerdem `GET`/`HEAD` über HTTP auf HTTPS um (Ausnahmen:
`/cron`, localhost/IP/andere Ports, Notschalter
`shared/https_umleitung_aus.flag`).

## 5. Integritätscheck

`/admin/integritaet`: vergleicht jede Datei in `current/` mit der
signierten `checksums.txt` des Releases. Abweichung = Warnung im Admin
(02 §9).

## 6. Backup & Restore

- Backup = ZIP aus DB-Dump in reinem PHP (`MysqlDumper`, Binärspalten
  als Hex-Literale), Blobs (`fs`), Logo und `manifest.json` (nur Versionen
  und Zahlen) – als Ganzes mit dem Server-Schlüssel verschlüsselt
  (`.zip.enc`, 02 §2). Der DB-Dump enthält Mitgliederdaten im Klartext;
  erst die Verschlüsselung macht die Datei harmlos.
- `config.php` kommt nie mit ins Backup. Betriebshandbuch: `config.php`
  bzw. den ausgedruckten Sicherungsschlüssel getrennt sichern.
- Erstellen als Schrittkette (`/admin/backups`), Rotation 10, Download
  gestreamt.
- Restore im Installer als Schrittkette (Dump häppchenweise mit Offset im
  Status, danach nur Migrationen > Backup-Stand). Der Restore fragt den
  Sicherungsschlüssel ab (oder `config.php` hochladen) und schreibt ihn
  in die neue `config.php`; ohne ihn ist das Backup nicht lesbar.

## 7. Systemcheck

`/admin/systemcheck` prüft im laufenden Betrieb, was sich beim Hoster
ändern kann: effektives `zend.exception_ignore_args`, `wait_timeout`,
`max_execution_time`, `memory_limit`, Upload-Grenzen, Erweiterungen
(sodium, gd, zip, curl), `PASSWORD_ARGON2ID`, Schreibrechte auf
`shared/var/`, sperrende `.htaccess` in `shared/`/`releases/`/`current/`,
`shared/config.php` nicht über die eigene Domain abrufbar. Dienst ohne
Abhängigkeit zu Http/Session.

## Pflicht-Tests

`DenyHtaccessTest`, `ShimContentTest`, `ReleaseSwitcherTest` (Umschalten,
Idempotenz, Reparatur nach Absturz, Rollback, Shim-Selbstheilung),
`ReleaseDownloaderTest` (Kanäle, Host-/Repo-Prüfung, Weiterleitungen),
`ReleaseSignaturTest`, `UpdateChainTest` (ganze Kette gegen ein lokales
Release-ZIP, manipuliertes ZIP, kein Downgrade), `MaintenanceModeTest`,
`ConfigWriterTest`, `InstallFlowTest` (Token, `config.php` erst nach
Bestätigung, Kanal-Übernahme, Sicherungsschlüssel einmal angezeigt),
Backup+Restore-Roundtrip (Backup verschlüsselt, Restore mit Schlüssel,
falscher Schlüssel abgelehnt), `SystemCheckTest`,
Test der Client-Logik der Update-Schrittkette (Reihenfolge, Wiederholung
ab dem fehlgeschlagenen Schritt).
