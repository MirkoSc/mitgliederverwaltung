# Security-Checkliste

Wiederholbare Prüfung der Anwendung, angelehnt an **OWASP ASVS 4.0
Level 2**: Anmeldung (V2), Sitzung (V3), Zugriffskontrolle (V4), Ausgabe
und Parser (V5), Kryptografie (V6), Fehler und Logs (V7), Dateien (V12),
Betrieb und Konfiguration (V10/V14). Grundlage sind
`docs/spec/02-sicherheit.md` und `CLAUDE.md` §4. Vorlage war die
Checkliste von Vereinsbelege; Tresor-Zeilen entfallen (E-11).

## So wird geprüft

- **Am Code, nicht an der Spec:** Route → Controller → Service →
  Repository. Die Fundstelle nennt Datei und Klasse/Methode.
- **Ergebnis je Zeile:** ✓ erfüllt · ✗ nicht erfüllt (Befund) · n. a. nicht
  anwendbar · ⚠ Restrisiko bewusst akzeptiert · H Härtungsvorschlag.
  „(#37)" markiert eine in diesem Durchlauf behobene Zeile.
- **Wann erneut:** bei jeder neuen Route (sie verlangt einen Eintrag in
  `tests/Http/routen-zugriff/*.txt`) die passenden Abschnitte, vor jeder
  größeren Änderung alle. Die Abschnitte sind unabhängig und lassen sich
  parallel prüfen.

**Letzter Durchlauf:** 2026-10-29 (Issue #37), Stand `main` 93f103d plus
die Korrekturen des PRs zu #37; Release-Signatur nachgetragen am
2026-10-30 (bb57f6f). Die Anwendung war zu diesem Zeitpunkt mit
echten Daten installiert, das Repository öffentlich.

## Automatisierte Prüfungen (laufen in CI)

| Test | Prüft |
|---|---|
| `tests/Http/RoutenZugriffTest` | jede Route × anonym × Admin × Vorstand gegen ihre Deklaration und `tests/Http/routen-zugriff/*.txt`; `/admin` nur mit `admin.*`; CSRF auf jeder POST-Route |
| `tests/Http/CspComplianceTest` | CSP ohne `unsafe-inline`/`unsafe-eval`, kein externer Host, kein Inline-JS/`on*`/`hx-on`; Security-Header; `.user.ini` gesperrt, `X-Powered-By` entfernt (#37) |
| `tests/Support/NoShellExecutionTest` | kein `exec` & Co. |
| `tests/Integration/AnmeldungTest`, `RateLimiterTest` | Rate-Limit atomar, Kontozähler über Schreibweisen der Adresse hinweg, IPv6 je /64 (#37) |
| `tests/Feature/AnmeldungFlowTest` | Anmeldung, 2FA, Reset, Einladung, Benutzerverwaltung; Audit-Einträge nur mit IDs (#37) |
| `tests/Integration/MailerTest` | Mail-Text nach Versand/endgültigem Fehlschlag geleert (#37) |
| `tests/Service/Export/CsvWriterTest`, `tests/Support/MarkdownTest` | CSV-Formelschutz, nur eigene Pfade/http(s)/mailto als Links (#37) |

## A – Anmeldung (ASVS V2)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| A-01 | Passwort-Hash Argon2id (Rückfall bcrypt), Rehash beim Login | ✓ | `Service/Account/PasswordHasher::hash()/needsRehash()`, `Anmeldung::versuch()` (Rehash nach erfolgreichem Passwort) |
| A-02 | Passwortregel ≥ 12 Zeichen + Liste häufiger Passwörter, keine Zusammensetzungsregeln; eine Regel für Einladung, Reset, Passwortwechsel, ersten Admin | ✓ | `PasswordPolicy::verstoesse()/pruefeNeu()`, `app/data/haeufige-passwoerter.txt` (3020 Einträge, 634 davon ≥ 12 Zeichen); Aufrufer `Einladung`, `PasswortReset`, `PasswortAenderung`, `BenutzerVerwaltung::ersterAdmin()`. Passwortfelder ungekürzt (`roh()`), `autocomplete` korrekt, kein `maxlength` |
| A-03 | Wortgleiche Meldung für unbekannte Adresse / falsches Passwort / gesperrt / offene Einladung | ✓ | `AnmeldeFehler::Zugangsdaten`, `Anmeldung::versuch()`; „zu viele Versuche“ für jede Adresse gleich |
| A-04 | Keine User-Enumeration über die Laufzeit der Anmeldung (Schein-`password_verify` so teuer wie ein echter) | ✓ | `PasswordHasher::DUMMY_ARGON2ID/DUMMY_BCRYPT` (Konstanten mit PHP-Standardkosten), `verifyDummy()`; `findByEmail()` läuft in jedem Zweig |
| A-05 | Rate-Limit je IP (20) und Konto (10), 15 min, atomar **vor** der Prüfung gezählt, Erfolg setzt Kontozähler zurück | ✓ (#37) | Kontozähler unter der Konto-ID (`RateLimiter::konto()`), auch für „Passwort vergessen“; IPv6 je /64 (`RateLimiter::ip()`) – vorher per Akzent-Variante der Adresse umgehbar |
| A-06 | Kontostatus bei der Anmeldung **und** bei jedem Request (Sperre, Löschen, Rolle, `session_epoch`) | ✓ | `Benutzer::darfAnmelden()`, `Benutzerkonten::konto()`, `LoginGuard::check()` (liest das Konto je Request, vergleicht Epoche) |
| A-07 | Kein Open Redirect über `weiter` | ✓ | `AnmeldungController::weiter()` (nur `^/[A-Za-z0-9\-._~/]*$`, kein `//`, kein `\`, kein `%`), `LoginGuard::weiter()` merkt nur GET-Pfade; Ziel nach dem Code kommt aus der Session (`offeneAnmeldung()['weiter']`) |
| A-08 | Offene Anmeldung zwischen Passwort und Code (statt Tresor-Cookie der Vorlage): nur in der Session, 10 min, neue Session-ID, Epoche des Passwortzeitpunkts | ✓ | `Session::merkeOffeneAnmeldung()` (regeneriert), `offeneAnmeldung()`, `ZweiterFaktorAnmeldung::pruefen()/abschliessen()` (Epoche aus dem Passwortschritt; Sperre dazwischen → `benutzer()` liefert null) |
| A-09 | TOTP RFC 6238, ±1 Schritt, `hash_equals`, 160-Bit-Geheimnis | ✓ | `Totp::matchingStep()` (vergleicht alle Schritte des Fensters), `Totp::SECRET_BYTES = 20`, `ZweiterFaktor::APP_TOLERANZ = 1` |
| A-10 | Jeder TOTP-Zeitschritt nur einmal (ASVS 2.8.4), auch der Code der Einrichtung | ✓ | `ZweiterFaktorRepository::verbraucheSchritt()` (`last_step < ?`, `rowCount`), `speichereApp()` speichert den Schritt der Einrichtung als verbraucht (`ZweiterFaktor::richteAppEin()`) |
| A-11 | E-Mail-Code: 6 Ziffern (`random_int`), nur HMAC, 10 min, 5 Versuche, atomar verbraucht | ✓ | `ZweiterFaktor::neuerMailCode()/pruefeMailCode()`, `ZweiterFaktorRepository::versuchMailCode()` (zählt per bedingtem UPDATE), `verbraucheMailCode()` (DELETE mit Hash, `rowCount`) |
| A-12 | Backup-Codes: 10, gehasht (HMAC, Salz User-ID), einmalig, atomar; ~50 Bit je Code | ✓ | `BackupCodes::generate()`, `ZweiterFaktorRepository::verbraucheBackupCode()` (`AND used_at IS NULL`), `ersetzeBackupCodes()` in einer Transaktion |
| A-13 | 2FA-Rate-Limit (20 je IP, 8 je Konto, vor der Prüfung), Mailcodes höchstens 5 je 15 min | ✓ | `ZweiterFaktor::versuch()` in `ZweiterFaktorAnmeldung::pruefen()`, `ZweiterFaktorController::abschliessen()`, `ZweiterFaktor::bestaetige()`; `LIMIT_MAIL` je User-ID (nicht umgehbar) |
| A-14 | „Gerät merken“: 32 Byte Zufall, nur HMAC gespeichert, 30 Tage, an Konto gebunden, widerrufbar, nicht nach Backup-Code | ✓ | `ZweiterFaktor::merkeGeraet()/geraetGemerkt()`, `trusted_device.expires_at`, `ZweiterFaktorController::geraeteVergessen()`, Faktorwechsel vergisst Geräte (`abschliessen()`), Backup-Code-Zweig in `pruefen()` setzt kein Cookie; Sicherheitsmail `NEUES_GERAET`. Härtung siehe **H-A3** |
| A-15 | Erzwungene 2FA-Einrichtung für jede Seite unter `/app` und `/admin`, auch htmx und JSON | ✓ (⚠ siehe A-24) | `LoginGuard::check()` → `einrichtungVerlangen()` (JSON 403 mit `weiter`, htmx `HX-Redirect`); frei nur `/app/konto/zweiter-faktor[/…]` (`istEinrichtung()`) |
| A-16 | Zweiten Faktor ändern / Backup-Codes neu nur nach erneuter Anmeldung (Passwort + aktueller Faktor, 10 min, einmal) | ✓ | `ZweiterFaktor::bestaetige()` (eigener Passwortzähler `mfa.neu`, dann 2FA-Zähler), `Session::merkeBestaetigung()/bestaetigt()`, verbraucht in `abschliessen()`/`backupCodes()`; neues TOTP-Geheimnis bis zur Bestätigung nur als Server-Schlüssel-Chiffrat in der Session; `login()` löscht die Bestätigung |
| A-17 | Reset-Token 32 Byte, nur Hash, 30 min, einmalig, ersetzt ältere; Link/Code nicht dauerhaft im Klartext | ✓ (#37) | `EinmalLink`, `PasswortReset::anfordern()`; Mail-Text wird nach dem Versand bzw. endgültigem Fehlschlag geleert (`MailQueueRepository::markSent()/markFailed()`) |
| A-18 | Reset beendet alle Sitzungen (Epoche), auch eine offene Code-Anmeldung | ✓ | `PasswortReset::abschliessen()` → `bumpSessionEpoch()`; `AnmeldungController::neu()` zerstört die eigene Session; offene Anmeldung trägt die alte Epoche (`ZweiterFaktorAnmeldung::abschliessen()`), `LoginGuard` beendet sie |
| A-19 | Reset/Einladung: gleiche Antwort für alle Fälle, Rate-Limit 10 je IP / 3 je Adresse | ✗ niedrig | Text und Status gleich ✓ (`AnmeldungController::vergessen()`), Fehler beim Mailen verschluckt ✓; **aber** Laufzeit verrät das Konto (synchroner SMTP-Versand) → **A-19**; Adresszähler per Akzent-Variante umgehbar → **A-05** |
| A-20 | Seite mit Token in der URL sendet keinen Referer, Token nicht in History | ✓ | `AnmeldungController::tokenAusLink()`: Token wandert in die Session, sofort 303 auf den nackten Pfad (`Session::merkeEinmalLink()`); zusätzlich `Referrer-Policy: strict-origin-when-cross-origin` (`docker/web/.htaccess`, `setifempty`) |
| A-21 | Link-Basis in Mails gegen Host-Header-Injection | ✓ | `Service/Mail/PublicUrl::resolve()`: `oeffentliche_url` gewinnt; sonst Host nur, wenn er die Absender-Domain bzw. eine Subdomain ist; sonst keine Mail (`Kontomails::basis()/ohneBasis()`) |
| A-22 | Passwortwechsel: altes Passwort, Rate-Limit, andere Sitzungen enden, eigene mit neuer ID, offener Reset-Link verfällt, Hinweismail | ✓ | `PasswortAenderung::aendern()` (Zähler vor Prüfung, `bumpSessionEpoch`, `deleteForUser(reset)`), `KontoController::passwort()` → `Session::adoptEpoch()`, `Kontomails::PASSWORT_GEAENDERT` |
| A-23 | Anmeldung und Benutzerverwaltung im Audit-Log (02 §8: „Anmeldungen, Benutzer- und Rollenänderungen“) | ✓ (#37) | Benutzer einladen/Rolle/Sperren/Entsperren/Löschen (`Admin/BenutzerController`), Passwort-Fehlschlag (`AnmeldungController::anmelden()`), Abmeldung, Passwort geändert/zurückgesetzt – nur IDs, keine Adressen |
| A-24 | Konto ohne zweiten Faktor (nach Einladung / erster Admin) | ⚠ | `ZweiterFaktorAnmeldung::nachPasswort()` öffnet bei `mfa_method IS NULL` eine volle Session; `LoginGuard` lässt nur die Einrichtung zu, die ohne Rückfrage geht (`ZweiterFaktorController::darfAendern()`). Wer bis zur Einrichtung das Passwort kennt, richtet **seinen** Faktor ein. Spec 02 §3 so gewollt („Erzwungene Einrichtung beim ersten Login“) |
| A-25 | E-Mail-Code als Faktor und Passwort-Reset über dasselbe Postfach | ⚠ | Für Konten mit `MfaMethode::Email` ist das Postfach faktisch der einzige Faktor (Reset-Link + Code). Spec 02 §3: E-Mail-Code ist Standard. Hinweis für die Doku/Admin-Empfehlung: Admins sollten die App nehmen |
| A-26 | Kontosperre per Fehlversuchen als DoS (ASVS 2.2.1) | ⚠ | 10 Fehlversuche/15 min sperren auch den echten Benutzer für den Rest des Fensters (`Anmeldung::versuch()`); weich (15 min), Spec-Entscheidung |
| A-27 | Einladung: Link 72 h, einmalig, nur Hash, erneut sendbar (ersetzt), Sperren/Löschen verwirft Links, Annahme nur im Status `eingeladen` | ✓ | `Einladung::einladen()/erneutSenden()/annehmen()/finde()`, `BenutzerVerwaltung::sperren()` (`deleteForUser`), `auth_token … ON DELETE CASCADE` |
| A-28 | Sicherheitsmails: Passwort geändert/zurückgesetzt, 2FA geändert, neue Backup-Codes, neues Gerät | ✓ | `Kontomails::hinweis()` aus `KontoController::passwort()`, `AnmeldungController::neu()`, `ZweiterFaktorController::abschliessen()/backupCodes()`, `ZweiterFaktorAnmeldung::pruefen()` |
| A-29 | Login-CSRF | ✓ | auch öffentliche POSTs laufen durch `CsrfSchutz::pruefe()` (`Routen::add()`, `CsrfSchutz::noetig()`) |

## B – Sitzung (ASVS V3)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| B-01 | Session-Cookie `httponly`, `samesite=Lax`, `secure` aus dem Schema, `Path=/`, keine Domain, Sitzungs-Cookie (kein Max-Age) | ✓ | `Http/Session::start()` (`session_set_cookie_params`), `Request::httpsFromGlobals()`. Härtung siehe **H-B3** |
| B-02 | Kein Cache für Sitzungsseiten (`cache_limiter=nocache`); `use_strict_mode` bewusst aus; `gc_maxlifetime` ≥ Leerlauf | ✓ | `Session::OPTIONEN` (nocache, `gc_maxlifetime` = 75 min); Login-, Reset-, 2FA- und Backup-Code-Seiten starten alle eine Session |
| B-03 | Geräte-Cookie mit `__Host-`-Präfix, über HTTPS nur unter diesem Namen gelesen | ✓ | `Http/Cookie::hostPrefixed()/hostPrefixedFrom()`, `ZweiterFaktorAnmeldung::GERAET_COOKIE` |
| B-04 | Session-Cookie über HTTPS als `__Host-sid` | ✓ | `Session::NAME_HTTPS` |
| B-05 | Neue Session-ID bei Login, nach dem Passwortschritt, bei Rollen- und Passwortwechsel; CSRF-Token verworfen | ✓ (H) | `Session::login()` (regeneriert, löscht `csrf`, `mfa_*`), `merkeOffeneAnmeldung()`, `rolleAbgleichen()`, `adoptEpoch()`. Bei Rolle/Passwort bleibt das CSRF-Token → **H-B1** |
| B-06 | Leerlauf 30 min (Barrierefrei 60 min, mit Warnung), absolut 12 h | ✓ | `LoginGuard::LEERLAUF_*`, `HOECHSTDAUER_SEKUNDEN`, `leerlaufSekunden()`, `Session::isExpired()` (fehlende Zeitstempel = abgelaufen); Warnung `public/js/leerlauf.js` |
| B-07 | Hintergrund-Anfragen (`X-Hintergrund: 1`) verlängern nicht | ✓ | `LoginGuard::HINTERGRUND_HEADER`; gesetzt in `public/js/abgleich.js`, `backups.js`, `import.js`. `update.js` setzt ihn nicht – vom Admin beobachtete Schrittkette, unkritisch |
| B-08 | Sperren / Löschen / Reset / Passwortwechsel beenden laufende Sitzungen sofort | ✓ | `LoginGuard::check()` (Konto null oder Epoche abweichend → `destroy()`), `BenutzerVerwaltung::sperren()` (`bumpSessionEpoch`) |
| B-09 | Abmelden nur per POST + CSRF, Session zerstört (alte Datei gelöscht) | ✓ | `app/routes/anmeldung.php` (`post('/abmelden')`), `AnmeldungController::abmelden()` → `Session::destroy()` (`session_regenerate_id(true)` + `session_destroy()`); Menüeintrag `Navigation` mit `HttpMethod::Post` |
| B-10 | CSRF-Token auf allen schreibenden Routen, zentral | ✓ | `Http/Routen::add()` + `CsrfSchutz::pruefe()`, `Session::checkCsrf()` (`hash_equals`, 32 Byte); Ausnahme nur Cron (Token) |
| B-11 | Nur HTTPS (Umleitung HTTP → HTTPS), HSTS | ✓ | Shim `docker/web/index.php` (GET/HEAD → 302), `Strict-Transport-Security` in `docker/web/.htaccess` |
| B-12 | Tresor-Schlüssel geteilt (Session + Cookie) | n. a. | kein Tresor (E-11) |
| B-13 | Benutzer kann alle anderen Sitzungen beenden (ASVS 3.3.4, L2) | H | nur indirekt über Passwortwechsel → **H-B2** |
| B-14 | Session-Inhalt ohne Mitglieder- und Klartext-Geheimnisse | ✓ | `Session` speichert User-ID, Epoche, Zeitstempel, CSRF, Flash; TOTP-Geheimnis der Einrichtung nur als Chiffrat; Reset-/Einladungs-Token höchstens bis zum Abschluss (`merkeEinmalLink()`) |

## C – Zugriffskontrolle (ASVS V4)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| C-01 | Jede Route deklariert `Zugriff` (Pflichtargument), und der Guard entsteht aus derselben Deklaration | ✓ | `Http/Router::add()`, `Http/Routen::add()` (Reihenfolge Ersatz → Cron-Token → CSRF → LoginGuard außen), `LoginGuard::pruefe()/darf()` |
| C-02 | Alle Deklarationen sind geprüft und festgeschrieben | ✓ | `tests/Http/routen-zugriff/*.txt`, `RoutenZugriffTest::testEveryRouteDeclarationIsReviewed`. Der Test setzt den Ersatz-Handler hinter den echten Guard. |
| C-03 | Öffentlich sind nur `/`, `/anmelden…`, `/abmelden`, `/impressum`, `/datenschutz`, `/logo/{groesse}` (11), dazu `/cron` per Token | ✓ | `RoutenZugriffTest::testOnlyTheWayInAndTheLegalPagesArePublic`/`…OnlyTheCronIsDeclaredCron`. `Routen::cronToken()` vergleicht mit `hash_equals`. `/logo` prüft die Größe gegen eine Whitelist (`LogoController::anzeigen`). |
| C-04 | Der Installer ist nur erreichbar, solange `config.php` fehlt | ✓ | `bootstrap.php`/`Anwendung::installation()`: getrennter Kernel. Die Freigabe ist an die Session gebunden (`InstallToken::isReleased()`), jeder POST hat CSRF. Einzelheiten gehören zum Bereich Installer. |
| C-05 | Jede `/admin`-Route verlangt ein `admin.*`-Recht | ✓ | `RoutenZugriffTest::testEveryAdminRouteNeedsAnAdminRight`, `Permission::istAdmin()`. Auch der Wartungs-Bypass in `public/index.php` öffnet nur `/admin*` (Guard gilt weiter) und `/anmelden`. |
| C-06 | Jede `/app`-Route verlangt eine Anmeldung, ohne eingerichteten 2. Faktor ist nur dessen Einrichtung erreichbar | ✓ | `RoutenZugriffTest::testEveryAppRouteNeedsALogin`, `LoginGuard::check()` (`istEinrichtung`) |
| C-07 | Rollen → Rechte entsprechen 02 §6, fail-closed | ✓ | `Domain/Rolle::rechte()`. Eine unbekannte Rolle in der DB wird zu Vorstand (`BenutzerRepository` Z. 173). `Recht` ohne Permission ergibt false (`LoginGuard::darf`). |
| C-08 | Vorstand erreicht nichts aus 08: Seite, Einzelbeitrag, Erzeugen, Betrag, Bezahlt, Rücklastschrift, Vermerk, Einzug, Einzugseite, Lastschriftdatei, Bestätigen/Verwerfen | ✓ | `app/routes/beitraege.php`, `einzug.php`: alles `beitraege.verwalten`. `BeitraegeSeiteTest::testTheVorstandGets403OnEveryRouteAndNoTab` |
| C-09 | Reiter „Beiträge“ am Mitglied (auch per htmx bzw. `?reiter=beitraege`) | ✓ | `BeitraegeReiter::recht()` = `BeitraegeVerwalten`. `MitgliederController::detail()` filtert über `Erweiterungen::alle($d, $konto)`, ein unbekannter oder verbotener Reiter fällt auf den ersten erlaubten zurück. Getestet (s. C-08). Zur Härtung siehe C-F4. |
| C-10 | htmx-Header (`HX-Target`, `HX-Request`) umgehen keine Prüfung | ✓ | `MitgliederController::seite()`: Der Header wählt nur das Fragment aus. Die Daten sind schon nach Konto gefiltert, alle drei Antworten zeigen dieselbe Menge. Auch das Fragment `SystemController::systemcheck` liegt hinter `admin.system`. |
| C-11 | Liste „Selbst kassieren“ nur für den Admin (Ansicht und Export) | ✓ | `SelbstKassierenListe::recht()`. `ListenController::anzeigen/exportieren` liefern über `Listen::registry()->finde(…, $konto)` beim Vorstand 404. `SelbstKassierenTest` |
| C-12 | Gesamtexport ohne Beitragsspalten für den Vorstand, auch beim Sortieren | ✓ | `AlleMitgliederListe::ergebnis()` (`$mitBeitrag`). `ListenAnsicht::aus()` sortiert nur nach gelieferten Spalten, eine versteckte Spalte lässt sich also nicht über die Sortierung erschließen. Hinweis: IBAN, Mandat und Zahlart sieht und ändert der Vorstand je Mitglied im Reiter „Daten“ und „Verlauf“. Das ist laut 02 §6 so gewollt, 09 §5 verbirgt sie nur im Massen-Export. |
| C-13 | Vorstand löscht keine Mitglieder und keine Nachweise, auf keinem Weg | ✓ | `mitglied.php`/`nachweise.php`: `mitglieder.loeschen`. Die anderen Löschwege (Abgleich „löschen“, „Bestand leeren“) verlangen `admin.import`. Familie/Verlauf löschen nichts außer einer leeren Familie. |
| C-14 | Vorstand öffnet weder Einstellungen noch Benutzer, System, Import, Audit, Update, Backup oder Designsystem | ✓ | alle `admin.*`. `RoutenZugriffTest::testSpotChecksAgainstTheSpec` |
| C-15 | IDOR Nachweis-Datei: nur über das eigene Mitglied erreichbar | ✓ | `NachweisController::datei()/loeschen()` prüfen `nachweis->mitgliedId === {id}`. Die Blob-ID kommt nur aus der Nachweis-Zeile, nie vom Client. |
| C-16 | IDOR Lastschriftdatei: nur über den eigenen Einzug erreichbar | ✓ | `EinzugController::datei()` → `Einzuege::datei()` liest `einzug.datei_blob_id`, nie eine Blob-ID aus dem Request. |
| C-17 | Import/Abgleich: keine Job-IDs vom Client | ✓ | `ImportController`: Es gibt je eine Kette (Singleton-Status), der Client schickt nur Mitgliedsnummern als Auswahl. |
| C-18 | Backup-Download: kein Pfad über `{name}` | ✓ | `BackupService::path()`: Regex `NAME` plus `is_file`. Ein Name mit angehängtem `\n` (Regex ohne `D`) findet keine Datei, als Härtung trotzdem `D` ergänzen. |
| C-19 | Keine zustandsändernden GETs | ✓ | Alle GET-Handler gelesen. Lösch-GETs (`/loeschen`, `/admin/import/leeren`) zeigen nur die Rückfrage. `/cron` ist token-geschützt und mit Absicht ein GET. HEAD und unbekannte Methoden laufen als GET (`HttpMethod::fromString`) und sind damit harmlos. |
| C-20 | CSRF auf jeder POST-Route, zentral, auch für htmx, JSON-Ketten und Uploads | ✓ | `CsrfSchutz::noetig()` (jede Nicht-GET-Methode außer Cron), `Session::checkCsrf()` (Feld `_csrf` oder Header `X-CSRF-Token`, nie aus der Query, ein Array wird abgewiesen). Das Layout setzt `hx-headers`. Multipart (Nachweis, Logo, Import) trägt `_csrf`. `RoutenZugriffTest::testEveryWritingRouteWantsACsrfToken` |
| C-21 | Kein Mass Assignment am Mitgliederformular | ✓ | `MitgliedFormular::gelesen()` liest eine feste Feldliste auf das gespeicherte Mitglied. `herkunft`, `familieId/Rolle`, `id` und Zeitstempel bleiben unverändert. `version` dient nur als optimistische Sperre (`MitgliedRepository` Z. 324–330). An Jahresbeiträge kommt das Formular nicht heran. „Wer/wann“ bei Nachweis, Ehrung und Vermerk setzt der Server. |
| C-22 | Offene Weiterleitungen über `zurueck`/`weiter` | ✓ | `BeitraegeController::sichereAdresse()` (verankert auf `/app/(beitraege…|mitglieder)`, eingeschränkter Zeichensatz). `EinzugController::zurueck()` erlaubt nur den exakten eigenen Pfad. `AnmeldungController::weiter()` lässt nur Pfade zu, kein `//` und keinen `\`. `LoginGuard::weiter()` übernimmt nur den Pfad. `UpdateController` hat bewusst keinen Parameter. |
| C-23 | Inhalt der Mitglieder-Reiter je Rolle | ✓ | Daten, Familie, Nachweise und Verlauf verlangen `mitglieder.sehen`, Beiträge `beitraege.verwalten`, der Abschnitt Hinweise `mitglieder.sehen`. Das stimmt mit 07 §9 überein. |
| C-24 | Rollenwechsel, Sperre und Löschung wirken sofort, mit Session-ID-Regeneration | ✓ | `LoginGuard` liest das Konto bei jedem Request (`Benutzerkonten`). `Session::rolleAbgleichen()` erneuert die ID. Sperren erhöht `session_epoch` und löscht Tokens, ein gelöschtes Konto ergibt `null` und beendet die Session. |
| C-25 | Admin sperrt, löscht oder degradiert weder sich selbst noch den letzten aktiven Admin | ✓ | `BenutzerVerwaltung::rolleAendern/sperren/loeschen` (eigenes Konto) und `imSchutz()` (`FOR UPDATE` auf aktive Admins). `BenutzerVerwaltungTest` |
| C-26 | Eigenes Konto (Passwort, 2FA, Barrierefrei) nur am eigenen Konto, keine User-ID aus dem Request | ✓ | `KontoController`, `ZweiterFaktorController` arbeiten nur mit `session->userId()` |
| C-27 | Statische Dateien: nur `public/`, nur erlaubte Endungen | ✓ | `StaticFileHandler::tryServe()` (Realpath-Prüfung, Whitelist ohne `.php`) |
| C-28 | Benutzer- und Rollenänderungen im Audit-Log (02 §8) | ✓ (#37) | `Admin/BenutzerController::protokollieren()` |
| C-29 | Downloads mit Massendaten nachvollziehbar (Lastschriftdatei, Backup) | ✓ (#37) | `einzug.datei_heruntergeladen`, `backup.heruntergeladen` |
| C-30 | Deklaration „Ehrung vermerken“ entspricht 02 §6 | ✓ (#37) | `app/routes/ehrungen.php` → `mitglieder.bearbeiten` |
| C-31 | Erweiterungen (Reiter, Listen, Abschnitte) schließen im Zweifel | ✓ (#37) | DocBlock `Support/Erweiterung::recht()` korrigiert: für Reiter/Listen ohne eigene Route ist `recht()` die serverseitige Prüfung; Seiten rufen `alle()` immer mit Konto |

## D – Uploads und Dateien (ASVS V12)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| D-01 | Nachweis: Typ per Magic Bytes (`finfo`), Allowlist PDF/JPEG/PNG/WebP, Browser-Angabe ignoriert | ✓ | `NachweisController::pruefeDatei()`, `::mime()`; SVG/HTML fallen durch die Allowlist |
| D-02 | Nachweis: geprüft werden genau die gespeicherten Bytes (kein TOCTOU) | ✓ | dieselbe PHP-Tempdatei wird geprüft und per `storeStream()` verschlüsselt; ein Client kann sie nicht austauschen |
| D-03 | Nachweis: Größe serverseitig (10 MB), leere Datei abgelehnt | ✓ | `NachweisController::MAX_BYTES`; leere Datei → `application/x-empty`, nicht in der Allowlist |
| D-04 | Nachweis: Datei verschlüsselt außerhalb des Webroots, zufälliger Name | ✓ | `BlobService` (secretstream), `FsBlobBackend::path()` nur `^[0-9a-f]{32}$` |
| D-05 | Originaldateiname nie auf Platte, nur bereinigt in `file_blob.original_name` | ✓ | `BlobMeta::bereinigterName()` (Pfad weg, Steuerzeichen weg, 255 Zeichen) |
| D-06 | Download: Allowlist-MIME, `nosniff`, `attachment`, `no-store` | ✓ | `NachweisController::datei()`, `EinzugController::datei()`, `BackupController::download()`; zusätzlich global `docker/web/.htaccess` |
| D-07 | Download-Dateiname ohne Mitgliederdaten | ✓ (#37) | `NachweisController::datei()`: `nachweis-<id>.<endung>` |
| D-08 | Download-Pfade ohne Path Traversal aus IDs/Namen | ✓ | Nachweis über DB-ID + Mitglied-Abgleich; Backup-Name gegen `BackupService::NAME`; Logo-Größe gegen Allowlist (`LogoController::anzeigen()`) |
| D-09 | Logo: nur PNG/JPEG per Inhalt, kein SVG | ✓ | `LogoService::speichern()` (`getimagesize()` + `IMAGETYPE_PNG/JPEG`) |
| D-10 | Logo: Pixel-Obergrenze **vor** dem Dekodieren (Dekompressionsbombe) | ✓ | `LogoService::MAX_PIXEL` (16 MP) aus dem Header, erst danach `imagecreatefromstring()`; 16 MP ≈ 64 MB bei `memory_limit` 384 MB |
| D-11 | Logo: Größe 5 MB serverseitig, Original verworfen, nur von GD neu erzeugte PNGs ausgeliefert | ✓ | `LogoService::MAX_BYTES`, `::ableiten()`; feste Zieldateinamen `<größe>.png` |
| D-12 | Logo: abgelehnter Upload lässt altes Logo stehen, Zwischendateien weg | ✓ | `.png.neu` + `rename()`, `finally` räumt auf |
| D-13 | Import-CSV: Größe serverseitig (8 MB), nur als verschlüsselter Blob zwischengelagert | ✓ | `ImportController::pruefeDatei()`, `ErstImport::MAX_BYTES`, `ImportDateiAblage::speichern()` |
| D-14 | Import-CSV: Begrenzung von Zeilen/Speicher beim Lesen | ✓ (#37) | `ImportLeser::MAX_ZEILEN` (20.000) vor dem Parsen |
| D-15 | Installer-Restore: nur echte Uploads (`is_uploaded_file`/`move_uploaded_file`), Arbeitsname zufällig | ✓ | `InstallController::hochgeladen()`, `::uploadAblegen()` (`hochgeladen_<16 hex>.zip.enc`, 0600) |
| D-16 | Installer-Restore: `config.php` wird nicht ausgeführt, Schlüssel per Regex gelesen, max. 64 KiB | ✓ | `RestoreService::schluesselAusConfig()` |
| D-17 | Klartext-Zwischendateien werden aufgeräumt | ✓ / Härtung | PHP-Tempdateien enden mit dem Request; Restore-Arbeitsdateien (`dump.sql` im Klartext) per `Wiederherstellung::abbrechen()` und Cron `verwaistAufraeumen()` (1 h). Toter Chunk-Upload ohne Cron-Aufräumer → **D-25** |
| D-18 | ZIP-Restore: kein Zip-Slip | ✓ | `RestoreService::zielFuer()` – nur `blobs/xx/<32 hex>` und `logo/<LOGO_NAME>`, alles andere gezählt und verworfen |
| D-19 | ZIP-Restore: Bombe/Eintragszahl | ✓ (vertrauenswürdig) | Backup ist mit dem Server-Schlüssel verschlüsselt und wird vor dem Auspacken geprüft (`BackupCipher::pruefen()`); wer es fälschen kann, hat ohnehin den Schlüssel. Portionen je Schritt (`DATEIEN_PER_STEP`, `DATEI_BYTES_PER_STEP`) |
| D-20 | Update-ZIP: Prüfsumme/Signatur vor `extractTo()` | ✓ | `UpdateService::extract()` → `pruefeEchtheit()` vor `ReleaseDownloader::extractTo()`; Host-Allowlist in `ReleaseDownloader::pruefeUrl()` (Details Bereich F/H) |
| D-21 | Statische Dateien: Extension-Allowlist, `realpath`-Präfix-Prüfung, NUL abgewiesen | ✓ | `Http/StaticFileHandler::tryServe()` |
| D-22 | Dateien nicht ausführbar abgelegt, `shared/` gesperrt | ✓ | Blobs/Logo/Backups unter `shared/var/`, Deny-`.htaccess` aus setup/Updater |

## E – Ausgabe, Kodierung, Parser (ASVS V5)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| E-01 | `e()` = `htmlspecialchars(ENT_QUOTES, 'UTF-8')` | ✓ | `View/helpers.php` |
| E-02 | Alle `<?=`/`echo` mit Daten laufen durch `e()` | ✓ | Tokenizer-Durchlauf über `app/views`: rund 190 Treffer ohne führendes `e(` – alle sind Konstanten, Ternäre mit festen Texten, ganze Zahlen (`data-leerlauf`, `LAENGEN`) oder Hilfs-Closures, die selbst escapen (`$text`/`$datum`/`$attribute` in `mitglied/formular.php`, `$bereichLink`, `$ergebnisLink`, `$kopf`, `$statusEtikett`, `$einzugEtikett`, `$wer`, `$rollenwahl`, `$versteckt`); Roh-HTML nur `$content`/`$abschnitt['inhalt']` (gerenderte Fragmente), `$html` (Markdown, E-05), `$qrSvg` (nur Zahlen + escaptes Label, `Support/QrCode::svg()`) |
| E-03 | Mail-Templates ohne `e()` | ✓ | `app/views/mail/*` sind `text/plain` (`MailMessage::headers()`), kein HTML-Kontext |
| E-04 | `href`/`action`/`src`/`hx-*`/`data-*` nur aus Serverpfaden bzw. escaped, keine `on*`, kein `hx-on`, kein `javascript:` | ✓ | alle URL-Attribute aus Controller-Konstanten/`adresse()`; `data-strasse/plz/ort/land` in `mitglied/hinweise.php` escaped; `hx-headers` nur CSRF-Token (escaped, `ENT_QUOTES` deckt das einfache Anführungszeichen) |
| E-05 | Rechtstexte: alles zuerst escaped, Links nur http(s)/mailto/eigene Pfade | ✓ / **Härtung** | `Support/Markdown::inline()` – Escaping korrekt, `javascript:` bleibt Text; aber protokollrelative Ziele `//host` und `/\host` gehen durch → **E-21** |
| E-06 | JSON in Seiten | ✓ | kein Inline-JSON; Werte als `data-*`; `htmx-config` fest (`selfRequestsOnly`, `historyCacheSize 0`, `allowEval false`) in `layout.php` |
| E-07 | JSON-Antworten mit korrektem Content-Type | ✓ | `Http/Response::json()` (`application/json; charset=utf-8`), global `nosniff` |
| E-08 | Kein DOM-XSS im eigenen JS | ✓ | `public/js/*.js` (auch neu `anschrift.js`): nur `textContent`/`.value`, kein `innerHTML`, `insertAdjacentHTML`, `eval`, `location =` mit Daten; kein `localStorage`/`sessionStorage` |
| E-09 | Weiterleitungsziele aus Eingaben nur per Allowlist (ASVS 5.1.5) | ✓ | `BeitraegeController::sichereAdresse()`, `EinzugController::zurueck()`, `AnmeldungController::weiter()` (kein `//`, kein `\`) |
| E-10 | CSV-Export: Formelschutz für Textzellen (`= + - @ TAB CR`) | ✓ (#37) | `CsvWriter::ohneFormel()`: Apostroph vor `= + - @ TAB CR`, Zahlen/Telefonnummern bleiben |
| E-11 | xlsx-Export: Text nie als Formel | ✓ | `XlsxWriter::textzelle()` – nur `t="inlineStr"`, keine Formelzellen, keine Shared Strings |
| E-12 | xlsx: XML-Escaping, unzulässige XML-Zeichen entfernt | ✓ | `XlsxWriter::text()`/`::attribut()` (`ENT_XML1`, Steuerzeichen-Filter), Blattname bereinigt |
| E-13 | Export-Dateinamen ohne Personendaten, `attachment`, `no-store` | ✓ | `ListenExport::dateiname()` (`[a-z0-9-]`), alle `Liste::dateiname()` nur Datum/Filter-Schlüssel; Lastschrift `lastschrift-<jahr>-<id>.xml` |
| E-14 | pain.008: XML-Escaping, SEPA-Zeichensatz, Längen | ✓ | `Sepa/Pain008::kind()` (DOM-Textknoten), `::pruefe()` mit `SepaText::istZulaessig()`/`istReferenz()`, IBAN/BIC/Gläubiger-ID-Regex mit `/D`; Fehlermeldungen ohne Werte |
| E-15 | XML-Parsen fremder Daten (XXE) | ✓ | keine Stelle: kein `loadXML`, `simplexml_*`, `XMLReader`; DOM nur zum Erzeugen |
| E-16 | Import-CSV: BOM, Kodierung, NUL, Spaltenzahl, Feldlängen, fehlerhafte Zeilen | ✓ / Härtung | `ImportLeser::lesen()`/`::zeile()` – BOM entfernt, `mb_check_encoding`, NUL abgewiesen, `fgetcsv(..., escape: '')`, Spaltenzahl je Zeile, `MAXIMUM` je Spalte; `bemerkung` ohne Grenze → **E-23**; Massendaten → **D-24** |
| E-17 | Mail: CR/LF abgewiesen, RFC 2047, Anzeigename gequotet, Adressen validiert | ✓ | `Mail/MailMessage::assertSingleLine()`, `::encodeWord()`, `::displayName()`; `MailSettings::eingabeFehler()` (`FILTER_VALIDATE_EMAIL` für Absender/Antwort-an, Admin und Installer); Testmail-Empfänger validiert (`MailController`) |
| E-18 | Kein `unserialize` fremder Daten, kein `eval`, kein `include $var` mit Eingaben | ✓ | einziges `unserialize`: `Backup/ZipAnhang::crcKontext()` (eigene Arbeitsdatei, `allowed_classes: [HashContext]`); `require $var` nur auf feste Verzeichnisse (`View::renderFile`, `MailTemplates::render`, `Routen`, `Config`) mit Template-Namen aus Code; `extract(EXTR_SKIP)` nur auf eigene Daten |
| E-19 | Dynamische Regex nur mit `preg_quote`; `LIKE`-Platzhalter escaped | ✓ | `Http/Route::compile()` (`preg_quote`); `MitgliedRepository`, `MitgliederListeRepository`, `FamilieRepository` (`addcslashes('%_\\')`) |
| E-20 | Header-Werte ohne CR/LF (Content-Disposition, Location) | ✓ | Dateinamen fest bzw. aus IDs (#37: Nachweis `nachweis-<id>.<endung>`) |

## F – Kryptografie (ASVS V6)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| F-01 | Blobs: `secretstream_xchacha20poly1305`, 64-KiB-Blöcke, Versionsbyte, `TAG_FINAL` Pflicht; Abschneiden, Anhängen hinter dem Ende, Umordnen = Fehler | ✓ | `Storage/BlobCipher`, `BlobStreamWriter::finish()`, `BlobStreamReader::read()/finish()` |
| F-02 | Dateischlüssel je Datei zufällig (32 Byte `random_bytes`), mit `secretbox` unter dem Server-Schlüssel gewrappt | ✓ | `Crypto/FileKey::generate()`, `BlobService::store()`, `ServerCrypto::encrypt()` |
| F-03 | Nonce je Verschlüsselung zufällig, secretstream-Header aus `init_push` | ✓ | `ServerCrypto::encrypt()`, `ServerKeyGeheimnisSchutz::schuetzen()`, `BlobStreamWriter` |
| F-04 | Versionsbyte an jedem Format, unbekannt = Fehler | ⚠ Härtung | ✓ `ServerCrypto`, `BlobStreamReader`, `BackupCipher` (Magic `MVBK` + Versionsbyte); ✗ SMTP-Passwort (`Mail/ServerKeyGeheimnisSchutz`, eigenes Format ohne Versionsbyte) → s. F-07 |
| F-05 | TOTP-Geheimnisse und SMTP-Passwort nur verschlüsselt in der DB | ✓ | `Account/ZweiterFaktor` (`ServerCrypto`), `Mail/MailSettingsRepository` + `ServerKeyGeheimnisSchutz`; Installer speichert das SMTP-Passwort sofort verschlüsselt |
| F-06 | `random_bytes`/CSPRNG für alles Sicherheitsrelevante, `hash_equals` für Geheimnis-Vergleiche | ✓ | Volltextsuche: kein `mt_rand`/`rand`/`uniqid`/`md5`/`sha1`; `random_int` (CSPRNG) nur Mail-Code/Backup-Codes; `hash_equals` in `Routen::cronToken()`, `InstallToken`, `Session` (CSRF), `Totp`, `ZweiterFaktor`, `ReleaseDownloader::verifyChecksum()`, `CodeIntegritaet` |
| F-07 | Schlüsseltrennung (ein Schlüssel – ein Zweck) | ⚠ Härtung | `ServerCrypto::hmac()` leitet sauber ab ✓; aber `Audit/AuditLog::protokollieren()` nutzt den **rohen** Server-Schlüssel als HMAC-Schlüssel, `ServerKeyGeheimnisSchutz` dupliziert `ServerCrypto` |
| F-08 | Fehlschlag sicher: falscher Schlüssel/Manipulation → Exception, nie „kürzere Datei" | ✓ | `CryptoException` überall; `BlobService::openRead()` liefert nur authentifizierte Blöcke, das Ende wird erzwungen; `BackupCipher::kopfPruefen()` scheitert vor dem ersten geschriebenen Byte |
| F-09 | Schlüssel nie in Logs/Dumps/Traces | ✓ | `__debugInfo()` in `Config`, `ServerCrypto`, `FileKey`; `#[\SensitiveParameter]`; `bootstrap.php`/`setup.php` setzen `zend.exception_ignore_args`; `Kernel` loggt nur Klasse/Meldung/Pfad |
| F-10 | Server-Schlüssel 32 Byte aus `random_bytes`, base64 in `config.php`, einmal zum Ausdrucken gezeigt | ✓ | `Installer/ConfigWriter::neuerServerSchluessel()`, `InstallController::admin()/schluessel()` |
| F-11 | Schlüsselwechsel nach Kompromittierung möglich | ✓ | `Crypto/Schluesselwechsel` (Schrittkette, Einstellungen → System, Passwort erneut), `ConfigWriter::schluesselSchreiben()` (atomar, 0600), `ServerCrypto`/`ServerKeyGeheimnisSchutz` lesen während des Wechsels mit beiden Schlüsseln; Spec 02 §2, `docs/betrieb.md` §3 (#80) |
| F-12 | Audit-Log manipulationserkennend (Hash-Kette) | ⚠ niedrig | `Audit/AuditLog::hashBilden()`: ungeschlüsseltes SHA-256 – erkennt nur naive Änderungen; Abschneiden am Ende nicht erkannt (Spec 02 §8, bewusst) |
| F-13 | IP im Audit-Log nur als HMAC unter dem Server-Schlüssel | ✓ | `AuditLog::protokollieren()` (Schlüsseltrennung s. F-07) |
| F-14 | Backup als Ganzes verschlüsselt, nur mit Schlüssel lesbar; abgeschnitten/verändert/verlängert = Fehler; secretstream-Zustand im Statusfile verschlüsselt | ✓ | `Backup/BackupCipher::verschluesseln()/entschluesseln()`, `zustandVerpacken()` |
| F-15 | Bindung Chiffrat ↔ Zeile (AAD) | ⚠ n. a. | kein AAD bei `key_enc`/TOTP; ein Tausch zwischen Zeilen setzt DB-Schreibzugriff voraus, der ohnehin alles erlaubt (E-11) – bewusst so lassen |

## H – Betrieb: Backup, Update, Installer, Integrität, Konfiguration (ASVS V10/V14)

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| H-01 | Backups außerhalb der DocumentRoot, `shared/` per `.htaccess` gesperrt | ✓ | `Paths::backupDir()`, `DenyHtaccess::ensureAll()` (setup.php, jede Installer-Seite, `UpdateService::finish()`), Systemcheck `directory_locks`/`config_exposure` |
| H-02 | Backup-, Arbeits- und Restore-Dateien nur für den Besitzer (0600 / Verzeichnis 0700) | ✓ | `BackupService::nurFuerBesitzer()`, `BackupErstellung::oeffnen()`, `BackupCipher::oeffnen()`, `Wiederherstellung::gesperrt()`, `InstallController::uploadAblegen()` |
| H-03 | Download nur `admin.system`, Namensmuster, `config.php` nie im Backup (daher GET ohne Re-Auth vertretbar) | ✓ | `app/routes/backups.php`, `BackupService::path()`/`NAME`, `BackupController::download()` |
| H-04 | Backup-Download im Audit-Log | ✓ (#37) | `BackupController::download()` → `backup.heruntergeladen` |
| H-05 | Restore nur im Installer (Token), `config.php` nie `include`, kein Zip-Slip, Backup-Auswahl nur aus Liste | ✓ | `RestoreService::schluesselAusConfig()` (Regex), `zielFuer()`, kein `extractTo()`; `InstallController::backupsAufDemServer()`; keine Admin-Route für Restore |
| H-06 | Verwaiste Klartext-Arbeitsdateien (Backup-Kette, Restore) werden entfernt | ✓ | `Cron/BackupAufraeumenTask` (1 h), `Wiederherstellung` löscht bei `fertig`, `InstallController::neu()` |
| H-07 | Klartext-Upload-Chunks (`shared/var/tmp/upload`) nach 24 h entfernt | ✓ (#37) | `Cron/UploadAufraeumenTask` (24 h) |
| H-08 | Update: feste Quelle (eigenes Repo), nur https, Port 443, keine Credentials, jeder Redirect-Sprung geprüft (max. 5), TLS-Prüfung an | ✓ | `ReleaseDownloader::pruefeQuelle()/pruefeUrl()/folgeWeiterleitungen()`, `CURLOPT_FOLLOWLOCATION=false`, `CURLOPT_PROTOCOLS_STR=https`, `follow_location=0` |
| H-09 | Prüfsumme; Liste gehört zur Zielversion; kein Downgrade (in `download` **und** `extract`) | ✓ | `UpdateService::pruefeEchtheit()`, `versionDerListe()`, `check()` |
| H-10 | Zip-Slip beim Entpacken des Releases | ✓ | `ReleaseDownloader::extractTo()` (`ZipArchive::extractTo()` entfernt `../`/führende `/`, legt keine Symlinks an) + ZIP-Prüfsumme aus dem eigenen Repo |
| H-11 | Update/setup.php: **Echtheit** (Signatur) statt nur Integrität | ✓ (#37) | `Update/ReleaseSignatur::PUBLIC_KEY` gesetzt (Commit bb57f6f), Secret `RELEASE_SIGNING_KEY` im Release-Workflow; ab v0.1.3 mit `checksums.txt.sig`, Updater und `setup.php` nehmen nur noch signierte Releases an |
| H-12 | Release-Pipeline gegen Lieferketten-Angriffe | ✓ (#79) | `.github/workflows/release.yml`/`ci.yml`: alle Actions per Commit-SHA gepinnt (Version als Kommentar), `permissions` minimal, Secret nur im `publish`-Job; dort nur `actions/*` (kein `setup-php`, kein `action-gh-release`: PHP des Runners mit Prüfschritt auf `sodium`, `gh release create`); `ReleaseWorkflowTest::testEveryActionIsPinnedToACommitSha`. Optional offen: Environment `release` mit Pflicht-Freigabe (Handarbeit in den Repo-Einstellungen) |
| H-13 | Wartungs-Flag + `rename()`-Umschaltung, Shim als Konstante, Flag bleibt bis Selbsttest, alter Shim zurück bei Fehler | ✓ | `ReleaseSwitcher::switchTo()/rollback()/SHIM`, `UpdateService::finish()`, `ShimContentTest` |
| H-14 | Schrittfolge der Update-Kette serverseitig erzwungen | ⚠ Härtung | `UpdateService::step()` prüft nur `zielVersion` – `switch` ist z. B. ohne `backup` aufrufbar |
| H-15 | `setup.php` sperrt/löscht sich nach der Installation | ⚠ Härtung | Löschen in `InstallController::abschliessen()` ✓ (sonst Hinweis); Sperre in `bin/setup.template.php` hängt nur an `is_dir(current)` |
| H-16 | `/install` nur ohne `config.php` und nur mit Installations-Token (128 Bit, `hash_equals`, Session-ID neu, Token sofort aus der URL weg-redirectet, am Ende gelöscht) | ✓ | `bootstrap.php`, `Anwendung::installation()`, `Installer/InstallToken`, `InstallController::zeigen()/erwarte()/abschliessen()` |
| H-17 | Installer ohne Debug-Modus, DB-Fehler ohne Werte, CSRF auf jeder POST-Route | ✓ | `Anwendung::installation()` (`debug: false`, `CsrfSchutz`), `InstallController::datenbankFehler()` |
| H-18 | `config.php`: Server-Schlüssel und Cron-Token (192 Bit) aus `random_bytes`, 0600, atomar per `rename()` | ✓ | `Installer/ConfigWriter::schreiben()` |
| H-19 | Deny-`.htaccess` in `shared/`, `releases/`, `current/` (Release-Wurzel bringt eigene mit) | ✓ | `.htaccess` (Repo-Wurzel), `Update/DenyHtaccess`, `ReleaseSwitcher::lockPrivateDirectories()`, Systemcheck |
| H-20 | Cron-Token: konstante Laufzeit, nicht geloggt | ✓ ⚠ | `Routen::cronToken()` (`hash_equals`), `Kernel` loggt nur `path`; ⚠ Token steht in der URL (Access-Log des Hosters) und `/cron` ist im Shim von der HTTPS-Umleitung ausgenommen – wie in Vereinsbelege akzeptiert, Folgen eines Lecks gering (nur Cron auslösen) |
| H-21 | Integritätscheck: alle Dateien von `current/`, Zusatzdateien, `web/` (Shim, `.htaccess`, `.user.ini`, Fremddateien), `config.php` (Rechte, nur Literale) | ✓ ⚠ | `Integritaet/CodeIntegritaet` (Phase `umgebung`); ⚠ ohne Signatur kann ein FTP-Angreifer die Liste mit anpassen → H-11 |
| H-22 | Keine Secrets im Repo und in der Historie | ✓ | `git log -p`-Suche: nur `docker/shared/config.php` (gekennzeichneter Dev-Schlüssel/-Passwort, nicht im Release-ZIP); gelöschte `tests/Fixtures/altdaten/*` sind erfundene Daten; keine Tokens/Schlüssel |
| H-23 | Abhängigkeiten minimal, vendored JS mit Prüfsumme | ✓ | `composer.json` (keine Laufzeit-Pakete), `public/js/vendor/README.md` + `VendorAssetsTest` |
| H-24 | Admin-Recht führt nicht zu fremdem Code (Update nur aus eigenem Repo, Restore nicht im Admin) | ✓ | `ReleaseDownloader::REPO`, `app/routes/update.php`, kein Restore unter `/admin` |

## I – CSP und Security-Header

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| I-01 | CSP exakt wie Spec (`default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`), kein `unsafe-inline`/`unsafe-eval`, kein externer Host | ✓ | `docker/web/.htaccess:16`; `tests/Http/CspComplianceTest.php` (`testPolicyKeepsTheDirectivesOfTheSpec`, `…RestrictsScriptsToSameOrigin`, `…AllowsNoInlineStylesEither`) |
| I-02 | HSTS | ✓ (`max-age=15768000`, ohne `includeSubDomains` – für eine Subdomain auf Shared Hosting vertretbar) | `docker/web/.htaccess:18` |
| I-03 | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, COOP `same-origin`, `Permissions-Policy` restriktiv | ✓ (alle mit `Header always`, gelten auch für Fehler- und Weiterleitungsantworten) | `docker/web/.htaccess:4–27` |
| I-04 | Kein Inline-JS, keine `on*`-Attribute, kein `hx-on`, keine Inline-Styles, keine externen Assets in Views; eigene Skripte ohne `eval`/`new Function` | ✓ | `CspComplianceTest`; manuell gegengeprüft (grep über `app/views`, `app/src`, `public/js`) – einzige Inline-`<style>` steht in `bin/setup.template.php:137` (siehe Hinweis H-1) |
| I-05 | htmx über `<meta name="htmx-config">`: `includeIndicatorStyles:false`, `selfRequestsOnly:true`, `historyCacheSize:0`, `allowEval:false`, `attributesToSettle` ohne `style` | ✓ | `app/views/layout.php:57` |
| I-06 | Vendored htmx mit Version/Lizenz/Prüfsumme | ✓ (2.0.10, SHA-256 in README, `VendorAssetsTest`) | `public/js/vendor/README.md` |
| I-07 | Header erreichen bestehende Installationen beim Update | ✓ mit Einschränkung | `ReleaseSwitcher::refreshDocrootFiles()` (ersetzt nur, wenn die Datei der Vorlage des Vorgänger-Release gleicht), aufgerufen in `UpdateService::finish()` Z. 326; Shim über `refreshShim()`. Einschränkungen: **I-B01**, **I-B02** |
| I-08 | HTTP→HTTPS-Umleitung | ✓ (Shim, nur GET/HEAD, nicht `/cron`, nicht localhost/IP/Fremdport, Notschalter `shared/https_umleitung_aus.flag`; `X-Forwarded-Proto` kann die Umleitung nur für die eigene Anfrage abschalten) | `docker/web/index.php` = `ReleaseSwitcher::SHIM` (Byte-Gleichheit per `ShimContentTest`) |
| I-09 | Header tatsächlich am Host vorhanden (Systemcheck/Selbsttest) | ✓ | `SystemCheck::securityHeaders()` und `userIniExposure()` (Einstellungen → System), Abruf über `CurlHeaderProbe` wie der Selbsttest des Updaters; `SystemCheckTest`, `SystemcheckSeiteTest` (#81) |
| I-10 | `Cache-Control: no-store` auf allen Seiten mit Mitgliederdaten (inkl. htmx-Partials, JSON der Schrittketten) | ✓ | Jede `/app`- und `/admin`-Route läuft durch `LoginGuard::check()` → `Session::start()` mit `cache_limiter=nocache` (`Cache-Control: no-store, no-cache, must-revalidate`, `Session::OPTIONEN`); `Response` setzt kein eigenes `Cache-Control`, überschreibt also nichts |
| I-11 | `no-store` auf Downloads: xlsx/csv-Exporte, Nachweis-Dateien, pain.008, Backup | ✓ | `ListenExport.php:44`, `NachweisController.php:133`, `EinzugController.php:179`, `BackupController.php:128` |
| I-12 | Back/Forward-Cache zeigt nach Abmelden keine Mitgliederseite | ✓ mit Härtung | `no-store` + neue Session-ID beim Abmelden (`Session::destroy()`) – siehe **I-B03** |
| I-13 | Session-Cookie `__Host-sid`, `httponly`, `secure`, `samesite=Lax` | ✓ | `Http/Session.php:65–80` |
| I-14 | Keine CORS-Header, Content-Type mit charset auf allen Antworten | ✓ | `Response::html/json`, `StaticFileHandler::MIME_TYPES`; kein `Access-Control-*` im Code |
| I-15 | Server gibt keine Versions-/Konfigurationsdetails preis | ✓ (#37) | `.htaccess`: `<Files ".user.ini">` gesperrt, `X-Powered-By` entfernt |

## J – Fehlerseiten, Logs und Datenschutz im Browser

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| J-01 | 500-Seite ohne Trace/Pfad, nur Referenz-ID; 404/403 ohne Details; Debug nur per `config.php` (Installer schreibt `false`, Installer-Kernel nie Debug) | ✓ | `Http/Kernel::errorResponse()`, `app/views/fehler.php`, `Installer/ConfigWriter.php:36`, `Anwendung.php:120`; `KernelFehlerTest` |
| J-02 | Fehler vor dem Kernel (Bootstrap) und beim Senden: eigene neutrale Seite, Log nur Klasse/Message/Methode/Pfad/Ort | ✓ | `public/index.php:46–96` |
| J-03 | `zend.exception_ignore_args=On`, `exception_string_param_max_len=0` (Bootstrap + `public/index.php` + `.user.ini`), Systemcheck prüft den effektiven Wert | ✓ | `app/src/bootstrap.php:26–29`, `docker/web/.user.ini`, `SystemCheck::exceptionIgnoreArgs()`, `ExceptionArgsTest` |
| J-04 | `display_errors=Off` | ✓ mit Härtung | nur `docker/web/.user.ini` – siehe **J-B05** |
| J-05 | Fehler-Log: nur Klasse, Message, Methode, Pfad (ohne Query), Ort; PDO-Meldungen (zitieren Zeilenwerte) unterdrückt | ✓ | `Kernel::meldung()`/`errorResponse()`; Pfad nicht URL-dekodiert (`Request.php:10`) → keine Zeilen-Injektion über `%0A` |
| J-06 | Alle übrigen Logger-Aufrufe ohne Mitglieder-/Kontodaten | ✓ | 17 Aufrufe geprüft (`CronRunner`, `ImportDateiAblage`, `Kontomails`, `Backup/Import/Update`-Controller, `ChunkUpload`, `NachweisController`, `MitgliedController`, `AnmeldungController`): nur Klasse, Blob-ID, Pfad |
| J-07 | Exception-Messages ohne fachliche Werte | ✓ | `Sepa/Pain008.php` (nur Feldbezeichnungen), Import/Einzug-Meldungen nur Mitgliedsnummern; `UpdateService::fehlertext()`, `BackupController`/`UpdateController` blenden PDO-/Migrations-Meldungen aus |
| J-08 | Mails ohne Mitgliederdaten; Sicherheitsmails (neues Gerät, Passwort, 2FA, Backup-Codes) nur feste Sätze; Betreffzeilen fest; SMTP-Fehler nur mit Code | ✓ | `app/views/mail/*.php`, `Kontomails.php:32–40, 79–150`, `SmtpTransport.php` |
| J-09 | Audit-Log ohne Mitgliederdaten (nur Mitgliedsnummer/IDs/Zähler), IP nur als HMAC | ✓ | `AuditLog::protokollieren()` Z. 56; alle 26 Aufrufe geprüft |
| J-10 | Audit-Log deckt die Spec-Ereignisse ab (02 §8: Anmeldungen, Benutzer- und Rollenänderungen …) | ✓ (#37) | siehe A-23 |
| J-11 | Mitgliedersuche: Suchtext nur im POST-Body, nie in URL/History (`hx-push-url` ohne Text) | ✓ | `MitgliederController` (`urlBauer()`, `formUrl()`), `MitgliederFilter::parameter()`, `mitglieder/bereich.php`, `bereich-link.php` |
| J-12 | Listen-Filter in der URL | ✓ (nur Auswahl/Jahr/Datum/Haken – `Listen/FilterArt.php`, kein Freitext) | `listen/liste.php:104` |
| J-13 | Familiensuche `?q=` in `/app/mitglieder/{id}/familie` | ✓ (#37) | Familiensuche per POST mit CSRF (`app/routes/familie.php`), `q` nur aus dem Body |
| J-14 | Download-Dateinamen ohne Personendaten | ✓ Listen-Exporte (`*::dateiname()`), pain.008 (`lastschrift-<jahr>-<id>.xml`); ✗ Nachweis-Dateien | **J-B03** |
| J-15 | Seitentitel (Browser-History) ohne Namen | ✓ | „Mitglieder", „Mitglied bearbeiten" (`MitgliedController.php:188`) |
| J-16 | Einmal-Tokens (Reset/Einladung) verlassen sofort die URL | ✓ | `AnmeldungController::tokenAusLink()` (Token in Session, 303 auf nackten Pfad) |
| J-17 | Keine Mitgliederdaten in localStorage/sessionStorage/IndexedDB/Service Worker/Cache API | ✓ | grep über `public/js/*.js`: keine Treffer; kein Service Worker |
| J-18 | htmx-History-Cache | ✓ | htmx 2.0.10 speichert Snapshots in `sessionStorage` („htmx-history-cache"); bei `historyCacheSize:0` löscht es den Schlüssel und speichert nichts (`zt()` in `htmx.min.js`). Back-Navigation lädt per `HX-History-Restore-Request` neu, `MitgliederController::seite()` liefert dann die ganze Seite. Kein `hx-history`-Attribut nötig |
| J-19 | Flash-Meldungen (liegen in der Session-Datei) ohne Mitgliederdaten | ✓ mit Ausnahme | Ausnahme Familienname, **J-B04** |
| J-20 | `autocomplete` an sensiblen Feldern | ✓ | Mitgliederformular (`mitglied/formular.php:54`, IBAN Z. 174 `off`), Suche `off`, Passwörter `current-/new-password`, Codes `one-time-code` |

## K – Karte und externe Dienste (#91)

Geprüft beim Bau der Seite „Karte" (2026-10-30), Abschnitte C, E, I und J
für die neuen Routen erneut durchgegangen.

| ID | Prüfung | Ergebnis | Fundstelle |
|---|---|---|---|
| K-01 | Neue Routen deklariert und getestet: Seite und Kacheln `mitglieder.sehen`, „Jetzt ermitteln" `admin.system` (POST mit CSRF) | ✓ | `app/routes/karte.php`, `tests/Http/routen-zugriff/karte.txt`, `KarteSeiteTest` |
| K-02 | Browser spricht keinen fremden Host an; CSP unverändert | ✓ | Kacheln über `/app/karte/kachel/…` (`KachelProxy`); Leaflet vendored mit Prüfsummen (`VendorAssetsTest` prüft jetzt auch `.css`/`.png`); einziger externer Verweis ist der Link „OpenStreetMap" im Quellenhinweis (nur bei Klick) |
| K-03 | An den Geocoder geht nur die Anschrift, nie Name oder Mitgliedsnummer | ✓ | `Geocoder::url()`; `KoordinatenermittlungTest::testNoNameAndNoMemberNumberLeavesTheServer` |
| K-04 | Keine Mitgliederdaten in Log, Cron-Antwort oder Kachel-Cache | ✓ | Log nur „HTTP 429"/„keine Antwort" (`Koordinatenermittlung`); Cron-Ergebnis nur Zähler; Cache-Dateinamen `z/x/y.png`; `geo_anschrift` nur Hash + Koordinaten |
| K-05 | Kein SSRF: Ziel-URLs fest, Parameter nur Zahlen bzw. URL-kodierte Felder, nur HTTPS, keine Weiterleitungen | ✓ | `KachelProxy::URL` + `gueltig()`, `Geocoder::URL` + `http_build_query`, `CurlHttpAbruf` (`CURLPROTO_HTTPS`, `FOLLOWLOCATION` aus, Größe begrenzt) |
| K-06 | Kein Massenabruf beim Kachelserver über die Route | ✓ mit Restrisiko ⚠ | nur angemeldet, Zoom 5–18, Cache 7 Tage; ein angemeldeter Benutzer könnte viele Kacheln anfordern – bei zwei festen Rollen und echten Vereinskonten akzeptiert |
| K-07 | Ausfall des Dienstes ist ein Hinweis, kein Absturz | ✓ | Geocoder: Pause 15 min, Hinweis auf der Seite; Kacheln: alte Kopie oder 503 (`KarteSeiteTest::testATileServerFailureIsAHintNotACrash`) |
| K-08 | `Cache-Control` | ✓ | Seite `no-store` wie alle (I-10); nur die Kachel-Antwort setzt `private, max-age=86400` – Kartenbild, keine Mitgliederdaten |
| K-09 | Popups ohne HTML aus Daten | ✓ | `karte.js` klont die serverseitig escapte Tabellenzelle, Zahl/Anschrift als `textContent` |

## Befunde des Durchlaufs 2026-10-29

Kein Befund war „kritisch" oder „hoch". Behoben heißt: im PR zu #37 mit
Test.

| Nr. | Schwere | Kurz | Stand |
|---|---|---|---|
| A-23 / C-F1 / J-B01 | mittel | Audit-Log ohne Benutzer-/Rollenänderungen, Passwort-Fehlschläge, Passwortwechsel/-Reset, Abmeldung | ✓ behoben |
| A-05 | mittel | Kontozähler (Anmeldung, Passwort vergessen) per Akzent-Variante der Adresse umgehbar; IPv6 je Adresse | ✓ behoben |
| H-11 | mittel | Release-Signatur nicht aktiv (`ReleaseSignatur::PUBLIC_KEY` leer) | ✓ behoben (bb57f6f, ab v0.1.3) |
| A-17 | niedrig | Reset-/Einladungslinks und Codes im Klartext in `mail_queue.body` | ✓ behoben |
| C-F2 / H-04 | niedrig | Download von Lastschriftdatei und Backup nicht protokolliert | ✓ behoben |
| C-F3 | niedrig | „Ehrung vermerken" nur mit `listen.sehen` | ✓ behoben |
| D-23 / J-B03 | niedrig | Nachweis-Download mit Originaldateinamen | ✓ behoben |
| D-24 | niedrig | Import-CSV ohne Zeilengrenze (Speicher, Einstellungsseite unbedienbar) | ✓ behoben |
| E-22 | niedrig | CSV-Export ohne Formelschutz | ✓ behoben |
| J-B02 | niedrig | Familiensuche mit Namen in der URL | ✓ behoben |
| H-07 / D-25 | niedrig | Klartext-Upload-Chunks ohne Aufräumen | ✓ behoben (Cron) |
| I-B02 | niedrig | `.htaccess`/`.user.ini` nach fehlgeschlagenem Selbsttest nicht zurückgestellt | ✓ behoben |
| A-19 | niedrig | „Passwort vergessen" verrät über die Laufzeit (sofortiger SMTP-Versand), ob ein Konto existiert | ⚠ akzeptiert: wenige Konten, Rate-Limit je IP und Konto; Versand über den Cron hieße bis zu 10 min Wartezeit auf den Link |
| H-12 | niedrig | Actions im Release-Workflow nicht per Commit-SHA gepinnt | ✓ behoben (#79) |
| F-11 | niedrig | Server-Schlüssel nicht wechselbar | ✓ behoben (#80) |
| F-12 | niedrig | Audit-Hash-Kette ungeschlüsselt (DB-Schreiber kann sie neu rechnen) | ⚠ akzeptiert: DB-Zugriff ist ohnehin Vollzugriff (E-11); Folge-Issue möglich |
| I-B01 | niedrig | niemand prüft, ob die Security-Header am Host ankommen | erledigt (#81): Systemcheck-Zeile „Sicherheits-Header am Server“ |
| C-F4 | Härtung | Doku zu `Erweiterung::recht()` | ✓ behoben |
| E-21 | Härtung | Markdown-Links `//host` | ✓ behoben |
| E-23 | Härtung | Import prüft Länge von `bemerkung` nicht | ✓ behoben |
| J-B04 | Härtung | Familienname in Flash-Meldung | ✓ behoben |
| I-B04 | Härtung | `.user.ini` abrufbar, `X-Powered-By` | ✓ behoben |
| J-B05 | Härtung | `display_errors` nur in `.user.ini` | ✓ behoben (Bootstrap) |
| F-07, H-14, H-15, I-B03, B-13 | Härtung | Schlüsseltrennung Audit-HMAC, Update-Schrittfolge serverseitig, übrig gebliebene `setup.php`, `Clear-Site-Data`, „überall abmelden" | offen, optional |

## Release-Signatur (H-11)

Eingeschaltet seit Commit bb57f6f, erstes signiertes Release v0.1.3. Der
Release-Workflow signiert `checksums.txt` mit dem Secret
`RELEASE_SIGNING_KEY`; Updater und `setup.php` prüfen gegen
`ReleaseSignatur::PUBLIC_KEY` und nehmen ein Release ohne gültige Signatur
nicht an. Der geheime Schlüssel liegt nur als GitHub-Secret und beim
Maintainer, nie im Repository.

- **Schlüssel verloren oder kompromittiert:** neues Paar mit
  `tools/release-signatur.php schluessel <datei>` erzeugen, Secret ersetzen,
  neuen öffentlichen Schlüssel in `ReleaseSignatur::PUBLIC_KEY` eintragen.
  Eine Installation mit dem alten Schlüssel nimmt das nächste Release dann
  nicht mehr an – sie braucht einmal `setup.php` des neuen Releases (oder
  das ZIP per FTP), danach läuft der Updater wieder.
- Nächster Schritt im Betrieb: ein geprüftes Release auf GitHub als
  „latest" markieren und die Installation auf den Kanal `stable` stellen.
