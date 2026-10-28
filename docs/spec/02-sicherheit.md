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
- **Brute-Force-Schutz**: Rate-Limit je IP und je Konto (15-min-Fenster),
  atomar vor der Prüfung gezählt; wortgleiche Fehlermeldungen,
  Schein-`password_verify()` bei unbekanntem Konto.
- **Passwort-Reset**: Token 32 Byte, nur Hash gespeichert, 30 min,
  einmalig; beendet alle Sessions. Ohne Tresor verliert das Konto dabei
  nichts.
- **Sicherheitsmails**: neues Gerät, Passwort geändert/zurückgesetzt, 2FA
  geändert.

## 4. Session

- Session-Cookie über HTTPS `__Host-sid` (`httponly`, `secure`,
  `samesite=Lax`); im Docker-Dev über HTTP ohne Präfix. `secure` aus dem
  Request-Schema abgeleitet. ID-Regeneration bei Login und Rechtewechsel,
  `cache_limiter=nocache`.
- Leerlauf 30 min (Barrierefrei-Modus 60 min mit Warnung, 06 §4), absolut
  12 h. Hintergrund-Anfragen (`X-Hintergrund: 1`) verlängern nicht.
- Jeder Request prüft das Konto erneut (`LoginGuard`): Sperre und
  `session_epoch` wirken sofort.

## 5. Benutzer

- Der Installer legt den ersten Admin an.
- Weitere Benutzer per **Einladung** (Mail-Link); das Konto setzt
  Passwort und zweiten Faktor selbst. Sperren, Löschen, Rolle ändern.
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
HMAC unter dem Server-Schlüssel. Protokolliert: Anmeldungen, Benutzer- und
Rollenänderungen, Backup, Update, Restore, Export, Import, Einzug
erzeugt/bestätigt/verworfen, Version zurückgeholt, Mitglied gelöscht.
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
CSP-Compliance, Routen-Zugriffsmatrix inkl. Vorstand, Audit-Kette.
