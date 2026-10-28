# Mitgliederverwaltung

Mitgliederverwaltung für einen Sportverein. Alle Mitgliederdaten liegen
verschlüsselt in der Datenbank – lesbar nur für angemeldete, freigegebene
Benutzer, nicht für jemanden mit FTP- oder Datenbankzugriff.

Läuft auf Shared Hosting (PHP 8.5 + MySQL/MariaDB) – ohne SSH, ohne
Composer auf dem Server, ohne `exec()`. Installation und Updates wie bei
[Vereinsbelege](https://github.com/MirkoSc/vereinsbelege) und dem
[Vereinskalender](https://github.com/MirkoSc/vereinskalender): eine
`setup.php` hochladen, Rest per Browser; Updates über die Kanäle `stable`
und `beta`.

**Status:** Nur der technische Rahmen ist festgelegt. Es gibt noch keinen
Code und noch keine fachliche Spezifikation.

## Dokumente

| Datei | Inhalt |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Verbindliche Architektur-Referenz (Claude Code liest sie in jeder Session) |
| [docs/VORGEHEN.md](docs/VORGEHEN.md) | Wie mit Claude Code gearbeitet wird |
| [docs/ENTSCHEIDUNGEN.md](docs/ENTSCHEIDUNGEN.md) | Getroffene technische und offene fachliche Entscheidungen |
| [docs/fachlichkeit.md](docs/fachlichkeit.md) | Fachliche Sammlung (Brainstorm) mit offenen Fragen |
| [docs/hosting-befunde.md](docs/hosting-befunde.md) | Gemessene Eigenschaften des Webspace (aus Vereinsbelege übernommen) |
| [docs/spec/01-plattform.md](docs/spec/01-plattform.md) | Hosting, Verzeichnislayout, Request-Modell, Cron, Mail, Logging |
| [docs/spec/02-sicherheit.md](docs/spec/02-sicherheit.md) | Verschlüsselung (Tresor), Anmeldung + 2FA, Rechte, CSP/CSRF, Audit-Log |
| [docs/spec/03-datenhaltung.md](docs/spec/03-datenhaltung.md) | DB-Konventionen, Verschlüsselungs-Klassifizierung, Blobs, Migrationen |
| [docs/spec/04-installation-und-update.md](docs/spec/04-installation-und-update.md) | setup.php, Installer, Updater, Release, Backup, Systemcheck |
| [docs/spec/05-entwicklung.md](docs/spec/05-entwicklung.md) | Docker, Tests, CI, Übernahme aus den Schwesterprojekten |
| [docs/spec/06-oberflaeche.md](docs/spec/06-oberflaeche.md) | Oberfläche: wenige Seiten, Handy + PC, Barrierefrei-Modus |
| [.claude/skills/frontend-design/](.claude/skills/frontend-design/SKILL.md) | UI-Gestaltungs-Skill von Anthropic (Apache-2.0), gilt für jede UI-Arbeit |

## Lizenz

GPLv3 (erlaubt die Übernahme von Code aus Vereinsbelege und
Vereinskalender).
