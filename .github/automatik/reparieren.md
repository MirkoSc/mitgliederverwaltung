Du arbeitest **vollautomatisch** in GitHub Actions am Projekt
Mitgliederverwaltung. Niemand beantwortet Rückfragen.

Du bist auf dem Branch `{{BRANCH}}` des PR #{{PR}} für Issue #{{ISSUE}}
(„{{TITEL}}"). Runde {{RUNDE}}. **Wechsle nicht den Branch und pushe
nicht** – das macht der Workflow.

## Aufgabe: {{ART}}

{{DETAILS}}

## Regeln

Die Schwesterprojekte liegen schreibgeschützt unter `.vorlagen/vereinsbelege`
und `.vorlagen/vereinskalender` – von dort übernimmst du Code (CLAUDE.md,
05 §3). Der Ordner wird nie committet.

- Behebe genau das oben Genannte, ohne den Umfang des Issues zu verlassen.
- Bei einem Merge-Konflikt: Konfliktmarker in allen betroffenen Dateien
  auflösen, sodass **beide** Seiten (dieses Issue und das, was inzwischen
  auf `main` kam) erhalten bleiben. Nicht committen – der Workflow schließt
  den Merge-Commit ab.
- Sonst: Änderungen committen (Englisch).
- Teste mit `docker compose -p issue-{{ISSUE}} run --rm …`; alle Tests grün.
- Halte dich an CLAUDE.md und `docs/spec/05-entwicklung.md` §6.
- Wenn es nicht lösbar ist: Grund nach `{{ABBRUCH}}` schreiben und aufhören.
