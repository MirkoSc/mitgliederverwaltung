# 03 – Datenhaltung

## 1. Konventionen

- MariaDB, utf8mb4, InnoDB, Fremdschlüssel wo sinnvoll.
- `id` BIGINT UNSIGNED AUTO_INCREMENT (Ausnahme `audit_log`, 02 §8).
- **Klartext** (E-11): Mitgliederdaten stehen als normale Spalten in der
  Datenbank; Suchen, Filtern und Sortieren laufen in SQL.
- Verschlüsselt (Server-Schlüssel, 02 §2) sind nur: Dateien (Blobs),
  Backups, TOTP-Geheimnisse, SMTP-Passwort. Spalten dafür heißen `*_enc`.
  Eine neue `*_enc`-Spalte muss in den Schlüsselwechsel (02 §2,
  `SchluesselwechselRepository`) aufgenommen werden.
- Datumsfelder als `DATE`, Zeitpunkte als `DATETIME` in lokaler Zeit
  (`Europe/Berlin`).
- Geld: Integer-Cent (`*_cent`), nie `float`.
- Die Tabellen in den Specs sind ein Entwurf; die jeweilige Migration darf
  Details anpassen, muss die Spec dann im selben PR nachziehen.

## 2. Technische Tabellen (übernommen aus Vereinsbelege, ohne Tresor)

| Tabelle | Spalten (Auszug) |
|---|---|
| `schema_version` | version, applied_at |
| `setting` | name PK (nicht `key`: reserviertes Wort), value (JSON), updated_at |
| `user` | email UNIQUE, name, password_hash, status (`eingeladen`/`aktiv`/`gesperrt`), rolle (`admin`/`vorstand`), modus_barrierefrei, mfa_method NULL, session_epoch, created_at, last_login_at |
| `mfa_totp` | user_id, secret_enc, last_step NULL, created_at (erst nach dem ersten richtigen Code geschrieben) |
| `mfa_email_code` | user_id, code_hash, expires_at, attempts, created_at |
| `mfa_backup_code` | id, user_id, code_hash, used_at NULL, created_at |
| `trusted_device` | id, user_id, token_hash UNIQUE, created_at, expires_at |
| `auth_token` | id, user_id, typ (`reset`/`invite`), token_hash UNIQUE, expires_at, used_at NULL, created_at |
| `rate_limit` | key_hash PK, window_start, count |
| `audit_log` | id, ts, user_id NULL (kein FK), action, entity NULL, entity_id NULL (Text), ip_hash NULL, details (JSON), prev_hash, hash |
| `mail_queue` | id, to_address, subject, body, status, attempts, next_try_at, created_at |
| `job` | id, typ, status (`offen`/`laeuft`/`fertig`/`fehler`), user_id NULL, state (JSON), progress (0–100), last_error NULL (nur Exception-Klasse), locked_until, created_at, updated_at |
| `file_blob` | id, storage (`fs`/`db`), fs_name NULL, size, cipher_sha256 NULL, key_enc, header, mime, original_name, created_at |
| `file_blob_chunk` | blob_id, seq, data |
| `geo_anschrift` | anschrift_hash PK (SHA-256 der normalisierten Anschrift, `App\Service\Karte\Anschrift`), status NULL (`gefunden`/`nicht_gefunden`; NULL = noch nicht gefragt), lat NULL, lon NULL, geprueft_am, such_strasse NULL, such_plz NULL, such_ort NULL – Koordinaten für die Karte (#91), je Anschrift statt je Mitglied, ohne Namen; Anschriftstext steht nur in den `such_*`-Spalten, wenn jemand am Mitglied eine Such-Anschrift nur für die Karte angegeben hat (#106, Migration 193: der Cron fragt dann mit ihr). Zeilen, die kein aktuelles Mitglied mehr nutzt, entfernt die nächste Ermittlung – mit ihrer Such-Anschrift |

Entfallen gegenüber Vereinsbelege: `vault`, `vault_grant`, `user_key`,
`role`/`user_role` (feste Rollen), Blind Indexes, `dek_sealed`. Die
`*_hash`-Spalten des zweiten Faktors sind HMAC-SHA256 unter einem aus dem
Server-Schlüssel abgeleiteten Schlüssel (`ServerCrypto::hmac()`), gesalzen
mit der Benutzer-ID.

## 3. Blobs

- Inhalt verschlüsselt (02 §2), Formate wie Vereinsbelege: Kopf
  `Version | secretstream-Header`, Blöcke à 64 KiB, letzter mit
  `TAG_FINAL`, SHA-256 über das Chiffrat.
- Backend `fs` (Default, `shared/var/blobs/<zufällige-ID>`) oder `db`.
- Dateinamen sind zufällige IDs (`file_blob.fs_name`); Originalname
  (`original_name`, ohne Pfad) und MIME-Typ (`mime`) stehen im Klartext in
  der Datenbank. `key_enc` ist der Dateischlüssel als `secretbox` unter dem
  Server-Schlüssel (73 Byte), `header` Versionsbyte + secretstream-Header.
- Eine Zeile ohne `cipher_sha256` ist ein abgebrochener Schreibvorgang (Entwurf)
  und nicht lesbar. `BlobService::verify()` prüft die Prüfsumme ohne Schlüssel.
- Backend `db`: das Chiffrat in Zeilen à 256 KiB in `file_blob_chunk`; beide
  Backends speichern denselben Byte-Strom. Die Backend-Wahl steht im Setting
  `speicher_backend` (Default `fs`); das Umschalten per Schrittkette gehört
  zur Speicher-Verwaltung und ist noch nicht gebaut.
- Gelesen wird immer als Strom (`BlobService::openRead()`, Stücke à 64 KiB);
  ein abgeschnittener, umsortierter oder mit falschem Schlüssel gelesener
  Strom wirft `CryptoException`, nie eine kürzere Datei.
- Uploads in Chunks (2 MiB): `App\Service\Upload\UploadService` sammelt die
  Chunks unter `shared/var/tmp/upload/<ID>/` (Klartext, deshalb nur dort und
  vom Cron nach 24 h aufgeräumt: `UploadService::cleanup()`), `App\Http\ChunkUpload::registrieren()` legt die vier Routen (`POST {basis}`,
  `…/{id}/chunk/{n}`, `…/finish`, `…/abort`) mit dem Zugriff des Bereichs an,
  `public/js/upload.js` ist die Browser-Seite. Der Bereich entscheidet im
  Abschluss-Closure, was mit der fertigen Datei geschieht (typisch
  `BlobService::store()`); der Klartext wird dabei nur im Strom gelesen, nie
  als Ganzes im Speicher oder als Datei abgelegt.
- Das Vereinslogo ist **kein** Blob: es ist öffentlich und liegt
  unverschlüsselt in `shared/var/logo/` (11 §2).

## 4. Migrationen

- `migrations/NNN_name.sql`, nie nachträglich ändern. Nummern gehören
  dem Issue, das sie im Arbeitsplan zugewiesen bekommt; Lücken sind normal.
- Der Runner (`App\Service\Migration\Migrator`) wendet **jede noch nicht
  eingetragene** Migration an, aufsteigend – nicht nur die über der
  höchsten. Parallele Issues werden in beliebiger Reihenfolge gemergt; eine
  Datenbank mit 040 bekommt 015 trotzdem, wenn es später kommt.
- **Eine Version rückwärtskompatibel**: hinzufügen ja; umbenennen/löschen
  erst in der Folgeversion (Rollback läuft mit altem Code auf neuem
  Schema).
- Ausgeführt vom Installer (alle ab 0), vom Updater (Schritt `migrate`)
  und lokal per `bin/migrate.php`.
- Seeds (Abteilungen, Beitragsarten, Funktionen, Default-Settings) als
  Migration.

## Pflicht-Tests

Migrationslauf von 0 auf einer leeren DB; Repository-Tests gegen die
Docker-MariaDB; Blob-Rundlauf in beiden Backends inkl. abgeschnittener/
vertauschter Chunks; TOTP-Geheimnis und SMTP-Passwort stehen nicht im
Klartext in der DB.
