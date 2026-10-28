# Hosting-Befunde

Übernommen aus `MirkoSc/vereinsbelege` (`docs/hosting-befunde.md`): beide
Anwendungen laufen beim selben Hoster, die Messung gilt deshalb auch hier.
Die verlinkten Folge-Issues gehören zu Vereinsbelege; ihre Lösungen sind
Teil dessen, was übernommen wird (05 §3).

Eine neue Messung (neuer Hoster, neuer Tarif, neue PHP-Version) läuft mit
`tools/hosting-check.php` aus Vereinsbelege; die Aufrufe stehen dort.
Rohausgaben werden nie eingecheckt (Kundenkennung, Pfade).

## Befunde

Gemessen am 21.09.2026 auf der Test-Subdomain beim Hoster (Shared Hosting,
PHP-FPM) für Vereinsbelege. Die Rohausgaben sind bewusst **nicht** eingecheckt: sie enthalten
Kundenkennung, Mail-Benutzernamen und absolute Pfade.

Legende: ✅ Annahme bestätigt · ⬆️ besser als angenommen · ⚠️ Abweichung mit
Folge-Issue · 📋 nur dokumentiert

| Prüfpunkt | Annahme in den Specs | Befund | Folge-Issue |
|---|---|---|---|
| PHP-Version | 8.5 | ✅ 8.5.9, SAPI `fpm-fcgi`, 64-bit | |
| `memory_limit` | ≥ 256M | ⬆️ 384 MiB | |
| `max_execution_time` | 30 s | ⬆️ 60 s (lokal bleibt 30 s, also strenger) | |
| Request-Abbruch bei langem Warten | > 90 s | ⬆️ kein Abbruch bei 180 s (`set_time_limit(0)` wird angenommen); gemessen wird die Wanduhr-Grenze des Webservers, `sleep`/Netz-I/O zählen ohnehin nicht auf `max_execution_time` | |
| `upload_max_filesize` / `post_max_size` | egal (Chunk-Upload 2 MiB) | ⬆️ je 384 MiB | |
| `max_input_time` | ≥ 30 s | ⬆️ unbegrenzt | |
| `disable_functions` | exec & Co. gesperrt | ⚠️ leer – exec wäre technisch möglich; Verzicht bleibt Selbstbeschränkung | [#102](https://github.com/MirkoSc/vereinsbelege/issues/102) |
| `open_basedir` | nicht gesetzt | ✅ nicht gesetzt | |
| `zend.exception_ignore_args` | On | ⚠️ 0 – zur Laufzeit per `ini_set` setzbar (geprüft) | [#97](https://github.com/MirkoSc/vereinsbelege/issues/97) |
| `set_time_limit()` wirkt | ja | ✅ ja | |
| Standard-Zeitzone | egal | 📋 schon `Europe/Berlin` | |
| OPcache | egal | 📋 vorhanden – `opcache_reset()` nach Release-Umschaltung beachten | |
| ext-sodium + pwhash INTERACTIVE | vorhanden, < 1 s | ✅ libsodium 1.0.18, INTERACTIVE 52–56 ms | |
| pwhash MODERATE | nicht vorausgesetzt | ⬆️ 309–321 ms – MODERATE ist bezahlbar und seit #100 das Profil der Tresor-Umhüllung (01 §2); `memory_limit` zählt die 256 MiB nicht mit (libsodium belegt sie selbst) | [#100](https://github.com/MirkoSc/vereinsbelege/issues/100) |
| Anmeldung mit MODERATE (Gesamtdauer, mehrere gleichzeitig) | (seit #100) | 📋 noch nicht gemessen – beim nächsten Besuch auf dem Webspace eine echte Anmeldung stoppen (Erwartung ≈ 0,5 s) und 3–4 Anmeldungen gleichzeitig auslösen (je 256 MiB für ~0,3 s außerhalb von `memory_limit`) | [#100](https://github.com/MirkoSc/vereinsbelege/issues/100) |
| `PASSWORD_ARGON2ID` | vorhanden | ✅ funktioniert, 166–170 ms | |
| Sealed Box, secretstream, `random_bytes` | funktionieren | ✅ Rundläufe erfolgreich – Tresor-Modell und Blob-Verschlüsselung tragen | |
| ext-gd (JPEG/PNG) | vorhanden | ✅ GD 2.3.0, JPEG/PNG/WebP (ohne AVIF) | |
| ext-imagick | nicht vorausgesetzt | 📋 vorhanden; ob es PDFs rastern kann, ist offen | [#103](https://github.com/MirkoSc/vereinsbelege/issues/103) |
| ext-zip, curl, openssl, mbstring, fileinfo, intl, iconv, pdo_mysql, json | vorhanden | ✅ alle vorhanden (zusätzlich zlib, exif) | |
| ext-dom, ext-xmlreader | vorhanden | 📋 noch nicht gemessen – beim nächsten Lauf von `tools/hosting-check.php` bestätigen (Standard-Erweiterungen von PHP) | |
| MariaDB-Version | ≥ 10.5 | ✅ 10.6.23 | |
| `max_allowed_packet` | ≥ 4 MiB | ⬆️ 64 MiB | |
| Zeichensatz, Standard-Engine | utf8mb4, InnoDB | ✅ utf8mb4, InnoDB | |
| Tabelle anlegen, JSON-Spalte, LONGBLOB | funktionieren | ✅ alle drei – Installer und beide Blob-Backends möglich | |
| `wait_timeout` | (nicht betrachtet) | ⚠️ **120 s** statt 28800 – untätige Verbindung stirbt während eines langen KI-Aufrufs | [#98](https://github.com/MirkoSc/vereinsbelege/issues/98) |
| DB-Größenlimit im Tarif | | 📋 kein hartes Limit, praktisch ~10 GB; Default bleibt Dateisystem (E-06) | |
| Streaming-Ausgabe 60 s | funktioniert | ✅ 199,8 MiB in 61 s, HTTP 200, `DONE` angekommen (~3,3 MiB/s) | |
| SMTP (Host/Port/TLS) | 465/587 | ✅ Hoster-SMTP, Port 465 implizit, `AUTH LOGIN` erfolgreich, Zertifikat gültig, `SIZE` ≈ 146 MiB | |
| Zustellung einer Testmail | funktioniert | ✅ Testmail über den Hoster-SMTP zugestellt, Absender akzeptiert | |
| Minimales Cron-Intervall | 5–15 min | ⬆️ **1 min** – Läufe können überlappen, Aufräumen braucht Drosselung → Sperre + Drosselung (01 §5) | [#99](https://github.com/MirkoSc/vereinsbelege/issues/99) |
| `rename()` von Verzeichnissen | funktioniert | ✅ vorwärts und zurück, dazu `mkdir`, `flock`, `symlink` | |
| Schreibrecht über dem DocumentRoot | vorhanden | ✅ DocumentRoot auf `<bereich>/web/` gelegt, `shared/` daneben beschreibbar, 361 GiB frei | |
| `shared/` öffentlich erreichbar? | nein | ✅ Testdatei über die Hauptdomain nicht abrufbar; Vorsorge per `.htaccess` trotzdem sinnvoll | [#101](https://github.com/MirkoSc/vereinsbelege/issues/101) |
| `.htaccess` wird ausgewertet | vorausgesetzt (Security-Header, CSP) | ✅ Datei vorher HTTP 200, nach der Sperre HTTP 403 | |
| KI-Server vom Webspace erreichbar | ja (TLS + API-Key) | ✅ `api.openai.com` und `api.anthropic.com` antworten mit 401 in 175–450 ms, Zertifikate gültig; `api.github.com` 200 (Updater) | |

## Fazit für die Mitgliederverwaltung

Alle Annahmen des technischen Rahmens tragen. Zu beachten:
`wait_timeout = 120` (keine DB-Verbindung über lange Netzwerkaufrufe offen
halten) und `zend.exception_ignore_args = 0` (per `.user.ini` und
`ini_set` überschreiben). Spalten zu KI-Servern und Streaming betreffen nur
Vereinsbelege.
