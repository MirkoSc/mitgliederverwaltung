# 03 – Datenhaltung

## 1. Konventionen

- `id` BIGINT UNSIGNED AUTO_INCREMENT (Ausnahme `audit_log`, 02 §8).
- `*_enc` = verschlüsselt (VARBINARY/BLOB), `*_bi` = Blind Index
  (BINARY(32), indiziert), `dek_sealed` = versiegelter Datenschlüssel der
  Zeile.
- Klassifizierung je Spalte in den Tabellen dieser Datei:
  `T` = Tresor (nur in entsperrter Session lesbar), `S` = Server-Schlüssel,
  `–` = Klartext.
- Utf8mb4, InnoDB, Fremdschlüssel wo sinnvoll, `created_at`/`updated_at`
  als DATETIME (lokale Zeit `Europe/Berlin`).
- Geld: Integer-Cent (bei Tresor-Spalten innerhalb des verschlüsselten
  Werts), Währung separat, nie `float`.
- Die Tabellen sind ein Entwurf; die jeweilige Migration darf Details
  anpassen, muss dann aber diese Datei im selben PR nachziehen.

## 2. Regel für fachliche Tabellen

Jede fachliche Tabelle (kommt mit der fachlichen Spec) folgt diesem Muster:

| Spalte | Klasse | Zweck |
|---|---|---|
| `id`, Fremdschlüssel | – | Struktur |
| `status`, technische Zeitstempel | – | Filtern/Sortieren in SQL |
| `dek_sealed` | – (versiegelt) | Datenschlüssel der Zeile |
| `<feld>_enc` | T | jeder personenbezogene oder fachliche Wert |
| `<feld>_bi` | – (HMAC) | Suche/Duplikaterkennung auf `<feld>` |

- **Default ist verschlüsselt.** Ein fachliches Feld bleibt nur Klartext,
  wenn die Spec begründet, warum SQL es braucht und warum der Klartext für
  einen DB-Leser unkritisch ist. Beispiele für die Abwägung: Datum des
  Eintritts (Filter) vs. Geburtsdatum (personenbezogen → verschlüsselt,
  Altersfilter rechnet PHP).
- Sortieren und Filtern auf verschlüsselten Feldern rechnet PHP nach dem
  Entschlüsseln – bei Vereinsmengen (wenige Tausend Datensätze) unkritisch;
  größere Listen werden seitenweise über Klartext-Strukturfelder geladen.
- Volltextsuche über verschlüsselte Werte: kein dauerhafter Index;
  bei Bedarf ein verschlüsselter Session-Index wie in Vereinsbelege.

## 3. Technische Tabellen (übernommen aus Vereinsbelege)

| Tabelle | Spalten (Auszug) | Verschl. |
|---|---|---|
| `schema_version` | version, applied_at | – |
| `setting` | key, value (JSON) | – (sensible Werte als `*_enc` mit S) |
| `user` | email_enc, email_bi UNIQUE, display_name_enc, password_hash, status (`eingeladen`/`aktiv`/`gesperrt`), rolle (`admin`/`vorstand`), modus_barrierefrei, expires_at NULL, mfa_required, mfa_method NULL, session_epoch, created_at, last_login_at | S |
| `user_key` | user_id, public_key, wrapped_private_key, kdf_salt, kdf_ops, kdf_mem, kdf_alg | – (selbst gewrappt) |
| `vault` | version, public_key, public_key_mac, created_at, recovery_confirmed_at, retired_at | – (MAC: S) |
| `vault_grant` | user_id, vault_version, sealed_private_key, granted_by, granted_at | – (versiegelt) |
| `mfa_totp` | user_id, secret_enc, confirmed_at, last_step NULL, created_at | S |
| `mfa_email_code` | user_id, code_hash, expires_at, attempts, created_at | – |
| `mfa_backup_code` | id, user_id, code_hash, used_at NULL, created_at | – |
| `trusted_device` | id, user_id, token_hash UNIQUE, label, created_at, last_used_at, expires_at | – |
| `auth_token` | id, user_id, typ (`reset`/`invite`), token_hash UNIQUE, expires_at, used_at NULL, created_at | – |
| `rate_limit` | key_hash PK, window_start, count | – |
| `audit_log` | id, ts, user_id NULL (kein FK), action, entity NULL, entity_id NULL, ip_hash NULL, details_enc NULL, dek_sealed NULL, prev_hash, hash | T (details) |
| `mail_queue` | id, to_enc, subject_enc, body_enc, status, attempts, next_try_at, created_at | S |
| `job` | id, typ, status, user_id, state_enc / state_json, progress, locked_until, created_at, updated_at | T bzw. – |
| `file_blob` | id, storage (`fs`/`db`), header, size, cipher_sha256, dek_sealed, created_at | – (Inhalt T) |
| `file_blob_chunk` | blob_id, seq, data | T |

Welche dieser Tabellen schon in der ersten Ausbaustufe nötig sind
(`file_blob`, `job`), entscheidet das jeweilige Issue.

## 4. Blobs

- Formate wie Vereinsbelege: Kopf `Version | secretstream-Header`, Inhalt
  in Blöcken à 64 KiB Klartext, letzter mit `TAG_FINAL`, SHA-256 über das
  Chiffrat.
- Backend `fs` (Default, `shared/var/blobs/<zufällige-ID>`) oder `db`;
  Umstellung per Schrittkette in beide Richtungen.
- Dateinamen sind zufällige IDs; der Originalname liegt – falls gebraucht –
  verschlüsselt in der fachlichen Tabelle.
- Uploads in Chunks (2 MiB), Zwischenablage nur als Chiffrat.

## 5. Migrationen

- `migrations/NNN_name.sql`, fortlaufend, nie nachträglich ändern.
- **Eine Version rückwärtskompatibel**: hinzufügen ja; umbenennen/löschen
  erst in der Folgeversion (Rollback läuft mit altem Code auf neuem Schema).
- Ausgeführt vom Installer (alle ab 0), vom Updater (Schritt `migrate`) und
  lokal per `bin/migrate.php`.
- Seeds (Systemrollen, Default-Settings) als Migration.
- Migrationen enthalten nie fachliche Klartextdaten (sie können ja nicht
  verschlüsseln); Datenumbauten an Tresor-Spalten sind Schrittketten in
  einer Session.

## Pflicht-Tests

Migrationslauf von 0 auf einer leeren DB; jede Migration einmal
anwendbar; Repository-Tests gegen die Docker-MariaDB; Blob-Rundlauf in
beiden Backends inkl. abgeschnittener/vertauschter Chunks; kein
`*_enc`-Wert ist ohne Tresor-Schlüssel lesbar.
