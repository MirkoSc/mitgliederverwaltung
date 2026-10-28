# Vorgehen: vollautomatische Entwicklung

Die 37 Issues aus `docs/ARBEITSPLAN.md` werden von Claude-Agenten
umgesetzt, gesteuert aus einer Claude-Code-Sitzung. Ein Mensch muss nur
starten und bei `braucht-hilfe` nachsehen.

## Start

In einer Claude-Code-Sitzung mit diesem
Repository sagen „starte die Entwicklung“ (bzw. den Workflow
`entwicklung` ausführen, `.claude/workflows/entwicklung.js`). Er

- liest, welche Issues schon geschlossen sind (Fortsetzen ist jederzeit
  möglich),
- startet jedes Issue, sobald seine Abhängigkeiten gemergt sind – je
  Issue ein Agent mit dem Modell aus dem Arbeitsplan in einem eigenen
  Worktree,
- lässt jeden PR von Opus 5.5 prüfen (bis zu 3 Reparatur-Runden),
- mergt main ein, testet, squash-mergt und schließt das Issue.

Wie viele Agenten gleichzeitig laufen, hängt von der Maschine der Sitzung
ab (bei 4 CPU-Kernen 2). Die Sitzung muss dafür laufen bleiben.

## Wenn etwas hängt

Issues mit **`braucht-hilfe`**: der Kommentar am Issue nennt den Grund.
Nach der Klärung (z. B. Spec präzisieren) das Label entfernen und den
Workflow erneut starten – geschlossene Issues werden übersprungen, der
Rest läuft weiter. **#37 (Security-Review)** startet nie automatisch –
vor der ersten echten Installation von Hand.

## Von Hand arbeiten

Weiterhin möglich, z. B. für `braucht-hilfe`: Claude Code im Repo starten
und `/issue <nr>` (`.claude/commands/issue.md`).

## Releases

```bash
git tag v0.1.0 && git push origin v0.1.0
```

Der Release-Workflow (ab #2) baut ein Pre-Release → Testinstanz (Kanal
beta) → im Admin „Update" → testen → auf GitHub als „latest" markieren →
Produktivinstanz (Kanal stable) zieht nach.
