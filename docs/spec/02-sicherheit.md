# 02 – Sicherheit: Verschlüsselung, Anmeldung, Rechte

Vorlage ist Vereinsbelege (`docs/spec/01-sicherheit.md` dort). Mechanik,
Speicherformate und Klassen werden **übernommen**; was dort mit
Belegen/Lieferanten zu tun hat, entfällt. Bei Zweifeln an einem Detail gilt
die Vereinsbelege-Spec als Referenz, bis diese Datei es anders regelt.

## 1. Bedrohungsmodell

Mitgliederdaten sind personenbezogene Daten (Namen, Anschriften,
Geburtsdaten, Bankverbindungen, ggf. Daten von Minderjährigen). Schutzziel:
Wer Zugriff auf Datenbank, FTP oder Backups bekommt, kann sie nicht lesen.

| Angreifer hat … | Schutzziel | Erreicht durch |
|---|---|---|
| nur DB-Zugriff (DB-Dump, Backup-Datei, phpMyAdmin-Leck) | Mitgliederdaten nicht lesbar | Tresor-Verschlüsselung; Server-Schlüssel nicht in der DB |
| nur Lesezugriff aufs FTP (inkl. `config.php`, Blobs, Backups) | Mitgliederdaten nicht lesbar | Tresor-Private-Key liegt nirgends im Klartext |
| FTP + DB lesend | Mitgliederdaten nicht lesbar | wie oben; lesbar nur Betriebsdaten (Benutzer-Mails, SMTP-Passwort) |
| Schreibzugriff aufs FTP (Code manipulieren) | **nicht vollständig schützbar** | Release-Signatur, Integritätscheck im Admin, starkes FTP-Passwort |
| gestohlenes Passwort eines Benutzers | Login verhindern | 2FA, Rate-Limit, Mail bei neuem Gerät |
| Fremder findet die Installation vor dem Verein | Übernahme verhindern | Installations-Token (04 §2) |

Geprüft wird wiederholbar mit einer Security-Checkliste nach OWASP ASVS
Level 2 (wie in Vereinsbelege), vor dem Produktivbetrieb und bei größeren
Änderungen.

## 2. Schlüsselhierarchie (libsodium, `ext-sodium`)

```
Wiederherstellungsschlüssel (Papier, beim Verein)
          │ (enthält)
Tresor-Schlüsselpaar  VK_pub (Klartext in DB)  /  VK_priv (nie im Klartext gespeichert)
          │                                   ├─ versiegelt an jeden freigegebenen Benutzer:
          │                                   │     vault_grant.sealed = box_seal(VK_priv, U_pub)
          │                                   └─ Benutzer-Schlüsselpaar U_pub / U_priv
          │                                         U_priv gewrappt mit KEK = Argon2id(Passwort, Salt)
          ▼
Datenschlüssel (DEK) je Datensatz / je Blob, zufällig
   dek_sealed = box_seal(DEK, VK_pub)        ← geht OHNE Geheimnis
Felder:  XChaCha20-Poly1305-IETF(DEK, nonce, AAD = "tabelle|id|spalte")
Blobs:   secretstream_xchacha20poly1305 mit DEK, Chunks à 64 KiB
Blind Index: HMAC-SHA256(BIK, zweck | 0x00 | normalisierter Wert), BIK = KDF(VK_priv, "blind-index-v1")
```

- **Server-Schlüssel** (`shared/config.php`, 32 Byte, bei Installation
  erzeugt): `secretbox` für Betriebsdaten (`user.email_enc`,
  `mail_queue.*_enc`, `mfa_totp.secret_enc`, SMTP-Passwort). Login-Lookup
  über `user.email_bi = HMAC(Server-Schlüssel, lowercase(email))`.
  Ein Backup ohne `config.php` braucht beim Restore den Zugangsschritt des
  Installers (04 §6) – `config.php` wird getrennt gesichert.
- **Anker**: versiegelt wird nur an einen `vault.public_key`, dessen MAC
  unter dem Server-Schlüssel stimmt (`vault.public_key_mac`) – ein in der
  DB ausgetauschter Public-Key wird nicht benutzt.
- **Speicherformate** wie Vereinsbelege (`App\Service\Crypto`): jeder Wert
  beginnt mit einem Versionsbyte; AAD-Bestandteile auf `[a-z][a-z0-9_]*`
  begrenzt; Blind Index trägt seinen Zweck (gleiche Werte in zwei Spalten
  sind nicht korrelierbar); Wiederherstellungsschlüssel als
  Crockford-Base32, 7 Gruppen à 8 Zeichen, mit Prüfsumme.
- **Argon2id-Profil** der Umhüllung: MODERATE (ops 3, mem 256 MiB);
  Parameter je Benutzer gespeichert, Anhebung bei der nächsten Anmeldung.
- **Schlüsselrotation** des Tresors als Schrittkette (Generationen über die
  Version im `dek_sealed`) – Mechanik aus Vereinsbelege, Umsetzung erst bei
  Bedarf.

## 3. Anmeldung

- Login mit **E-Mail + Passwort**; `password_hash()` mit
  `PASSWORD_ARGON2ID`. Passwort-Hash und KEK-Salt getrennt.
- Passwortregel: min. 12 Zeichen, Abgleich gegen mitgelieferte Liste
  häufiger Passwörter (`app/data/haeufige-passwoerter.txt`, aus
  Vereinsbelege), keine Zusammensetzungsregeln.
- **Zweiter Faktor**, Default Pflicht für alle Konten:
  - TOTP (RFC 6238, eigene Implementierung + QR-Code in reinem PHP, aus
    Vereinsbelege), jeder Zeitschritt nur einmal verwendbar;
  - E-Mail-Code (6 Ziffern, 10 min, max. 5 Versuche, nur HMAC gespeichert);
  - 10 Einmal-Backup-Codes;
  - „Dieses Gerät 30 Tage merken" (widerrufbar);
  - erzwungene Einrichtung beim ersten Login; Ändern des Faktors nur nach
    erneuter Bestätigung mit Passwort + aktuellem Faktor.
- **Brute-Force-Schutz**: Rate-Limit je IP und je Konto (feste Fenster
  15 min), Versuch wird **vor** der Prüfung atomar gezählt; wortgleiche
  Fehlermeldungen, Schein-`password_verify()` bei unbekanntem Konto.
- **Passwort-Reset**: Token 32 Byte, nur Hash gespeichert, 30 min,
  einmalig; beendet alle Sessions. Nach einem Reset hat das Konto ein neues
  Schlüsselpaar und **keinen Tresorzugriff mehr**, bis ein Admin neu
  freigibt (Folge des Tresor-Modells).
- **Sicherheitsmails**: neues Gerät, Passwort geändert/zurückgesetzt, 2FA
  geändert, Tresor-Freigabe erteilt/entzogen, Tresor per
  Wiederherstellungsschlüssel entsperrt.

## 4. Session und Tresor-Entsperrung

- Nach Passwort **und** zweitem Faktor: KEK → `U_priv` → `VK_priv`.
  Zwischen beiden Schritten liegt der geöffnete Tresor nur verschlüsselt mit
  einem Schlüssel aus dem Cookie `__Host-2fa` vor (`PendingLogin`).
- `VK_priv` liegt in `$_SESSION` nur verschlüsselt mit `K_s`; `K_s` steht
  ausschließlich im Cookie `__Host-vk` (`httponly`, `secure`,
  `samesite=Strict`). Die Sessiondatei allein ist wertlos.
- Session-Cookie über HTTPS `__Host-sid` (`samesite=Lax`); im Docker-Dev
  über HTTP ohne Präfix. `secure` aus dem Request-Schema abgeleitet.
- Idle-Timeout 30 min (im Barrierefrei-Modus 60 min, mit Warnung vor
  dem Abmelden – 06 §4), absolut 12 h (Settings). Hintergrund-Anfragen
  (`X-Hintergrund: 1`) verlängern die Leerlaufzeit nicht.
- Jeder Request prüft das Konto erneut (`LoginGuard`): Sperre, Ablauf,
  `session_epoch` wirken sofort. Session-ID-Regeneration bei Login und
  Rechtewechsel; `cache_limiter=nocache`.
- Angemeldet ohne Tresor-Cookie bzw. ohne Freigabe ist ein eigener Zustand:
  Seiten zeigen einen Hinweis statt leerer Listen (`VaultAccess`).

## 5. Benutzer-Lebenszyklus

- Der **Installer** legt den ersten Admin an, erzeugt den Tresor und zeigt
  den Wiederherstellungsschlüssel einmalig (Bestätigung durch Eingabe der
  letzten Gruppe).
- Weitere Benutzer per **Einladung** (Mail-Link, Typ `invite`); das Konto
  setzt sein Passwort und den zweiten Faktor selbst.
- **Tresor-Freigabe** erteilt ein Admin mit `admin.vault_grant` aus seiner
  entsperrten Session (`box_seal(VK_priv, U_pub)` des neuen Kontos).
- Sperren, Ablaufdatum (für externe/zeitlich begrenzte Konten),
  Freigabe entziehen, Löschen.
- Notfall: Entsperren per Wiederherstellungsschlüssel (Admin-Seite), danach
  Freigaben neu erteilen.
- **Benutzer ≠ Mitglied.** Benutzer sind die Personen, die die Anwendung
  bedienen. Ob Mitglieder selbst ein Konto bekommen, ist fachlich offen
  (E-F2) – das Tresor-Modell setzt dafür enge Grenzen.

## 6. Rollen und Rechte (Mechanik)

- Rechte sind ein PHP-Enum `App\Domain\Permission`. Es gibt **zwei feste
  Rollen** (Einfachheit, kein Rollen-Editor):
  - **Admin**: alle Rechte.
  - **Vorstand**: Mitglieder suchen, ansehen, anlegen, bearbeiten (auch
    IBAN, Abteilung, Beitragsart), Listen und Exporte. **Nicht**:
    Jahresbeiträge, Einzug, Bezahlt-Status, Lastschriftdatei,
    Einstellungen, Benutzer, System.
- Die Zuordnung Rolle → Rechte steht im Code (ein `match`), die Rolle am
  Benutzer. Keine Reichweiten/Scopes – alle sehen alle Mitglieder.
  Übernommene Scope-Mechanik aus Vereinsbelege entfällt.
- `/admin/*` nur mit mindestens einem `admin.*`-Recht.

## 7. Web-Sicherheit

- **CSRF** zentral für jede POST-Route (`App\Http\CsrfSchutz`), Ausnahmen
  nur in `CsrfSchutz::AUSNAHMEN` (derzeit keine).
- **Header** (`.htaccess`, Startpunkt = Vereinsbelege):
  `Content-Security-Policy: default-src 'self'; script-src 'self';
  style-src 'self'; img-src 'self' data:; font-src 'self';
  connect-src 'self'; object-src 'none'; frame-ancestors 'none';
  base-uri 'self'; form-action 'self'`, HSTS, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy:
  same-origin`, `Permissions-Policy` restriktiv (alles aus).
  Lockerungen nur einzeln begründet (vgl. Vereinskalender §2).
- `.user.ini`: `zend.exception_ignore_args = On`,
  `zend.exception_string_param_max_len = 0`, `display_errors = Off`.
- Kein Inline-JS, keine `on*`-Attribute, kein `eval`/`new Function` – auch
  nicht in Bibliotheken. htmx über `<meta name="htmx-config">`,
  `includeIndicatorStyles=false`, kein `hx-on`. Ein `CspComplianceTest`
  prüft alle Templates.
- **Daten im Browser**: entschlüsselte Fachdaten nur im Speicher der
  geöffneten Seite, nie in localStorage, sessionStorage, IndexedDB oder
  einem Service-Worker-Cache; `Cache-Control: no-store` auf allen Antworten
  mit Fachdaten; nach dem Abmelden ist nichts davon mehr im Browser.
- Ausgaben escapen (eine Hilfsfunktion `e()`), JSON mit korrekten Headern.

## 8. Audit-Log

Append-only `audit_log` mit Hash-Kette (`prev_hash`, `hash`),
`id` vom Schreiber gesetzt, Details verschlüsselt (Tresor), IP nur als
HMAC. Protokolliert: Anmeldungen, Rechte- und Freigabeänderungen, Backup,
Update, Restore, Export – fachliche Aktionen ergänzt die fachliche Spec.
Prüfung der Kette im Admin (`audit.view`).

## 9. Integritätscheck Code

`checksums.txt` des Releases enthält je Datei eine Zeile; der Admin
vergleicht `current/` dagegen (signierte Liste, 04 §4).

## Pflicht-Tests

Krypto-Rundläufe und Manipulationserkennung je Speicherformat (falsche
AAD, falscher Schlüssel, unbekannte Version), Blind Index mit Zweck,
Wiederherstellungsschlüssel (Kodierung, Tippfehler-Toleranz, Prüfsumme),
Anker-MAC; Login (alle Fehlerfälle wortgleich, Rate-Limit atomar), 2FA
(TOTP-Einmalverwendung, E-Mail-Code-Limit, Backup-Codes, Gerät merken),
Reset (Token einmalig, neues Schlüsselpaar ohne Freigabe), Session (Tresor
ohne Cookie nicht entschlüsselbar, Timeouts, `session_epoch`), CSRF auf
jeder POST-Route, CSP-Compliance, Routen-Zugriffsmatrix, Audit-Kette.
