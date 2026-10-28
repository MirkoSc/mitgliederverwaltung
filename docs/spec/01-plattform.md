# 01 – Plattform: Hosting, Layout, Request-Modell, Betrieb

## 1. Zielumgebung

Shared Hosting bei demselben Anbieter wie Vereinsbelege und
Vereinskalender. Die dort gemessenen Werte gelten hier unverändert
(`docs/hosting-befunde.md`, übernommen aus Vereinsbelege). Wichtig für die
Architektur:

| Eigenschaft | Wert | Folge |
|---|---|---|
| PHP | 8.5, `fpm-fcgi`, 64-bit | `php_value` in `.htaccess` erzeugt 500 → `.user.ini` |
| `max_execution_time` | 60 s (lokal 30 s) | Entwicklung ist strenger; Schritte < 30 s halten |
| `memory_limit` | 384 MiB | |
| `wait_timeout` (DB) | **120 s** | DB-Verbindung nie über einen langen Netzwerkaufruf offen halten; ggf. neu verbinden |
| `disable_functions` | leer | Prozessverbot ist Selbstbeschränkung (CLAUDE.md §1) |
| `zend.exception_ignore_args` | 0 | per `.user.ini` **und** `ini_set` im Bootstrap auf On |
| Cron | ab 1 min, per HTTP-Aufruf | Läufe können überlappen → Sperre + Drosselung |
| SMTP | Hoster-SMTP, Port 465 implizit TLS | eigener SMTP-Client, kein `mail()` für Sicherheitsmails |
| ext-sodium | libsodium 1.0.18, Argon2id MODERATE ≈ 0,3 s | Tresor-Modell trägt |
| Erweiterungen | gd, zip, curl, openssl, mbstring, intl, fileinfo, pdo_mysql, dom, … | |
| MariaDB | 10.6, utf8mb4, InnoDB, JSON, LONGBLOB | |
| `rename()` von Verzeichnissen | funktioniert | Release-Umschaltung per `rename()` |
| OPcache | aktiv | `opcache_reset()` nach dem Umschalten |
| `.htaccess` | wird ausgewertet | Sperren für `shared/`, `releases/`, `current/` |

Ein neuer Hoster/Tarif/PHP-Stand wird mit `tools/hosting-check.php` aus
Vereinsbelege erneut vermessen; Abweichungen werden Issues.

## 2. Verzeichnislayout auf dem Server

Die (Sub-)Domain zeigt im Kundenmenü auf `/web/`; alles daneben ist damit
nicht öffentlich. Zusätzlich sperren `.htaccess`-Dateien
(`Require all denied`, Apache-2.2-Fallback) `shared/`, `releases/` und –
über die Datei im Release-Wurzelverzeichnis – `current/`.

```
/web/                 DocumentRoot
   index.php          Shim: Wartungsmodus + „Release fehlt"-Prüfung,
                      HTTP→HTTPS-Umleitung, dann require ../current/public/index.php
   .htaccess          Rewrite auf index.php, Security-Header, CSP, HSTS
   .user.ini          zend.exception_ignore_args = On
/current/             aktives Release
/releases/vX.Y.Z/     app/, public/, vendor/, bin/, migrations/, docker/web/, VERSION, checksums.txt(.sig)
/releases/_prev/      vorheriges Release (Rollback)
/shared/
   config.php         DB-Zugang, Cron-Token, Server-Schlüssel
   maintenance.flag   gesetzt während Update/Wartung (JSON: Grund, Start)
   update_state.json  Stand der Update-Schrittkette
   var/blobs/         verschlüsselte Dateien (Backend „fs")
   var/backups/       Backups (fachliche Daten nur als Chiffrat)
   var/log/app.log    eigenes Log (eine Generation .1 ab 5 MB)
   var/tmp/           nur Chiffrat oder Upload-Chunks, wird aufgeräumt
   var/update/        Download-Zwischenablage des Updaters
```

Die drei Docroot-Dateien liegen im Repo unter `docker/web/`, reisen im
Release-ZIP mit, `setup.php` kopiert sie, der Updater hält sie aktuell
(04 §3).

## 3. Request-Modell

- Einstieg `public/index.php` → Bootstrap (Zeitzone `Europe/Berlin`,
  Error-Handler, `ini_set('zend.exception_ignore_args', '1')`) →
  `App\Http\Kernel` → `Router` → Controller.
- Ohne `shared/config.php` läuft die Anwendung im **Installationsmodus**
  (nur `/install*`).
- Routen werden zentral in `app/src/routes.php` registriert; jede mit
  `App\Http\Zugriff` (öffentlich / angemeldet / Recht X). Eine Testdatei
  `tests/Http/routen-zugriff.txt` hält die Matrix fest – eine neue Route
  ändert sie sichtbar im Diff.
- Antwortformate (HTML-Seiten, Fragmente, JSON-API) hängen von der
  UI-Technik ab (E-05).

## 4. Lange Vorgänge: Schrittketten

Kein Request darf lange laufen. Muster (übernommen aus Vereinsbelege 06 §4):

- **Job-Tabelle** `job` (Typ, Status, Fortschritt, Zustand als JSON bzw.
  bei fachlichem Inhalt verschlüsselt, Sperre mit Ablauf).
- Der **Browser treibt** die Kette: je Schritt ein
  kurzer POST, Antwort = neuer Fortschritt. Jeder Schritt ist idempotent
  und darf nach Abbruch wiederholt werden.
- Schritte, die entschlüsseln, laufen nur in einer Session mit entsperrtem
  Tresor. Hintergrund-Anfragen tragen `X-Hintergrund: 1` und verlängern die
  Leerlaufzeit der Session nicht (02 §4).
- Infrastruktur-Ketten ohne Tresor (Update, Restore) halten ihren Stand in
  einer Statusdatei unter `shared/`.

## 5. Cron

- Der Cron des Hoster-Kontrollpanels ruft `/cron?token=…` per HTTP auf
  (Token aus `config.php`, Vergleich mit `hash_equals`).
- Aufgaben: Mail-Queue versenden, Aufräumen (abgelaufene Tokens, Rate-Limit-
  Zeilen, `var/tmp/`, alte Backups), sonst nichts. **Nie entschlüsseln.**
- Eine Sperre (`flock` bzw. DB-Lock) verhindert überlappende Läufe;
  Aufräumarbeiten sind gedrosselt (nicht bei jedem Minutenlauf).

## 6. Mail

- Eigener SMTP-Client (`App\Service\Mail\SmtpTransport`, übernommen),
  Einstellungen im Admin bzw. im Installer-Schritt, Passwort mit dem
  Server-Schlüssel verschlüsselt.
- **Mail-Queue** (`mail_queue`, Inhalte mit Server-Schlüssel verschlüsselt),
  Versand im Cron. Sicherheitsmails (Reset, 2FA-Code, neues Gerät) gehen
  sofort raus.
- Link-Basis aus dem Setting `oeffentliche_url` (Schutz vor
  Host-Header-Injection).
- Mails enthalten keine fachlichen Klartextdaten, solange keine fachliche
  Spec das ausdrücklich erlaubt und begründet (z. B. weil der Empfänger das
  Mitglied selbst ist).

## 7. Logging und Fehler

- `App\Support\FileLogger` → `shared/var/log/app.log`, zusätzlich
  `error_log()`. Jeder Schreibvorgang best effort.
- Der Error-Handler loggt nur Exception-Klasse, Message, Methode/Route und
  Ort – nie den vollen Exception-String, nie Argumente, nie Request-Daten.
- Messages von Exceptions enthalten nie fachliche Werte.
- Fehlerseiten zeigen eine Referenz-ID, keine Details.

## Pflicht-Tests

`NoShellExecutionTest` (kein Prozessaufruf im Quellcode außer erlaubten
Ausnahmen – derzeit keine), Routen-Zugriffsmatrix, Cron-Token und
-Sperre, Logger schreibt keine Argumente, Bootstrap setzt Zeitzone und
`zend.exception_ignore_args`.
