Du arbeitest **vollautomatisch** in GitHub Actions am Projekt
Mitgliederverwaltung. Niemand beantwortet Rückfragen.

## Auftrag

Setze Issue #{{ISSUE}} um: „{{TITEL}}"

{{BODY}}

## So gehst du vor

Die Schwesterprojekte liegen schreibgeschützt unter `.vorlagen/vereinsbelege`
und `.vorlagen/vereinskalender` – von dort übernimmst du Code (CLAUDE.md,
05 §3). Der Ordner wird nie committet.

Folge `.claude/commands/issue.md` (Schritte 1–8) mit diesen Abweichungen:

- Du bist bereits auf dem Branch `{{BRANCH}}` (von aktuellem `main` bzw.
  der Stand eines früheren Versuchs). **Wechsle nicht den Branch, pushe
  nicht, öffne keinen PR** – das macht der Workflow nach dir.
- **Committe** deine Arbeit (Englisch, sinnvolle Commits). Unfertiges nicht
  committen, sondern fertig machen.
- Lies `docs/spec/05-entwicklung.md` §6 und halte dich daran: nur
  Erweiterungspunkte, Migrationen nur im Nummernbereich aus dem Issue.
- Teste mit Docker: `docker compose -p issue-{{ISSUE}} run --rm …`
  (Images von `mirror.gcr.io`). Alle Tests müssen grün sein.
- Bei Unklarheit: einfachste Lösung, die zu Spec und Leitlinien
  (CLAUDE.md: „einfach statt perfekt", „Hinweise statt Sperren") passt.
- Nutze Skills, wo sie helfen – bei UI immer `frontend-design`.
- Entwicklungsphase: keine aufwendige Sicherheitshärtung, aber die
  Invarianten aus CLAUDE.md §4 einhalten.
- Gründlichkeit laut Issue-Label „effort".

## Am Ende

1. Schreibe den PR-Text nach `{{PR_BODY}}` (nicht committen): Zusammenfassung,
   Abschnitt „Entscheidungen", Prüfliste für manuelle Tests, geprüfte
   Fremdbibliotheken. `Closes #{{ISSUE}}` hängt der Workflow selbst an.
2. Nur wenn es wirklich nicht weitergeht (fehlende Voraussetzung,
   widersprüchliche Spec): schreibe den Grund nach `{{ABBRUCH}}` und höre
   auf. Der Workflow markiert das Issue dann mit `braucht-hilfe`.
