# 02 – Sicherheit: Anmeldung, Rechte, Verschlüsselung von Dateien

Vorlage für Anmeldung, 2FA, Sessions, CSRF und Header ist Vereinsbelege
(`docs/spec/01-sicherheit.md` dort) – Mechanik und Klassen werden
übernommen. **Den Tresor aus Vereinsbelege gibt es hier nicht** (E-11):
die Datenbank speichert Mitgliederdaten im Klartext; verschlüsselt werden
nur Dateien.

## 1. Bedrohungsmodell

| Angreifer hat … | Folge | Gegenmaßnahme |
|---|---|---|
| keinen Zugang, ruft die Seite auf | nichts lesbar | Anmeldung mit zweitem Faktor, Rate-Limit, generische Fehlermeldungen |
| gestohlenes Passwort eines Benutzers | Login verhindert | zweiter Faktor, Mail bei neuem Gerät |
| eine heruntergeladene Backup-Datei | nicht lesbar | Backups mit dem Server-Schlüssel verschlüsselt (§2) |
| Zugriff auf `shared/var/` per Web (Fehlkonfiguration) | Dateien nicht lesbar | `.htaccess`-Sperren (04) + Dateien verschlüsselt |
| Datenbank-Zugang (DB-Dump, Datenbank-Verwaltung des Hosters) | **Mitgliederdaten lesbar** | bewusst in Kauf genommen (E-11); starke DB- und Hoster-Passwörter, DB nur über den Hoster erreichbar |
| FTP-Zugang | **alles lesbar** (über `config.php` auch DB und Schlüssel) | starkes FTP-Passwort, FTP nur bei Bedarf; Release-Signatur und Integritätscheck gegen veränderten Code |
| Fremder findet die Installation vor dem Verein | Übernahme verhindert | Installations-Token (04 §2) |

Datenschutz (für die Datenschutzerklärung): Schutz durch Zugangskontrolle
mit zweitem Faktor, HTTPS, verschlüsselte Dateien und Backups; keine
Verschlüsselung einzelner Datenbankfelder.

## 2. Server-Schlüssel und Dateiverschlüsselung

- **Server-Schlüssel**: 32 Byte, bei der Installation erzeugt, liegt nur
  in `shared/config.php` (nie in der DB, nie im DB-Dump).
- Verschlüsselt werden damit:
  - **Dateien** (Blobs): Nachweis-Scans und **Lastschriftdateien** (als
    Archiv jedes Einzugs, 08 §3); je Datei ein zufälliger
    Dateischlüssel, `secretstream_xchacha20poly1305` in Blöcken à 64 KiB,
    der Dateischlüssel mit `secretbox` unter dem Server-Schlüssel.
    Speicherformat wie Vereinsbelege (Versionsbyte, `TAG_FINAL`).
  - **Backups**: das Backup-ZIP wird als Ganzes so verschlüsselt (04 §6).
  - **Geheimnisse**: TOTP-Geheimnisse und das SMTP-Passwort (`secretbox`).
- **Exporte** (xlsx/csv) entstehen beim Download und werden nicht
  gespeichert.
- Folge: Ohne `config.php` lassen sich Backups und Dateien nicht mehr
  lesen. `config.php` wird deshalb **getrennt gesichert** (Ausdruck des
  Schlüssels bzw. Kopie beim zweiten Vorstandsmitglied); der Installer
  zeigt den Schlüssel einmal zum Ausdrucken an (04 §2).
- **Schlüsselwechsel** (#80, nach einer möglichen Kompromittierung von
  `config.php` oder des Ausdrucks): Einstellungen → System, Recht
  `admin.system`, nur nach erneuter Passwort-Eingabe (Rate-Limit je Konto).
  Neu verpackt werden nur die kleinen Werte unter dem Schlüssel
  (`file_blob.key_enc`, `mfa_totp.secret_enc`, SMTP-Passwort), nie die
  Dateien selbst. Schrittkette (01 §4, `App\Service\Crypto\Schluesselwechsel`),
  `config.php` ist die einzige Quelle der Wahrheit:
  1. Start: neuer Schlüssel per `random_bytes` wird `server_key`, der alte
     wandert nach `server_key_alt` – ein atomares Neuschreiben von
     `config.php` (temporäre Datei, `fsync`, 0600, `rename`;
     `ConfigWriter::schluesselSchreiben()`). Ab dann verschlüsseln alle
     Anfragen unter dem neuen Schlüssel und entschlüsseln mit beiden
     (`ServerCrypto`, `ServerKeyGeheimnisSchutz`).
  2. Je Schritt eine Portion Werte: mit dem alten Schlüssel öffnen, mit dem
     neuen verschlüsseln, per Compare-and-swap schreiben. Werte, die der
     neue Schlüssel schon öffnet, bleiben unberührt – jeder Schritt ist
     wiederholbar.
  3. Eine Anfrage, die `config.php` vor dem Start gelesen hat (oder ein
     Opcode-Cache), kann noch unter dem alten Schlüssel schreiben. Deshalb
     wartet die Kette 120 s nach dem Start und läuft dann ein zweites Mal
     über alles; erst ein Durchgang, der nach dieser Frist begann, beendet
     sie.
  4. Abschluss: Backup-Codes/Geräte, die in der Wartezeit noch unter dem
     alten Schlüssel entstanden sein können, werden gelöscht, dann
     `config.php` ohne `server_key_alt`. Audit-Einträge bei Start und
     Abschluss. Der neue Schlüssel steht in der Antwort des letzten
     Schritts und lässt sich danach mit Passwort erneut anzeigen, bis ein
     Admin bestätigt, dass er ausgedruckt ist (die letzte Antwort kann bei
     einem anderen Admin landen oder verloren gehen).

  Bricht die Kette irgendwo ab, ist alles mit dem lesbar, was in
  `config.php` steht; das nächste Öffnen der Einstellungen setzt sie fort.
  Die Schlüssel stehen nie in der Statusdatei
  (`shared/var/schluesselwechsel.json`: Fingerabdrücke, Positionen,
  Zähler). Werte, die nur als HMAC unter dem Schlüssel liegen
  (Backup-Codes, gemerkte Geräte, offene Mail-Codes), lassen sich nicht
  neu verpacken und werden beim Start gelöscht; Benutzer erzeugen neue
  Backup-Codes. Während des Wechsels startet kein Backup (ein Dump wäre
  halb alt, halb neu verschlüsselt): `config.php` wird unter der Sperre
  der Backup-Kette geschrieben (`BackupErstellung::ausschliesslich()`),
  und deren Start liest `config.php` selbst; der Abschluss wartet auf ein
  laufendes Backup. Bestehende Backups bleiben unter dem alten Schlüssel –
  wer ihn hat, kann sie weiterhin öffnen. Nach dem Wechsel ein neues
  Backup erstellen und den alten Ausdruck erst danach vernichten. Die IP-Hashes im Audit-Log
  (§8) entstehen ab dann unter dem neuen Schlüssel.

## 3. Anmeldung

Übernommen aus Vereinsbelege, ohne die Tresor-Teile:

- Login mit **E-Mail + Passwort**; `password_hash()` mit
  `PASSWORD_ARGON2ID`. Passwortregel: min. 12 Zeichen, Abgleich gegen die
  mitgelieferte Liste häufiger Passwörter, keine Zusammensetzungsregeln.
- **Zweiter Faktor**, Pflicht für alle Konten. Bei der Einrichtung
  wählt jeder Benutzer:
  - **E-Mail-Code** (Standard, Vorauswahl): bei jeder Anmeldung kommt
    eine Mail mit einer 6-stelligen Nummer (10 min gültig, max. 5
    Versuche). Keine App nötig.
  - **Authenticator-App** (TOTP, optional): jeder Zeitschritt nur einmal.
  Dazu 10 Backup-Codes zum Ausdrucken und „Dieses Gerät 30 Tage merken".
  Erzwungene Einrichtung beim ersten Login; Ändern nur nach erneuter
  Bestätigung. Ist die Mail noch nicht eingerichtet (Installer-Schritt
  übersprungen), bietet die Einrichtung nur die App an und sagt warum.
  Umsetzung (#8, `App\Service\Account\ZweiterFaktor`, Migration 025):
  - Nach richtigem Passwort wartet die Anmeldung 10 min in der Session
    (`Session::merkeOffeneAnmeldung()`) auf `/anmelden/bestaetigen`; bei
    E-Mail-Code geht die Mail genau beim Übergang raus, „Code erneut
    senden" schickt einen neuen (höchstens 5 je 15 min). Ein Feld nimmt
    App-, Mail- und Backup-Code. Konten ohne zweiten Faktor kommen
    sofort hinein, `LoginGuard` lässt sie aber nur auf
    `/app/konto/zweiter-faktor` (JSON: 403).
  - Codes zählen vor der Prüfung je IP (20) und je Konto (8) im
    15-min-Fenster; der Mail-Code hat zusätzlich seine 5 Versuche in der
    eigenen Zeile. App-, Mail- und Backup-Codes verbraucht nur die Anfrage,
    deren Statement die Zeile ändert.
  - Gespeichert werden nur HMACs (Codes, Geräte-Token) und das
    App-Geheimnis als Chiffrat des Server-Schlüssels; ein neues Geheimnis
    liegt bis zum ersten richtigen Code nur verschlüsselt in der Session.
    Der Wechsel der Methode löscht das Geheimnis der anderen.
  - „Gerät merken" setzt das Cookie `__Host-geraet` (30 Tage, httponly);
    nicht nach einem Backup-Code. „Alle Geräte vergessen" im Benutzermenü.
  - Ändern (Methode, neue App, neue Backup-Codes) erst nach Passwort +
    aktuellem Code, gültig 10 min und einmal; die erste Einrichtung fragt
    nicht nach. Backup-Codes erscheinen nur einmal, als Antwort auf den
    POST, der sie erzeugt.
  - Kein Ausweg per Mail für App-Konten (anders als Vereinsbelege): wer
    App und Backup-Codes verliert, ist ausgesperrt, bis ein Admin den
    Faktor zurücksetzen kann (fehlt noch, Folge-Issue).
- **Brute-Force-Schutz**: Rate-Limit je IP (20) und je Konto (10) im
  15-min-Fenster, atomar vor der Prüfung gezählt, ein Erfolg setzt beide
  Zähler zurück (`App\Service\RateLimiter`); wortgleiche Fehlermeldungen
  für unbekannte Adresse, falsches Passwort, gesperrtes Konto und offene
  Einladung, Schein-`password_verify()` bei unbekanntem Konto. Adressen
  werden getrimmt und klein geschrieben gespeichert und gesucht. Der
  Kontozähler hängt an der Konto-ID (`RateLimiter::konto()`), nicht an der
  getippten Schreibweise – die Datenbank findet ein Konto auch mit Akzenten;
  IPv6-Adressen zählen je /64 (`RateLimiter::ip()`).
- Mails mit Links oder Codes stehen nur bis zum Versand im Klartext in
  `mail_queue`; danach und nach dem endgültigen Fehlschlag wird der Text
  geleert.
- **Passwort-Reset**: Token 32 Byte, nur SHA-256 gespeichert, 30 min,
  einmalig, ein neuer Link ersetzt den alten; beendet alle Sessions.
  Anfragen zählen je IP (10) und je Konto bzw. Adresse (3) im Fenster; die Antwort
  ist für jede Adresse dieselbe. Ohne Tresor verliert das Konto dabei
  nichts.
- **Eigenes Passwort ändern** (Benutzermenü): nur mit dem aktuellen
  Passwort (Fehlversuche zählen wie bei der Anmeldung); beendet alle
  anderen Sessions, die eigene lebt mit neuer ID weiter.
- **Sicherheitsmails**: neues Gerät, Passwort geändert/zurückgesetzt, 2FA
  geändert.

## 4. Session

- Session-Cookie über HTTPS `__Host-sid` (`httponly`, `secure`,
  `samesite=Lax`); im Docker-Dev über HTTP ohne Präfix. `secure` aus dem
  Request-Schema abgeleitet. ID-Regeneration bei Login und Rechtewechsel,
  `cache_limiter=nocache`.
- Leerlauf 30 min (Barrierefrei-Modus 60 min mit Warnung, 06 §4), absolut
  12 h. Hintergrund-Anfragen (`X-Hintergrund: 1`) verlängern nicht.
- Jeder Request prüft das Konto erneut (`LoginGuard`, Konten aus
  `App\Service\Account\Benutzerkonten`): Sperre, Löschen, Rollenwechsel
  und `session_epoch` wirken sofort; ein Rollenwechsel erneuert die
  Session-ID beim nächsten Request.

## 5. Benutzer

- Der Installer legt den ersten Admin an
  (`BenutzerVerwaltung::ersterAdmin()`).
- Weitere Benutzer per **Einladung** (Mail-Link, 72 h, einmalig, nur Hash
  gespeichert, erneut sendbar); das Konto setzt Passwort und zweiten
  Faktor selbst. Sperren (beendet alle Sessions, verwirft offene Links),
  Sperre aufheben, Löschen, Rolle ändern – im Einstellungs-Abschnitt
  „Benutzer“ (`admin.benutzer`).
- Zwei Regeln halten die Installation erreichbar: das eigene Konto sperrt,
  löscht oder degradiert man nicht selbst, und der letzte aktive Admin
  bleibt aktiver Admin.
- Benutzer ≠ Mitglied; Mitglieder bekommen kein Konto (E-F2).

## 6. Rollen und Rechte

- Rechte sind ein PHP-Enum `App\Domain\Permission`. **Zwei feste Rollen**
  (kein Rollen-Editor):
  - **Admin**: alle Rechte.
  - **Vorstand**: Mitglieder suchen, ansehen, anlegen, bearbeiten (auch
    IBAN, Abteilung, Beitragsart), Listen und Exporte. **Nicht**:
    Jahresbeiträge, Einzug, Bezahlt-Status, Lastschriftdatei,
    Einstellungen, Benutzer, System.
- Rolle → Rechte steht im Code (ein `match` in `App\Domain\Rolle`).
  Keine Scopes – alle sehen alle Mitglieder. Geprüft serverseitig je Route
  (`App\Http\Zugriff`).
- Das Enum ist seit #1 vollständig (05 §6); spätere Issues nutzen diese
  Rechte und erweitern es nicht:

  | Recht | Wofür | Admin | Vorstand |
  |---|---|:-:|:-:|
  | `mitglieder.sehen` | Mitglieder suchen, ansehen, Verlauf ansehen | ✓ | ✓ |
  | `mitglieder.bearbeiten` | anlegen, bearbeiten (auch IBAN, Abteilung, Beitragsart), Version zurückholen, Familien, Nachweis/Ehrung vermerken | ✓ | ✓ |
  | `mitglieder.loeschen` | Mitglied löschen, Nachweis löschen | ✓ | – |
  | `listen.sehen` | Listen ansehen und exportieren | ✓ | ✓ |
  | `beitraege.verwalten` | alles aus 08: Jahresbeiträge, Bezahlt-Status, Reiter „Beiträge", Einzug, Lastschriftdatei, Liste „Selbst kassieren", Beitragsspalten im Gesamtexport | ✓ | – |
  | `admin.einstellungen` | Einstellungen-Seite: Vereinsdaten, Abteilungen, Beitragsarten, Funktionen, Geburtstage, Ehrungsstufen, Mail, Rechtstexte | ✓ | – |
  | `admin.benutzer` | Benutzer einladen, sperren, löschen, Rolle ändern | ✓ | – |
  | `admin.import` | Erst- und Abgleich-Import (10) | ✓ | – |
  | `admin.system` | Update, Backup/Restore, Systemcheck, Integritätscheck, Wartung | ✓ | – |
  | `admin.audit` | Audit-Log ansehen | ✓ | – |

  Jede Route unter `/admin` verlangt ein `admin.*`-Recht, jede unter
  `/app` eine Anmeldung; öffentlich sind nur `/`, `/anmelden…`,
  `/abmelden`, `/impressum`, `/datenschutz` (E-F3,
  `tests/Http/RoutenZugriffTest.php`).

## 7. Web-Sicherheit

- **CSRF** zentral für jede POST-Route (`App\Http\CsrfSchutz`).
- **Header** (`.htaccess`, Startpunkt = Vereinsbelege):
  `Content-Security-Policy: default-src 'self'; script-src 'self';
  style-src 'self'; img-src 'self' data:; font-src 'self';
  connect-src 'self'; object-src 'none'; frame-ancestors 'none';
  base-uri 'self'; form-action 'self'`, HSTS, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy:
  same-origin`, `Permissions-Policy` restriktiv.
- **Karte** (#91): Leaflet ist vendored (`public/js/vendor/leaflet/`),
  Kacheln kommen über den eigenen Server (01 §8) – die CSP bleibt
  unverändert. Leaflet setzt Stile nur per CSSOM (`element.style`), das
  `style-src 'self'` erlaubt. Kacheln sind keine Mitgliederdaten und
  dürfen deshalb einen Tag im Browser bleiben (`private, max-age=86400`);
  die Seite selbst bleibt `no-store`.
- `.user.ini`: `zend.exception_ignore_args = On`,
  `zend.exception_string_param_max_len = 0`, `display_errors = Off`.
- Kein Inline-JS, keine `on*`-Attribute, kein `eval`; htmx über
  `<meta name="htmx-config">`, `includeIndicatorStyles=false`, kein
  `hx-on`. `CspComplianceTest` prüft alle Templates.
- **Daten im Browser**: Mitgliederdaten nie in localStorage,
  sessionStorage, IndexedDB oder einem Service-Worker-Cache;
  `Cache-Control: no-store` auf allen Seiten mit Mitgliederdaten.
- Ausgaben escapen (`e()`), SQL nur mit Prepared Statements.
- **Logs**: keine Mitgliederdaten in Logs, Fehlermeldungen, Dateinamen
  oder URLs (Mitgliedsnummer bzw. ID genügt). Passwörter nie loggen.

## 8. Audit-Log

Append-only `audit_log` mit Hash-Kette (`prev_hash`, `hash`), IP nur als
HMAC unter dem Server-Schlüssel. Protokolliert: Anmeldungen (auch
fehlgeschlagene, mit Schritt Passwort bzw. zweiter Faktor), Abmeldung,
Passwort geändert/zurückgesetzt, zweiter Faktor geändert, Benutzer
eingeladen/geändert/gesperrt/gelöscht und Rollenänderungen (betroffene
Benutzer nur mit ID), Backup erstellt und heruntergeladen, Update, Restore,
Export, Import, Einzug erzeugt/bestätigt/verworfen, Lastschriftdatei
heruntergeladen, Version zurückgeholt, Mitglied gelöscht,
Mitgliederbestand geleert (Probelauf des Imports, 10 §1), Abgleich
durchgeführt (10 §4), Server-Schlüssel: Wechsel begonnen und
abgeschlossen (§2). Prüfliste und letzter Sicherheits-Durchlauf:
`docs/security-checkliste.md` (#37).
Ansicht im Admin (Seite `/admin/audit`, verlinkt aus den Einstellungen) mit
Kettenprüfung bei jedem Aufruf. Schnittstelle für alle Bereiche:
`$dienste->audit()->protokollieren(AuditAktion::…, $userId, …)`. Nicht
erkannt wird das Abschneiden der letzten Einträge (dafür bräuchte es eine
Kopie des Kopf-Hashs außerhalb der Datenbank). Änderungen an Mitgliedern selbst stehen in deren
Versionen (07 §7).

## 9. Integritätscheck Code

`checksums.txt` des Releases enthält je Datei eine Zeile; der Admin
vergleicht `current/` dagegen (signierte Liste, 04 §4).

## Pflicht-Tests

Blob-Rundlauf und Manipulationserkennung (abgeschnitten, vertauscht,
falscher Schlüssel); Backup verschlüsselt und nur mit dem Schlüssel
lesbar; Login (alle Fehlerfälle wortgleich, Rate-Limit atomar), 2FA
(TOTP-Einmalverwendung, E-Mail-Code-Limit, Backup-Codes, Gerät merken),
Reset (Token einmalig, Sessions beendet), Session-Timeouts inkl.
Barrierefrei-Modus, `session_epoch`; CSRF auf jeder POST-Route,
CSP-Compliance, Routen-Zugriffsmatrix inkl. Vorstand, Audit-Kette;
Schlüsselwechsel (`SchluesselwechselTest`: danach alles mit dem neuen und
nichts mit dem alten Schlüssel lesbar, Abbruch nach jedem Schritt und
Verlust der Statusdatei, Absturz zwischen `config.php` und Statusdatei,
ein während der Kette unter dem alten Schlüssel geschriebener Wert,
kein Start während eines Backups).
