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

### E-02 ❌ Tresor-Modell wie Vereinsbelege – ersetzt durch E-11
Ursprünglich geplant, nach Klärung der Fachlichkeit verworfen: der Tresor
ist der aufwendigste Teil des Rahmens (Wiederherstellungsschlüssel,
Freigaben je Benutzer, Zugriffsverlust nach Passwort-Reset, Filtern in
PHP statt SQL) und passt nicht zu „einfach statt perfekt".

### E-03 ✅ Anmeldung wie Vereinsbelege
E-Mail + Passwort + zweiter Faktor (TOTP, E-Mail-Code, Backup-Codes),
Default Pflicht für alle Konten, Rate-Limits, Einladung statt
Selbstregistrierung.

### E-04 ✅ Code aus den Schwesterprojekten kopieren, kein gemeinsames Paket
Ein geteiltes Composer-Paket würde drei Release-Zyklen koppeln und braucht
eine eigene Pflege. Der übernommene Kern ist stabil; Sicherheitsfixes werden
per Issue in die anderen Projekte gespiegelt.

### E-05 ✅ UI-Technik: PHP-Seiten + htmx wie Vereinsbelege
Nach Klärung der Fachlichkeit: die Oberfläche besteht aus Listen mit
Filtern, einem Mitglieder-Formular, wenigen Abläufen und Einstellungen –
bei ~700 Mitgliedern. Server-gerenderte Seiten mit htmx reichen dafür,
Login/2FA/Installer/Updater/Backup lassen sich samt Oberfläche aus
Vereinsbelege übernehmen, kein Build, eine Sprache. Eine Single-Page-App
hätte alle diese Oberflächen und eine JSON-API neu verlangt.
Dazu: so wenige Seiten wie möglich, Handy + PC, Barrierefrei-Modus je
Benutzer für den PC (06-oberflaeche.md).

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

### E-11 ✅ Datenbank im Klartext, nur Dateien verschlüsselt
Mitgliederdaten stehen im Klartext in der Datenbank. Mit dem
Server-Schlüssel aus `config.php` verschlüsselt werden nur Dateien
(Nachweis-Scans, Lastschriftdateien als Archiv jedes Einzugs), Backups
und Geheimnisse (TOTP, SMTP-Passwort). Exporte werden nie auf dem
Webspace gespeichert.
Schutz der Daten durch Zugangskontrolle (zweiter Faktor, HTTPS).

**Bewusst in Kauf genommen:** Wer Zugang zur Datenbank (DB-Dump,
Datenbank-Verwaltung des Hosters) oder per FTP zu `config.php` bekommt,
kann Mitgliederdaten lesen. Gegenmaßnahmen: starke Passwörter für Hoster,
FTP und Datenbank. Gemessen wurde vorher, dass der Tresor die Suche nicht
spürbar verlangsamt hätte (~40 ms für 700 Mitglieder) – entschieden wurde
wegen der Einfachheit.

### E-10 ✅ UI-Gestaltung mit dem Skill `frontend-design`
Für gutes UI wird ein fertiger Skill verwendet statt eigener Regeln:
Anthropics offizieller `frontend-design`-Skill, unverändert unter
`.claude/skills/frontend-design/` (Quelle `anthropics/skills`, Apache-2.0,
GPL-verträglich). Er gilt für jede UI-Arbeit, unabhängig von E-05. Bei
Updates wird er unverändert neu übernommen.

## Fachlich offen (vor der jeweiligen fachlichen Spec klären)

Die Sammlung und Klärung läuft in `docs/fachlichkeit.md`.

### E-F1 ✅ Funktionsumfang – in Specs 07–10
Steht fest: Stammdaten mit Versionen, Abteilungen, Familien, Funktionen,
Beiträge mit SEPA-Lastschriftdatei, Geburtstagskalender, Ehrungen,
BLSV-Meldung, Exporte (xlsx/csv), Erstimport. Details:
`docs/fachlichkeit.md`, umgesetzt in den Specs 07–10. Offen nur noch E6
(Mandatsreferenz) und L3 (BLSV-Formate).

### E-F2 ✅ Wer meldet sich an? – nur der Vorstand
Nur ein kleiner Verwaltungskreis (Vorstand). Kein Zugang für Mitglieder.

Ursprüngliche Frage: Nur Funktionäre oder auch Mitglieder selbst
(Self-Service)?

### E-F3 ✅ Öffentliche Seiten ohne Anmeldung – keine
Außer Anmeldung, Impressum und Datenschutz vorerst keine.

Ursprüngliche Frage: z. B. ein öffentliches Beitrittsformular.

### E-F4 ✅ Kopplung an Vereinsbelege/Vereinskalender – keine
Die Mitgliederverwaltung bleibt eigenständig.

### E-F5 – entfallen durch E-11
Die Frage, welche Felder trotz Tresor im Klartext bleiben dürfen, stellt
sich ohne Tresor nicht mehr.

### E-F6 ✅ Datenübernahme – festes Importformat + einmalige Umwandlung
Aus der bisherigen Mitgliederverwaltung (Excel/CSV). Festes Importformat
der Anwendung; die Altdatei wird einmalig lokal per Skript umgewandelt
(`docs/fachlichkeit.md` §8).

Ursprüngliche Frage:
Aus welchem Altbestand (Excel/CSV, andere Vereinssoftware) werden Mitglieder
initial übernommen?
