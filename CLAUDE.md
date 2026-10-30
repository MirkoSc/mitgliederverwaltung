# CLAUDE.md – Mitgliederverwaltung

Mitgliederverwaltung für einen Sportverein. Dieses Dokument ist die
**verbindliche Architektur-Referenz**. Bei Widerspruch zwischen Code und
Dokument gilt das Dokument – oder es wird im selben PR bewusst geändert.
Änderungswünsche laufen als GitHub Issues, nicht in dieser Datei.

**Stand:** Specs 01–11 umgesetzt (Issues #1–#37); die Anwendung ist seit
29.10.2026 mit echten Mitgliederdaten installiert, das Repository ist
öffentlich. `docs/fachlichkeit.md` ist das Protokoll der Klärung und enthält
die wenigen offenen Punkte. Es wird **nichts Fachliches erfunden** –
Unklares wird gefragt.

**Leitlinien (gelten für jede Session):**
- **Einfach statt perfekt.** Die Anwendung wird nicht verkauft. Sie soll
  deutlich schlanker sein als Vereinsbelege: wenige Seiten, wenige
  Einstellungen, keine Sonderfälle oder Konfigurierbarkeit auf Vorrat.
  Übernommener Code aus Vereinsbelege ist willkommen, Ausbau darüber hinaus
  nur auf ausdrücklichen Wunsch.
- **Hinweise statt Sperren.** Fachliche Regeln (Alter, Nachweise,
  Familiengröße, Haushalt …) warnen, blockieren aber nie.

Diese Datei ist bewusst kurz gehalten (sie wird in JEDER Session geladen).
Details stehen in `docs/spec/*.md` – **lies nur die Spec-Dateien, die das
aktuelle Issue verlinkt**, nicht alle.

| Spec | Inhalt |
|---|---|
| `docs/spec/01-plattform.md` | Hosting-Constraints, Verzeichnislayout, Request-Modell, Schrittketten, Cron, Mail, Logging |
| `docs/spec/02-sicherheit.md` | Bedrohungsmodell, Anmeldung + 2FA, drei feste Rollen, Datei-/Backup-Verschlüsselung, CSRF/CSP, Audit-Log |
| `docs/spec/03-datenhaltung.md` | DB-Konventionen, technische Tabellen, Blobs, Migrationen |
| `docs/spec/04-installation-und-update.md` | setup.php, Installer, Self-Updater (Kanäle stable/beta), Release, Wartungsmodus, Backup/Restore, Systemcheck |
| `docs/spec/05-entwicklung.md` | Docker, Tests, CI, Übernahme aus den Schwesterprojekten, Fremdbibliotheken |
| `docs/spec/06-oberflaeche.md` | UI-Technik, Seitenaufbau (wenige Seiten), Muster, Barrierefrei-Modus |
| `docs/spec/07-mitglieder.md` | Mitglied, Abteilungen, Familien, Funktionen, Nachweise, Hinweise, Versionen, Datenmodell |
| `docs/spec/08-beitraege.md` | Beitragsarten, Jahresbeiträge, Einzug, Lastschriftdatei pain.008 (nur Admin) |
| `docs/spec/09-listen-und-exporte.md` | Geburtstage, Ehrungen, Funktionen, Selbst kassieren, Auffälligkeiten, BLSV, Gesamtexport, xlsx/csv |
| `docs/spec/10-import.md` | Erstimport und späterer Abgleich (Merge-Ansicht), Importformat |
| `docs/spec/11-vereinsdaten.md` | Vereinsdaten, Bankverbindung, Gläubiger-ID, Verbandsnummern, Logo |

Schwesterprojekte (gleicher Hoster, gleiche Bauweise):
`MirkoSc/vereinsbelege` (Hauptvorlage: Login, 2FA, Installer,
Updater, Backup – **ohne** dessen Tresor) und `MirkoSc/vereinskalender` (Ursprung von Http-Kern, Updater,
Shim). Was dort gelöst ist, wird **übernommen und angepasst, nicht neu
erfunden** (05 §3).

## 1. Harte Umgebungs-Constraints (niemals verletzen)

- Shared Hosting bei einem deutschen Shared-Hosting-Anbieter: **kein SSH,
  kein Git, kein Composer** auf dem Server. Der Anbieter wird in Code,
  Doku und Commits **nicht namentlich genannt** („der Hoster").
- **Keine Prozess-Aufrufe aus PHP** (`exec()`, `shell_exec()`,
  `proc_open()`, `passthru()`, `system()`, `popen()`, Backticks) und damit
  keine Kommandozeilen-Tools. Bewusste Selbstbeschränkung – der Hoster
  sperrt sie nicht (`disable_functions` leer, `docs/hosting-befunde.md`).
  Die lokale Docker-Umgebung sperrt sie, `NoShellExecutionTest` prüft den
  Quellcode.
- Deployment ausschließlich über **Release-ZIPs (GitHub Releases) +
  `setup.php` + Self-Updater** mit Kanälen `stable`/`beta` (04).
- **PHP 8.5**, fehler- und deprecation-frei; Enums, readonly, Promotion,
  `match` aktiv nutzen. MySQL/MariaDB über PDO, Prepared Statements,
  `ERRMODE_EXCEPTION`.
- **Kein einzelner Request darf lange laufen.** Lange Vorgänge (Import,
  Export, Umschlüsseln, Update, Backup) sind idempotente Schrittketten aus
  kurzen Requests mit persistentem Status (Job-Tabelle bzw. Statusdatei).
  Ausnahme nur für gestreamte Downloads (Zeitlimit je Stück neu gesetzt).
- Kein Build-Step auf dem Server; `vendor/` liegt im Release-ZIP. Nur reine
  PHP-Bibliotheken ohne native Abhängigkeiten.
- Server-gerendertes PHP + htmx + wenig Vanilla JS + handgeschriebenes
  CSS (E-05), kein SPA-Framework, kein Build – weder auf dem Server noch
  für CSS/JS. Keine externen Hosts/CDNs.
- **Eine Installation = ein Verein.** Kein Mandanten-Konzept.
- Öffentliches Repo: **niemals Secrets, Zugangsdaten oder echte
  Mitgliederdaten committen** – Testdaten nur erfunden/anonymisiert.

## 2. Verzeichnislayout

Identisch zu Vereinsbelege/Vereinskalender (Details 01 §2):

```
/web/            DocumentRoot: index.php-Shim, .htaccess (Header, CSP), .user.ini
/current/        aktives Release (per rename() umgeschaltet)
/releases/vX.Y.Z/
/shared/         überlebt Updates: config.php, maintenance.flag,
                 update_state.json, var/{blobs,backups,log,tmp}/
```

Repo spiegelt ein Release: `app/src/` (Namespace `App\`, Unterordner
`Http`, `Config`, `Database`, `Domain`, `Repository`, `Service/<Bereich>`,
`Installer`, `Admin`, `App`, `PublicPages`, `Support`, `View`),
`app/views/` (Templates, ein Layout + `partials/`), `app/data/`,
`public/` (Einstiegspunkt, CSS, JS, vendored JS-Libs unter
`public/js/vendor/`), `bin/`, `migrations/`, `tests/`, `docker/`,
`setup.php`. `tools/` für Hilfsskripte, die **nicht**
Teil eines Releases sind.

## 3. Bereiche der Anwendung (technisch)

- **Öffentlich**: Anmeldung, „Passwort vergessen", `/impressum`,
  `/datenschutz` – sonst nichts (E-F3).
- **Anwenderseite** (`/app/...`): alles Fachliche, je nach Recht.
- **Adminseite** (`/admin/...`): Benutzer (drei feste Rollen Admin/
  Vorstand/Nur lesen), Mail, Rechtstexte, Einstellungen, Backup,
  Update, Systemcheck, Audit-Log. Nur der Admin hat einen Adminbereich.

**Oberfläche (06-oberflaeche.md):** so wenige Seiten wie möglich –
Mitglieder, Listen, Karte, Beiträge, Einstellungen, Benutzermenü. Läuft auf Handy
und PC. Barrierearmut als solide Grundlage, kein Vollstandard; dazu ein
**Barrierefrei-Modus** je Benutzer (PC: große Schrift, hoher Kontrast,
große Flächen, nur Tastatur möglich) als CSS-Klasse, kein zweites Layout.
Ein Layout, ein handgeschriebenes Designsystem (`public/css/app.css`,
hell/dunkel); alle Bausteine zeigt die Musterseite `/admin/designsystem`
– neue Seiten kopieren ihr Markup von dort. Jede UI-Arbeit lädt den Skill **`frontend-design`**
(`.claude/skills/frontend-design/`, unverändert von Anthropic,
Apache-2.0) und hält sich daran – Einfachheit geht dabei vor.

## 4. Sicherheits-Invarianten (Details: 02-sicherheit.md)

- **Kein Tresor** (E-11): Mitgliederdaten liegen im Klartext in der
  Datenbank. Schutz durch Zugangskontrolle: Anmeldung mit E-Mail +
  Passwort + **zweitem Faktor** (Standard: Code per E-Mail; optional
  Authenticator-App; Backup-Codes),
  Rate-Limits, keine User-Enumeration.
- **Server-Schlüssel** (`shared/config.php`, nie in DB oder Backup)
  verschlüsselt: Dateien (Nachweis-Scans, archivierte
  Lastschriftdateien), Backups, TOTP-Geheimnisse, SMTP-Passwort. Exporte
  werden nie auf dem Webspace gespeichert, nur beim Download erzeugt.
- Keine Mitgliederdaten in Logs, Mails, Fehlermeldungen, Dateinamen oder
  URLs (Mitgliedsnummer/ID genügt). Passwörter nie loggen;
  `zend.exception_ignore_args=On`.
- CSRF-Token zentral auf jeder POST-Route (`App\Http\CsrfSchutz`).
  Session-Cookie `httponly`, `samesite=Lax`, über HTTPS `__Host-sid`.
- CSP `script-src 'self'` **ohne** `unsafe-inline`/`unsafe-eval` (kein
  Inline-JS, keine `on*`-Attribute, htmx ohne `hx-on`), keine externen
  Hosts. JS-Libs vendored unter `public/js/vendor/` mit Version, Lizenz
  und Prüfsumme in dessen `README.md`. htmx wird über
  `<meta name="htmx-config">` konfiguriert.
- Mitgliederdaten im Browser **nie dauerhaft speichern** (kein
  localStorage/IndexedDB/Service-Worker-Cache); `Cache-Control: no-store`.
- Rechte **serverseitig pro Aktion** (`Permission`-Enum). Jede Route
  deklariert ihren Zugriff (`App\Http\Zugriff`, Pflichtargument von
  `Router::get/post`). Drei feste Rollen Admin/Vorstand/Nur lesen (02 §6);
  Vorstand und Nur lesen haben keinen Adminbereich, Nur lesen ändert nichts.

## 5. Datenhaltung (Details: 03-datenhaltung.md)

- Normale Spalten im Klartext; Suchen, Filtern, Sortieren in SQL.
- Dateien sind **Blobs**, verschlüsselt per libsodium secretstream,
  Backend `fs` (Default) oder `db`.
- Geldbeträge immer Integer-Cent, nie `float`.
- **Kein Event Sourcing**; Änderungen an Mitgliedern als **Versionen**
  (07 §7), dazu ein append-only **Audit-Log** mit Hash-Kette.
- Zeitzone durchgängig `Europe/Berlin`.

## 6. Entwicklungs-Konventionen (Details: 05-entwicklung.md)

- Lokal: `docker compose up` (PHP 8.5 + MariaDB, produktionsnah:
  `disable_functions=exec,…`, `max_execution_time=30`, `wait_timeout=120`).
- Tests: PHPUnit (`failOnDeprecation`, `failOnWarning`), JS-Logik mit
  `node --test tests/js`. Pflicht-Tests stehen je Spec unter
  „Pflicht-Tests".
- SQL nur in Repositories, nur Prepared Statements, kein ORM.
- Jede Schema-Änderung = neue Migration `NNN_name.sql`; alte nie ändern;
  Migrationen eine Version rückwärtskompatibel.
- UI-Texte Deutsch; Code, Kommentare, Commits Englisch.
- Fremdbibliotheken vor Aufnahme prüfen: reines PHP, PHP-8.5-kompatibel,
  aktiv gepflegt, GPL-kompatibel. Ergebnis im PR notieren.
- Architektur- oder Datenmodell-Änderungen aktualisieren die Spec (und ggf.
  diese Datei) **im selben PR**.

## 7. Arbeitsweise mit Claude Code

- **Ein Issue pro Agent bzw. Sitzung.** Branch `issue-<nr>`, PR mit
  `Closes #<nr>`. Nie direkt auf `main`. Ablauf: `.claude/commands/issue.md`.
- Plan, Reihenfolge, Modelle: `docs/ARBEITSPLAN.md`. Viele Agenten
  arbeiten **parallel** – Regeln dafür in 05 §6 (Erweiterungspunkte,
  Migrationsbereiche, isolierte Tests). Daran halten, sonst blockieren sich
  die Jobs gegenseitig.
- **Vollautomatik** (Workflow `entwicklung`, `docs/VORGEHEN.md`): niemand
  antwortet auf Rückfragen. Bei Unklarheit die einfachste Lösung wählen,
  die zu Spec und Leitlinien passt, und sie im PR unter „Entscheidungen"
  begründen. Nur wenn es wirklich nicht weitergeht: abbrechen mit Grund
  (die Automatik setzt dann `braucht-hilfe`).
- In einer interaktiven Sitzung mit Mirko gilt dagegen: bei fachlicher
  Unklarheit **fragen statt annehmen**.
- **Skills** dürfen und sollen automatisch genutzt werden, wo sie helfen –
  insbesondere `frontend-design` bei jeder UI-Arbeit, `code-review` /
  `simplify` vor dem PR.
- **Betrieb:** Die Anwendung läuft mit echten Daten, das Repository ist
  öffentlich. Das Security-Review (#37) steht in
  `docs/security-checkliste.md`; jede neue Route oder Funktion hält §4 ein
  und ist Anlass, die passenden Abschnitte dieser Checkliste erneut
  durchzugehen. Jede Korrektur muss per Update auf die bestehende
  Installation kommen – ohne Neuinstallation und ohne Handarbeit auf dem
  Server.
- Vor dem PR: Tests grün, betroffene Seiten geprüft (bzw. Prüfliste im PR).
- Keine „Nebenbei-Refactorings" außerhalb des Issues – dafür neues Issue.
