---
description: Ein GitHub-Issue nach dem Projekt-Workflow umsetzen
argument-hint: <issue-nummer>
---
Setze GitHub-Issue #$ARGUMENTS um. Halte dich strikt an diesen Ablauf:

1. Issue lesen (`gh issue view $ARGUMENTS` oder die GitHub-MCP-Tools). Lies
   danach NUR die im Issue verlinkten Abschnitte aus `docs/spec/`
   (CLAUDE.md kennst du bereits) sowie `docs/spec/05-entwicklung.md` §6
   (parallele Arbeit). Andere Specs nur, wenn zwingend nötig.
2. Prüfe, dass alle Issues unter „Abhängig von" geschlossen sind. Wenn
   nicht: abbrechen und das sagen.
3. Kurzer Plan (betroffene Dateien, Migrationen im zugewiesenen Bereich,
   Tests). Interaktiv: bei fachlicher Unklarheit fragen. Automatisch: die
   einfachste spec-konforme Lösung wählen und im PR begründen.
4. Branch `issue-$ARGUMENTS` von aktuellem `main` (interaktiv:
   `git switch main && git pull && git switch -c issue-$ARGUMENTS`).
5. Implementieren inkl. der Pflicht-Tests aus der Spec. Nur eigene Dateien
   und Erweiterungspunkte (05 §6). Skills nutzen, wo sie helfen –
   `frontend-design` bei jeder UI-Arbeit.
6. Prüfen: `docker compose -p issue-$ARGUMENTS run --rm app vendor/bin/phpunit`
   und – falls JS betroffen – `node --test tests/js`. Alles grün, ohne
   Deprecations. (Bis #1 fertig ist, gibt es noch keine Docker-Umgebung.)
7. Architektur/Datenmodell betroffen: Spec bzw. CLAUDE.md im selben
   Branch aktualisieren. Neue Folge-Issues nur vorschlagen (im PR), nicht
   selbst anlegen.
8. Commits auf Englisch. PR mit: Zusammenfassung, `Closes #$ARGUMENTS`,
   „Entscheidungen" (was du ohne Rückfrage festgelegt hast), Prüfliste für
   manuelle Tests (360 px, PC, PC im Barrierefrei-Modus), geprüfte
   Fremdbibliotheken. In der Automatik pusht und öffnet der Workflow den
   PR – dort nur committen und den PR-Text in die genannte Datei schreiben.
9. Kurze Liste am Ende: was gemacht, was manuell zu testen ist, offene
   Punkte.
