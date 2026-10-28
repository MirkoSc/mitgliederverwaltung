# 03 – Datenhaltung

## 1. Konventionen

- MariaDB, utf8mb4, InnoDB, Fremdschlüssel wo sinnvoll.
- `id` BIGINT UNSIGNED AUTO_INCREMENT (Ausnahme `audit_log`, 02 §8).
- **Klartext** (E-11): Mitgliederdaten stehen als normale Spalten in der
  Datenbank; Suchen, Filtern und Sortieren laufen in SQL.
- Verschlüsselt (Server-Schlüssel, 02 §2) sind nur: Dateien (Blobs),
  Backups, TOTP-Geheimnisse, SMTP-Passwort. Spalten dafür heißen `*_enc`.
- Datumsfelder als `DATE`, Zeitpunkte als `DATETIME` in lokaler Zeit
  (`Europe/Berlin`).
- Geld: Integer-Cent (`*_cent`), nie `float`.
- Die Tabellen in den Specs sind ein Entwurf; die jeweilige Migration darf
  Details anpassen, muss die Spec dann im selben PR nachziehen.

## 2. Technische Tabellen (übernommen aus Vereinsbelege, ohne Tresor)

| Tabelle | Spalten (Auszug) |
|---|---|
| `schema_version` | version, applied_at |
| `setting` | key, value (JSON) |
| `user` | email UNIQUE, name, password_hash, status (`eingeladen`/`aktiv`/`gesperrt`), rolle (`admin`/`vorstand`), modus_barrierefrei, mfa_method NULL, session_epoch, created_at, last_login_at |
| `mfa_totp` | user_id, secret_enc, confirmed_at, last_step NULL, created_at |
| `mfa_email_code` | user_id, code_hash, expires_at, attempts, created_at |
| `mfa_backup_code` | id, user_id, code_hash, used_at NULL, created_at |
| `trusted_device` | id, user_id, token_hash UNIQUE, label, created_at, last_used_at, expires_at |
| `auth_token` | id, user_id, typ (`reset`/`invite`), token_hash UNIQUE, expires_at, used_at NULL, created_at |
| `rate_limit` | key_hash PK, window_start, count |
| `audit_log` | id, ts, user_id NULL (kein FK), action, entity NULL, entity_id NULL, ip_hash NULL, details (JSON), prev_hash, hash |
| `mail_queue` | id, to_address, subject, body, status, attempts, next_try_at, created_at |
| `job` | id, typ, status, user_id, state (JSON), progress, locked_until, created_at, updated_at |
| `file_blob` | id, storage (`fs`/`db`), key_enc, header, size, cipher_sha256, mime, created_at |
| `file_blob_chunk` | blob_id, seq, data |

Entfallen gegenüber Vereinsbelege: `vault`, `vault_grant`, `user_key`,
`role`/`user_role` (feste Rollen), Blind Indexes, `dek_sealed`.

## 3. Blobs

- Inhalt verschlüsselt (02 §2), Formate wie Vereinsbelege: Kopf
  `Version | secretstream-Header`, Blöcke à 64 KiB, letzter mit
  `TAG_FINAL`, SHA-256 über das Chiffrat.
- Backend `fs` (Default, `shared/var/blobs/<zufällige-ID>`) oder `db`.
- Dateinamen sind zufällige IDs; Originalname und MIME-Typ stehen in der
  Datenbank.
- Uploads in Chunks (2 MiB).
- Das Vereinslogo ist **kein** Blob: es ist öffentlich und liegt
  unverschlüsselt in `shared/var/logo/` (11 §2).

## 4. Migrationen

- `migrations/NNN_name.sql`, fortlaufend, nie nachträglich ändern.
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
