# Grundsatzentscheidungen

Status: **✅ entschieden** · **❓ offen**. Wer eine Entscheidung ändert,
passt die betroffenen Specs im selben PR an.

Technische Entscheidungen haben die Kennung `E-NN`, fachliche (noch offene)
`E-FN`.

## Technischer Rahmen

### E-01 ✅ Gleiche Plattform wie die Schwesterprojekte
PHP 8.5 + MariaDB auf demselben Shared Hoster wie Vereinsbelege und
Vereinskalender; Deployment per Release-ZIP, `setup.php` und Self-Updater
mit Kanälen `stable`/`beta`. Der Hoster wird nicht namentlich genannt.

### E-02 ✅ Tresor-Modell wie Vereinsbelege
Mitgliederdaten sind personenbezogen; wer FTP- oder DB-Zugriff hat, soll
sie nicht lesen können. Deshalb asymmetrischer Tresor, Private Key nur
gewrappt mit Benutzerpasswörtern und als Papier-Wiederherstellungsschlüssel.

**Bewusst in Kauf genommene Folgen:**
- Alles, was Mitgliederdaten entschlüsselt (Listen, Exporte, Serienmails,
  Auswertungen), läuft nur in einer angemeldeten Session, nicht im Cron.
  Automatische Mails mit fachlichem Inhalt (z. B. Geburtstagsgrüße) gehen
  nur, wenn sie in einer Session vorbereitet und als Server-verschlüsselte
  Mail-Queue-Einträge abgelegt werden – die fachliche Spec muss das
  ausdrücklich entscheiden.
- Nach „Passwort vergessen" muss ein Admin den Zugriff neu freigeben.
- Geht der Wiederherstellungsschlüssel verloren **und** haben alle Admins
  ihr Passwort vergessen, sind die Daten weg.
- Gegen einen Angreifer, der Code auf dem Server verändert, hilft keine
  Verschlüsselung vollständig.

### E-03 ✅ Anmeldung wie Vereinsbelege
E-Mail + Passwort + zweiter Faktor (TOTP, E-Mail-Code, Backup-Codes),
Default Pflicht für alle Konten, Rate-Limits, Einladung statt
Selbstregistrierung.

### E-04 ✅ Code aus den Schwesterprojekten kopieren, kein gemeinsames Paket
Ein geteiltes Composer-Paket würde drei Release-Zyklen koppeln und braucht
eine eigene Pflege. Der übernommene Kern ist stabil; Sicherheitsfixes werden
per Issue in die anderen Projekte gespiegelt.

### E-05 ❓ UI-Technik – nach der Fachlichkeit entscheiden
Offen, bewusst zurückgestellt: welche Oberfläche zur Fachlichkeit passt
(viele große Tabellen? komplexe Formulare? Mitgliederportal?). Mögliche
Wege auf dem Hoster:
- server-gerenderte PHP-Seiten (ggf. mit htmx) wie Vereinsbelege – Login,
  Installer, Updater und Designsystem direkt übernehmbar;
- dasselbe mit CSS-/JS-Build in GitHub Actions;
- Single-Page-App (z. B. Vue, Angular, Svelte, React) mit PHP-JSON-API,
  in GitHub Actions gebaut;
- Blazor WebAssembly (C#) mit PHP-API.
Mitzuentscheiden: Komponentenbibliothek (fertig gestaltet, headless oder
selbst gebaut) und deren CSP-Verträglichkeit. Feste Grenzen unabhängig
davon: CLAUDE.md §1 und §4.

### E-06 ✅ Kein Event Sourcing
Verschlüsselte Event-Payloads würden Replay an eine Session binden.
Ersatz: Audit-Log mit Hash-Kette.

### E-07 ✅ Eine Installation = ein Verein
Kein Mandanten-Konzept.

### E-08 ✅ Speicher-Backend Dateisystem als Default
Falls Dateien gespeichert werden: verschlüsselte Blobs in
`shared/var/blobs/`, `db` als Alternative per Schrittkette.

### E-09 ✅ Prozessaufrufe bleiben verboten
Obwohl der Hoster `exec()` & Co. nicht sperrt: keine Abhängigkeit von
Binaries des Hosters, kleinere Angriffsfläche.

### E-10 ✅ UI-Gestaltung mit dem Skill `frontend-design`
Für gutes UI wird ein fertiger Skill verwendet statt eigener Regeln:
Anthropics offizieller `frontend-design`-Skill, unverändert unter
`.claude/skills/frontend-design/` (Quelle `anthropics/skills`, Apache-2.0,
GPL-verträglich). Er gilt für jede UI-Arbeit, unabhängig von E-05. Bei
Updates wird er unverändert neu übernommen.

## Fachlich offen (vor der jeweiligen fachlichen Spec klären)

### E-F1 ❓ Funktionsumfang
Welche Bereiche (Stammdaten, Abteilungen, Beiträge/SEPA, Kommunikation,
Ehrungen, Online-Beitritt …) und in welcher Reihenfolge.

### E-F2 ❓ Wer meldet sich an?
Nur Funktionäre oder auch Mitglieder selbst (Self-Service)? Ein
Mitgliederportal verträgt sich nur eingeschränkt mit dem Tresor-Modell
(ein Mitglied darf nicht den ganzen Tresor bekommen).

### E-F3 ❓ Öffentliche Seiten ohne Anmeldung
Z. B. ein Beitrittsformular, das wie die Einreichung in Vereinsbelege nur
verschlüsselt in einen Posteingang schreibt.

### E-F4 ❓ Kopplung an Vereinsbelege/Vereinskalender
Eigenständig oder Datenaustausch (z. B. Beitragseingänge, Mannschaften)?
Wenn ja, nur über Export/Import-Dateien, nie über eine geteilte Datenbank.

### E-F5 ❓ Klartext-Strukturfelder
Welche fachlichen Felder (z. B. Eintrittsdatum, Abteilungs-ID, Status)
dürfen für SQL-Filter Klartext bleiben (03 §2)?

### E-F6 ❓ Datenübernahme
Aus welchem Altbestand (Excel/CSV, andere Vereinssoftware) werden Mitglieder
initial übernommen?
