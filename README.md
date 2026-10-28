# Mitgliederverwaltung

Mitgliederverwaltung für einen Sportverein: Mitglieder, Abteilungen,
Familien, Beiträge mit SEPA-Lastschriftdatei, Geburtstage, Ehrungen,
BLSV-Meldung und Exporte. Einfach gehalten, für Handy und PC, mit
Barrierefrei-Modus. Anmeldung mit zweitem Faktor; Dateien und Backups
sind verschlüsselt.

Läuft auf Shared Hosting (PHP 8.5 + MySQL/MariaDB) – ohne SSH, ohne
Composer auf dem Server, ohne `exec()`. Installation und Updates wie bei
[Vereinsbelege](https://github.com/MirkoSc/vereinsbelege) und dem
[Vereinskalender](https://github.com/MirkoSc/vereinskalender): eine
`setup.php` hochladen, Rest per Browser; Updates über die Kanäle `stable`
und `beta`.

**Status:** Technischer Rahmen und Fachlichkeit sind spezifiziert, der
Arbeitsplan steht als Issues bereit; es gibt noch keinen Code. Die
Umsetzung läuft vollautomatisch über GitHub Actions (`docs/VORGEHEN.md`).

## Dokumente

| Datei | Inhalt |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Verbindliche Architektur-Referenz (Claude Code liest sie in jeder Session) |
| [docs/VORGEHEN.md](docs/VORGEHEN.md) | Vollautomatische Entwicklung: Einrichtung, Start, Anhalten |
| [docs/ARBEITSPLAN.md](docs/ARBEITSPLAN.md) | 37 Issues, Reihenfolge, Parallelität, Modelle |
| [docs/ENTSCHEIDUNGEN.md](docs/ENTSCHEIDUNGEN.md) | Getroffene technische und offene fachliche Entscheidungen |
| [docs/fachlichkeit.md](docs/fachlichkeit.md) | Fachliche Sammlung (Brainstorm) mit offenen Fragen |
| [docs/hosting-befunde.md](docs/hosting-befunde.md) | Gemessene Eigenschaften des Webspace (aus Vereinsbelege übernommen) |
| [docs/spec/01-plattform.md](docs/spec/01-plattform.md) | Hosting, Verzeichnislayout, Request-Modell, Cron, Mail, Logging |
| [docs/spec/02-sicherheit.md](docs/spec/02-sicherheit.md) | Anmeldung + 2FA, Rollen, Datei-/Backup-Verschlüsselung, CSP/CSRF, Audit-Log |
| [docs/spec/03-datenhaltung.md](docs/spec/03-datenhaltung.md) | DB-Konventionen, Verschlüsselungs-Klassifizierung, Blobs, Migrationen |
| [docs/spec/04-installation-und-update.md](docs/spec/04-installation-und-update.md) | setup.php, Installer, Updater, Release, Backup, Systemcheck |
| [docs/spec/05-entwicklung.md](docs/spec/05-entwicklung.md) | Docker, Tests, CI, Übernahme aus den Schwesterprojekten |
| [docs/spec/06-oberflaeche.md](docs/spec/06-oberflaeche.md) | Oberfläche: wenige Seiten, Handy + PC, Barrierefrei-Modus |
| [docs/spec/07-mitglieder.md](docs/spec/07-mitglieder.md) … [10-import.md](docs/spec/10-import.md) | Fachliche Specs: Mitglieder, Beiträge, Listen/Exporte, Import |
| [.claude/skills/frontend-design/](.claude/skills/frontend-design/SKILL.md) | UI-Gestaltungs-Skill von Anthropic (Apache-2.0), gilt für jede UI-Arbeit |

## Lizenz

GPLv3 (erlaubt die Übernahme von Code aus Vereinsbelege und
Vereinskalender).
