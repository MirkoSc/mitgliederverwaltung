# Vorgehen: vollautomatische Entwicklung

Die 37 Issues aus `docs/ARBEITSPLAN.md` werden von Claude-Agenten in
**GitHub Actions** umgesetzt – jedes Issue auf einer eigenen Maschine,
viele gleichzeitig. Ein Mensch muss nur starten und bei `braucht-hilfe`
nachsehen.

## Wie es läuft

```
Planer ──► Umsetzen #n ──► PR ──► CI ──► Pruefen ─┬─► Freigabe: Squash-Merge, Issue zu ──► Planer startet die nächsten
  ▲          (Claude,                              ├─► CI rot: Claude repariert ──► CI ...
  │           Modell aus                           ├─► main weiter: einmergen/Konflikt lösen ──► CI ...
  │           dem Label)                           └─► Review-Befunde: Claude behebt ──► CI ...
  └────────────────── push auf main / Issue geschlossen / alle 30 min
```

| Workflow | Aufgabe |
|---|---|
| `planer.yml` | startet jedes offene `auto`-Issue, dessen „Abhängig von" geschlossen ist – höchstens `MAX_PARALLEL` gleichzeitig |
| `umsetzen.yml` | Claude implementiert auf Branch `issue-<nr>` (Modell aus dem Label `modell:*`), der Workflow pusht und öffnet den PR |
| `ci.yml` | Tests (ab Issue #1 echt) |
| `pruefen.yml` | nach jeder CI: reparieren, `main` einmergen, Review durch Opus 5.5, bei Freigabe mergen |

Alles läuft nur, solange die Variable `AUTOMATIK` = `an` ist. Nach
`MAX_RUNDEN` Reparatur-Runden ohne Freigabe bekommt das Issue
`braucht-hilfe` und wird nicht weiter angefasst.

## Einmalig einrichten (Checkliste)

1. **Standard-Branch auf `main` stellen** (Settings → General → Default
   branch). Nötig, weil GitHub `workflow_run`, Zeitpläne und „Closes #n"
   nur auf dem Standard-Branch auswertet.
2. **Claude-Zugang als Secret** (Settings → Secrets and variables →
   Actions → New repository secret), eins von beiden:
   - `CLAUDE_CODE_OAUTH_TOKEN` – mit einem Claude-Abo: lokal
     `claude setup-token` ausführen und den Token eintragen; die Läufe
     zählen dann auf das Abo-Kontingent.
   - `ANTHROPIC_API_KEY` – API-Schlüssel, Abrechnung nach Verbrauch.
3. **`AUTOMATIK_TOKEN`** als Secret: ein Fine-grained Personal Access Token
   (GitHub → Settings → Developer settings → Fine-grained tokens), nur für
   dieses Repository, Berechtigungen *Contents*, *Pull requests*,
   *Issues*, *Actions* und *Workflows* jeweils Read and write. Er wird für
   Push, PR und Merge gebraucht, weil Aktionen mit dem eingebauten
   `GITHUB_TOKEN` keine weiteren Workflows (z. B. die CI) auslösen.
4. **Variablen** (Settings → Secrets and variables → Actions → Variables):
   - `MAX_PARALLEL` – wie viele Issues gleichzeitig (Vorschlag `8`; der
     Plan erlaubt bis zu 9).
   - `MAX_RUNDEN` – Reparatur-Runden je PR bis `braucht-hilfe`
     (Vorschlag `5`).
   - `AUTOMATIK` – erst beim Start auf `an` setzen.
5. Prüfen: Settings → Actions → General → „Allow all actions" (die
   Workflows nutzen `actions/*` und `anthropics/claude-code-action`).

## Start

1. Variable `AUTOMATIK` auf `an` setzen.
2. Actions → **Planer** → „Run workflow". Er startet #1; alles Weitere
   folgt von selbst.

**Anhalten:** `AUTOMATIK` auf `aus` setzen. Laufende Jobs laufen zu Ende,
nichts Neues startet. Danach mit `an` + „Run workflow" fortsetzen.

## Wenn etwas hängt

- Issues mit **`braucht-hilfe`**: der Kommentar am Issue nennt den Grund
  und den Lauf. Nach der Klärung (z. B. Spec präzisieren) das Label
  entfernen – der Planer startet das Issue neu bzw. „Pruefen" für den
  Branch von Hand starten (Actions → Pruefen → Run workflow, Branch
  `issue-<nr>`).
- Ein Issue steht auf `in-arbeit`, aber nichts läuft (z. B. Lauf
  abgebrochen): Label `in-arbeit` entfernen, Planer erneut starten.
- **#37 (Security-Review)** startet nie automatisch – vor der ersten
  echten Installation von Hand.

## Kosten und Grenzen

- **Claude:** jedes Issue ein Lauf zum Umsetzen plus mindestens ein Review
  (Opus) – bei 36 Issues grob 80–150 Läufe.
- **GitHub Actions:** private Repositories haben ein Freikontingent an
  Minuten (beim Free-Plan 2.000 min/Monat). Ein Umsetzen-Lauf dauert
  typisch 20–90 min; das Kontingent kann für den ganzen Plan knapp werden
  – unter Settings → Billing sichtbar.
- Gleichzeitige Jobs: beim Free-Plan bis 20.

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
