# CLAUDE.md – Mitgliederverwaltung

Mitgliederverwaltung für einen Sportverein. Dieses Dokument ist die
**verbindliche Architektur-Referenz**. Bei Widerspruch zwischen Code und
Dokument gilt das Dokument – oder es wird im selben PR bewusst geändert.
Änderungswünsche laufen als GitHub Issues, nicht in dieser Datei.

**Stand:** Der technische Rahmen steht fest. Die Fachlichkeit wird in
`docs/fachlichkeit.md` gesammelt und geklärt; erst geklärte Bereiche
werden zu Specs unter `docs/spec/`. Es wird **nichts Fachliches erfunden**.

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
| `docs/spec/02-sicherheit.md` | Bedrohungsmodell, Tresor-Verschlüsselung, Anmeldung + 2FA, Rollen/Rechte-Mechanik, CSRF/CSP, Audit-Log |
| `docs/spec/03-datenhaltung.md` | DB-Konventionen, Verschlüsselungs-Klassifizierung, technische Tabellen, Blobs, Migrationen |
| `docs/spec/04-installation-und-update.md` | setup.php, Installer, Self-Updater (Kanäle stable/beta), Release, Wartungsmodus, Backup/Restore, Systemcheck |
| `docs/spec/05-entwicklung.md` | Docker, Tests, CI, Übernahme aus den Schwesterprojekten, Fremdbibliotheken |
| `docs/spec/06-oberflaeche.md` | UI-Technik, Seitenaufbau (wenige Seiten), Muster, Barrierefrei-Modus |

Schwesterprojekte (gleicher Hoster, gleiche Bauweise):
`MirkoSc/vereinsbelege` (Hauptvorlage: Login, 2FA, Tresor, Installer,
Updater) und `MirkoSc/vereinskalender` (Ursprung von Http-Kern, Updater,
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
- **Adminseite** (`/admin/...`): Benutzer (zwei feste Rollen Admin/
  Vorstand), Tresor-Freigaben, Mail, Rechtstexte, Einstellungen, Backup,
  Update, Systemcheck, Audit-Log.

**Oberfläche (06-oberflaeche.md):** so wenige Seiten wie möglich –
Mitglieder, Listen, Beiträge, Einstellungen, Benutzermenü. Läuft auf Handy
und PC. Barrierearmut als solide Grundlage, kein Vollstandard; dazu ein
**Barrierefrei-Modus** je Benutzer (PC: große Schrift, hoher Kontrast,
große Flächen, nur Tastatur möglich) als CSS-Klasse, kein zweites Layout.
Ein Layout, ein handgeschriebenes Designsystem (`public/css/app.css`,
hell/dunkel). Jede UI-Arbeit lädt den Skill **`frontend-design`**
(`.claude/skills/frontend-design/`, unverändert von Anthropic,
Apache-2.0) und hält sich daran – Einfachheit geht dabei vor.

## 4. Sicherheits-Invarianten (Details: 02-sicherheit.md)

- **Tresor-Modell wie Vereinsbelege.** Zwei Schlüsselebenen:
  1. *Server-Schlüssel* (`shared/config.php`, nie in DB oder DB-Backup):
     nur Betriebsdaten, die ohne angemeldeten Nutzer gebraucht werden
     (Benutzer-Mailadressen, Mail-Queue, TOTP-Geheimnisse, SMTP-Passwort).
  2. *Tresor* (asymmetrisch, libsodium): **alle fachlichen Daten** –
     insbesondere alle personenbezogenen Daten der Mitglieder. Schreiben
     geht ohne Geheimnis (Sealed Box an den Tresor-Public-Key), **Lesen nur
     in einer angemeldeten Session** mit entsperrtem Tresor.
- Der Tresor-Private-Key liegt **nie** im Klartext auf Platte oder in der
  DB: gewrappt je Benutzer (Argon2id aus dem Passwort) und als
  Wiederherstellungsschlüssel beim Verein (Papier). In der Session nur
  verschlüsselt mit einem Schlüssel, der ausschließlich im Cookie liegt.
- Folge: **Alles, was entschlüsseln muss, läuft in einer Nutzer-Session**
  (browsergetriebene Schrittkette), nie im Cron. Der Cron macht nur
  Mail-Versand und Aufräumen.
- Anmeldung mit E-Mail + Passwort + **zweitem Faktor** (TOTP, E-Mail-Code,
  Backup-Codes), Rate-Limits, keine User-Enumeration.
- Keine fachlichen Klartextdaten in Logs, Mails, Fehlermeldungen,
  Dateinamen oder URLs. Passwörter nie loggen;
  `zend.exception_ignore_args=On`.
- CSRF-Token zentral auf jeder POST-Route (`App\Http\CsrfSchutz`).
  Session-Cookie `httponly`, `samesite=Lax`, über HTTPS `__Host-sid`.
- CSP `script-src 'self'` **ohne** `unsafe-inline`/`unsafe-eval` (kein
  Inline-JS, keine `on*`-Attribute, htmx ohne `hx-on`), keine externen
  Hosts. JS-Libs vendored unter `public/js/vendor/` mit Version, Lizenz
  und Prüfsumme in dessen `README.md`. htmx wird über
  `<meta name="htmx-config">` konfiguriert.
- Entschlüsselte Daten werden im Browser **nie dauerhaft gespeichert**
  (kein localStorage/IndexedDB/Service-Worker-Cache), Antworten mit
  Fachdaten tragen `Cache-Control: no-store`.
- Rechte **serverseitig pro Aktion** (`Permission`-Enum). Jede Route
  deklariert ihren Zugriff (`App\Http\Zugriff`, Pflichtargument von
  `Router::get/post`). Zwei feste Rollen, keine Scopes (02 §6).

## 5. Datenhaltung (Details: 03-datenhaltung.md)

- Fachliche Tabellen: je Zeile ein versiegelter Datenschlüssel
  (`dek_sealed`) und verschlüsselte Spalten (`*_enc`, AEAD, AAD =
  Tabelle|ID|Spalte). Strukturfelder (IDs, FKs, Status, technische
  Zeitstempel) bleiben Klartext, damit SQL filtern/sortieren kann. **Welche**
  Felder Klartext bleiben dürfen, wird je fachlicher Tabelle bewusst
  entschieden und in der Spec begründet – Default ist verschlüsselt.
- Suche/Duplikaterkennung über **Blind Indexes** (HMAC, Schlüssel aus dem
  Tresor abgeleitet → nur in der Session berechenbar).
- Dateien sind **Blobs**, verschlüsselt per libsodium secretstream,
  Backend `fs` (Default) oder `db`.
- Geldbeträge immer Integer-Cent, nie `float`.
- **Kein Event Sourcing**; stattdessen append-only **Audit-Log** mit
  Hash-Kette.
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

- **Ein Issue pro Session** (`/issue <nr>`). Branch `issue-<nr>-<kurz>`,
  PR mit `Closes #<nr>`. Nie direkt auf `main`.
- Zu Beginn: Issue lesen, nur die verlinkten Spec-Abschnitte lesen, kurzen
  Plan nennen. Bei fachlicher Unklarheit **fragen statt annehmen**.
- Vor dem PR: Tests grün, `docker compose up` läuft, betroffene Seiten im
  Browser geprüft (bzw. Prüfliste im PR).
- Keine „Nebenbei-Refactorings" außerhalb des Issues – dafür neues Issue.
