# Vorgehen mit Claude Code

## Stand

Das Repository enthält nur den **technischen Rahmen** (CLAUDE.md,
`docs/spec/01–05`, Entscheidungen, Hosting-Befunde). Es gibt noch keinen
Code und keine fachliche Spezifikation.

## Einmalig vorbereiten

1. Branch-Schutz für `main` aktivieren (PR + grüne CI Pflicht), sobald die
   CI existiert.
2. Vereinsbelege und Vereinskalender daneben klonen, damit Claude Code
   Infrastruktur übernehmen kann (05 §3):
   ```
   dev/vereinskalender/
   dev/vereinsbelege/
   dev/mitgliederverwaltung/   ← hier Claude Code starten
   ```
3. Beim Hoster eine Test-Subdomain (Kanal `beta`) und später die
   Produktiv-Subdomain (Kanal `stable`) anlegen, je mit DocumentRoot auf
   `<bereich>/web/`.
4. Release-Signaturschlüssel erzeugen (`tools/release-signatur.php`, kommt
   mit dem Projektgerüst) und als Secret `RELEASE_SIGNING_KEY` hinterlegen.

## Nächste Schritte

1. **Fachlichen Umfang klären** (E-F1 bis E-F6 in `ENTSCHEIDUNGEN.md`) und
   daraus fachliche Specs ab `docs/spec/06-…` schreiben.
2. **Issues anlegen**: je Issue ein in einer Session umsetzbarer Schnitt
   mit Spec-Verweis (Datei + Abschnitt) und Abnahmekriterien.
3. **Ein Issue pro Session**: `/issue <nr>` (`.claude/commands/issue.md`)
   – Issue lesen → nur verlinkte Specs lesen → Plan → Branch → Code +
   Tests → PR. Review, ggf. lokal testen (`docker compose up`), mergen.

Tipps:
- Neue Session je Issue, keine langen Sessions über mehrere Issues.
- Specs sind bewusst geteilt – im Issue nur die nötigen Abschnitte
  verlinken.
- Große Issues zuerst im Plan Mode planen lassen, prüfen, dann umsetzen.
- Fehler aus CI/Browser konkret einfügen (Meldung + Schritte).

## Releases

```bash
git tag v0.1.0 && git push origin v0.1.0
```

GitHub Action baut ein Pre-Release → Testinstanz (beta) aktualisiert sich
über Admin → Update → testen → auf GitHub als „latest" markieren →
Produktivinstanz (stable) zieht nach. Identisch zu Vereinsbelege und
Vereinskalender.
