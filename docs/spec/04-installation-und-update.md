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

Abschnitt **Update** der Einstellungen (`/admin#update`, Recht
`admin.system`, Teil von „System“), Schrittkette per AJAX
(`public/js/update.js`, eine Anfrage je Schritt:
`POST /admin/update/schritt/<schritt>`), Stand in
`shared/update_state.json`, jeder Schritt < 30 s und wiederholbar. Die
Schrittnamen sind ein Vertrag zwischen den Releases: nach `switch` laufen
die nächsten Anfragen schon auf dem neuen Code.

| Schritt | Inhalt |
|---|---|
| `check` | GitHub Releases API (nur dieses Repo), Kanal beachten, nur neuere Version |
| `download` | ZIP + `checksums.txt` + `.sig` nach `shared/var/update/`, Signatur prüfen |
| `extract` | ZIP/Signatur erneut prüfen, nach `releases/vX.Y.Z/` entpacken, Liste ins Release legen |
| `backup` | Backup unmittelbar vor dem Umschalten (§6); Fehler bricht ab |
| `switch` | `maintenance.flag` → `rename(current, releases/_prev)` → `rename(neu, current)` → `opcache_reset()`; das Flag **bleibt** gesetzt |
| `migrate` | alle noch nicht in `schema_version` eingetragenen Migrationen (03 §4); bei Fehler bleibt Wartungsmodus, Admin wählt Wiederholen oder Rollback |
| `finish` | Docroot-Dateien auffrischen, Flag weg, Selbsttest (Startseite → 200), Download aufräumen, letzte 2 Releases behalten |

- **Wartungsmodus über die Kette:** das Flag wird von `switch` gesetzt und
  erst von `finish` entfernt – solange läuft nichts auf einem halb
  migrierten Schema. `finish` nimmt es *vor* dem Selbsttest ab (der Test
  geht durch den Shim und bekäme sonst 503); besteht der Test nicht,
  kommt der alte Shim zurück **und** das Flag wird mit dem Grund
  „Selbsttest fehlgeschlagen“ wieder gesetzt: ein Release, das nicht
  antwortet, bleibt nicht öffentlich. Der Ausweg ist der Rollback, der das
  Flag selbst beendet.
- **Weitermachen nach Neuladen:** ist eine Kette angefangen und nicht
  abgeschlossen, bietet die Seite „Update auf Version X fortsetzen“ ab
  dem Schritt nach dem letzten abgeschlossenen. „Schritt wiederholen“
  startet beim fehlgeschlagenen Schritt.
- **Rollback-Knopf:** nach einem Fehler nur in `migrate` und `finish`
  (erst dann ist das neue Release das laufende; davor wäre `_prev` das
  Release eines älteren Updates, also ein Downgrade). Daneben steht der
  Knopf mit Rückfrage dauerhaft im Abschnitt, solange `releases/_prev`
  existiert.
- **Fehlertexte** der Schritte enthalten keine Datenbankmeldungen (sie
  können Werte zitieren): von einer fehlgeschlagenen Migration erscheinen
  nur Nummer, Name und Anweisung.
- **Audit-Log:** `update.durchgefuehrt` (nach `finish`),
  `update.zurueckgerollt`, `wartung.aufgehoben`.

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
Start). Der Shim beantwortet alles außer `/admin`, der Anmeldung
(`/anmelden…` – ein Admin, dessen Sitzung während eines abgestürzten
Updates abgelaufen ist, muss wieder hineinkommen) und den Assets, die diese
Seiten brauchen, mit 503.
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
  Status, danach die Migrationen, die im Backup noch nicht eingetragen
  sind, 03 §4). Der Restore fragt den
  Sicherungsschlüssel ab (oder `config.php` hochladen) und schreibt ihn
  in die neue `config.php`; ohne ihn ist das Backup nicht lesbar.

**Umsetzung** (`App\Service\Backup`, übernommen aus Vereinsbelege):

- Dateiformat `backup_JJJJMMTT_HHMMSS[_n].zip.enc` (`BackupCipher`):
  `MVBK` | Dateischlüssel als `secretbox` unter dem Server-Schlüssel
  (73 Byte) | Blob-Kopf (Versionsbyte + secretstream-Header) | Blöcke
  à 64 KiB wie ein Blob, der letzte mit `TAG_FINAL`. Ein falscher
  Schlüssel scheitert am Kopf, bevor etwas geschrieben wird; ein
  abgeschnittenes, verändertes oder verlängertes Backup ist ein Fehler.
- ZIP-Inhalt: `dump.sql` (`MysqlDumper`), `blobs/<2>/<id>` (Backend `fs`),
  `logo/<name>`, `manifest.json` (App- und Schemaversion, Zeitpunkt,
  Anzahlen). Das Update-Backup (#4) lässt `blobs/` weg.
- `BackupErstellung`: Phasen `dump` → `dateien` → `abschluss` →
  `verschluesseln` → `fertig`. Das Klartext-ZIP liegt nur während der Kette
  als Arbeitsdatei `erstellung_<id>.zip.part` (0600) in `var/backups/` und
  wird gelöscht, sobald das verschlüsselte Backup abgelegt ist. Stand in
  `var/backups/erstellung.json` (nicht in `job`: die Tabelle steckt im
  Dump), Sperre per `flock`. Der Cron verwirft eine Kette, die eine Stunde
  nicht weiterlief (`BackupAufraeumenTask`), samt Klartext.
- `Wiederherstellung` (für den Installer, #3): Phasen `entschluesseln` →
  `auspacken` → `dump` → `dateien` → `migrationen` → `fertig`, Stand in
  `var/backups/wiederherstellung/wiederherstellung.json`. Der Schlüssel wird
  nie gespeichert: der Aufrufer übergibt ihn jedem Schritt, der
  secretstream-Zustand im Stand ist unter ihm verschlüsselt.
  `RestoreService::schluesselAusEingabe()` (Base64, Leerzeichen und
  Zeilenumbrüche des Ausdrucks egal) und `schluesselAusConfig()` (Regex,
  nie `include`) lesen den Schlüssel.

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
ab dem fehlgeschlagenen Schritt, Rollback-Knopf erst ab `migrate`),
`UpdateSeiteTest` (Abschnitt, Schritt-Route, Banner, Freigeben mit Token
und Recht).
