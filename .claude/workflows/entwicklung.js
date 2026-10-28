export const meta = {
  name: 'entwicklung',
  description: 'Mitgliederverwaltung: alle offenen Issues des Arbeitsplans umsetzen, von Opus prüfen lassen und nach main mergen',
  whenToUse: 'Vollautomatische Umsetzung von docs/ARBEITSPLAN.md aus einer Claude-Code-Sitzung (ohne GitHub Actions)',
  phases: [
    { title: 'Stand', detail: 'geschlossene Issues ermitteln, Docker prüfen' },
    { title: 'Umsetzen', detail: 'je Issue ein Agent in eigenem Worktree' },
    { title: 'Review', detail: 'Opus 5.5 prüft jeden PR' },
    { title: 'Mergen', detail: 'main einmergen, testen, squash-merge, Issue schließen' },
  ],
}

// Plan from docs/ARBEITSPLAN.md (#37 is manual and not part of this run).
const PLAN = [
  { n: 1, m: 'opus', e: 'xhigh', d: [] },
  { n: 2, m: 'opus', e: 'high', d: [1] },
  { n: 3, m: 'opus', e: 'high', d: [2, 5, 6, 7, 12] },
  { n: 4, m: 'sonnet', e: 'high', d: [2, 12] },
  { n: 5, m: 'sonnet', e: 'high', d: [1] },
  { n: 6, m: 'opus', e: 'high', d: [1, 7] },
  { n: 7, m: 'sonnet', e: 'medium', d: [1] },
  { n: 8, m: 'opus', e: 'high', d: [6, 5] },
  { n: 9, m: 'opus', e: 'high', d: [1] },
  { n: 10, m: 'sonnet', e: 'low', d: [6, 9] },
  { n: 11, m: 'sonnet', e: 'medium', d: [1] },
  { n: 12, m: 'opus', e: 'high', d: [5] },
  { n: 13, m: 'sonnet', e: 'medium', d: [2] },
  { n: 14, m: 'haiku', e: 'medium', d: [1] },
  { n: 15, m: 'opus', e: 'high', d: [1] },
  { n: 16, m: 'sonnet', e: 'medium', d: [1, 9] },
  { n: 17, m: 'sonnet', e: 'medium', d: [9, 15] },
  { n: 18, m: 'sonnet', e: 'high', d: [9, 15] },
  { n: 19, m: 'opus', e: 'high', d: [9, 15] },
  { n: 20, m: 'sonnet', e: 'medium', d: [19] },
  { n: 21, m: 'sonnet', e: 'high', d: [19] },
  { n: 22, m: 'sonnet', e: 'medium', d: [19] },
  { n: 23, m: 'sonnet', e: 'medium', d: [19, 5] },
  { n: 24, m: 'sonnet', e: 'high', d: [9, 11, 15] },
  { n: 25, m: 'sonnet', e: 'medium', d: [24] },
  { n: 26, m: 'sonnet', e: 'medium', d: [24] },
  { n: 27, m: 'haiku', e: 'medium', d: [24, 21] },
  { n: 28, m: 'sonnet', e: 'medium', d: [16, 20, 24] },
  { n: 29, m: 'opus', e: 'high', d: [9, 15, 19] },
  { n: 30, m: 'opus', e: 'xhigh', d: [5, 16, 29] },
  { n: 31, m: 'haiku', e: 'low', d: [24, 29] },
  { n: 32, m: 'sonnet', e: 'high', d: [9, 11, 20] },
  { n: 33, m: 'sonnet', e: 'high', d: [1] },
  { n: 34, m: 'opus', e: 'high', d: [22, 32] },
  { n: 35, m: 'haiku', e: 'medium', d: [3, 4, 12, 30, 32] },
  { n: 36, m: 'opus', e: 'high', d: [3, 4, 8, 10, 13, 14, 17, 18, 23, 25, 26, 27, 28, 30, 31, 33, 34] },
]

const REPO = (args && args.repo) || '/home/user/mitgliederverwaltung'
const WT = (args && args.worktrees) || '/home/user/wt'
const MAX_RUNDEN = (args && args.maxRunden) || 3
// Each agent runs its own Docker stack (PHP + MariaDB); more than ~4 at once
// exhausted the session container and made it restart.
const MAX_PARALLEL = (args && args.maxParallel) || 4

const BASIS = `Projekt Mitgliederverwaltung: lokales Repository ${REPO}, GitHub MirkoSc/mitgliederverwaltung.
Für dieses Projekt gilt ${REPO}/CLAUDE.md – nicht die CLAUDE.md der Schwesterprojekte.
Du arbeitest vollautomatisch; niemand beantwortet Rückfragen. Bei Unklarheit die einfachste
Lösung wählen, die zu Spec und Leitlinien passt, und sie im PR unter „Entscheidungen" begründen.
Mirko hat ausdrücklich erlaubt: Branches issue-<nr> pushen, PRs gegen main öffnen, nach Freigabe
in main mergen, Issues kommentieren, labeln und schließen.
GitHub nur über die MCP-Tools mcp__github__* (per ToolSearch laden), nicht über gh.
Docker: prüfe mit "docker info"; läuft es nicht, starte "sudo nohup dockerd > /tmp/dockerd.log 2>&1 &"
und prüfe in einer Schleife erneut. Images von mirror.gcr.io (Docker Hub drosselt).
Schwesterprojekte zum Übernehmen von Code (nur lesen): /home/user/vereinsbelege, /home/user/vereinskalender.
Regeln für parallele Arbeit: ${REPO}/docs/spec/05-entwicklung.md §6. Skills nutzen, wo sie helfen
(bei UI immer frontend-design). Commits auf Englisch.
Räume nach den Tests deine Docker-Container ab ("docker compose -p issue-<nr> down -v"), damit der
Speicher reicht. Der Container kann jederzeit neu starten: committe und pushe Zwischenstände regelmäßig
(auch unfertig als "WIP: …" auf issue-<nr>), damit nichts verloren geht.`

const wt = n => `${WT}/issue-${n}`

const IMPL = {
  type: 'object',
  properties: {
    ok: { type: 'boolean' },
    pr: { type: 'number' },
    zusammenfassung: { type: 'string' },
    grund: { type: 'string' },
  },
  required: ['ok', 'zusammenfassung'],
}
const REV = {
  type: 'object',
  properties: {
    freigabe: { type: 'boolean' },
    zusammenfassung: { type: 'string' },
    befunde: { type: 'array', items: { type: 'string' } },
  },
  required: ['freigabe', 'zusammenfassung', 'befunde'],
}
const MERGE = {
  type: 'object',
  properties: { merged: { type: 'boolean' }, grund: { type: 'string' } },
  required: ['merged'],
}

const umsetzenPrompt = i => `${BASIS}

Setze Issue #${i.n} um (Effort: ${i.e}).
1. Worktree anlegen: im Repository ${REPO} "git fetch origin". Existiert origin/issue-${i.n}, dann
   "git worktree add -B issue-${i.n} ${wt(i.n)} origin/issue-${i.n}", sonst
   "git worktree add -B issue-${i.n} ${wt(i.n)} origin/main". Existiert der Worktree schon, weiterverwenden.
   Arbeite ausschließlich in ${wt(i.n)}. Gibt es dort bereits Arbeit (Commits, auch "WIP"),
   mach damit weiter statt neu anzufangen.
2. Folge ${REPO}/.claude/commands/issue.md in der Automatik-Variante: Issue #${i.n} per MCP lesen,
   nur die verlinkten Specs, Abhängigkeiten sind bereits gemergt. Implementieren inkl. Pflicht-Tests.
   Testen mit "docker compose -p issue-${i.n} run --rm …" (sobald #1 die Docker-Umgebung gebaut hat).
3. Committen, dann "git push -u origin issue-${i.n}".
4. Gibt es noch keinen offenen PR für issue-${i.n}: PR per mcp__github__create_pull_request
   (base main, head issue-${i.n}). Text: Zusammenfassung, „Entscheidungen", Prüfliste für manuelle
   Tests, geprüfte Fremdbibliotheken, am Ende "Closes #${i.n}".
Wenn es wirklich nicht weitergeht: ok=false und Grund. Sonst ok=true und die PR-Nummer.`

const reviewPrompt = (i, pr) => `${BASIS}

Du bist der Reviewer vor dem Merge. Ändere keine Dateien.
PR #${pr} (Branch issue-${i.n}) setzt Issue #${i.n} um. Issue per MCP lesen.
Im Repository ${REPO}: "git fetch origin", dann "git diff origin/main...origin/issue-${i.n}" lesen und die
im Issue verlinkten Specs. Tests darfst du im Worktree ${wt(i.n)} ausführen
("docker compose -p issue-${i.n} run --rm …").
Prüfe: Abnahmekriterien und Pflicht-Tests erfüllt? Echte Fehler? Invarianten aus CLAUDE.md §1/§4
verletzt (exec, Inline-JS, Rechte nur serverseitig, Mitgliederdaten in Logs)? Verstoß gegen 05 §6
(zentrale Dateien statt Erweiterungspunkte, Migrationsnummer außerhalb des Bereichs)? Deutlich mehr
gebaut als verlangt? Nutze den Skill code-review, wenn er hilft.
Freigabe, wenn nichts davon blockiert. Kleinigkeiten und Sicherheitshärtung über §4 hinaus sind
kein Ablehnungsgrund. Befunde konkret (Datei, was falsch, was tun).
Schreibe dein Ergebnis zusätzlich als Kommentar an PR #${pr} (mcp__github__add_issue_comment),
Überschrift "Review (Opus 5.5): Freigabe" bzw. "Review (Opus 5.5): Änderungen nötig".`

const fixPrompt = (i, pr, befunde, runde) => `${BASIS}

Runde ${runde}: Behebe die Review-Befunde für PR #${pr} (Issue #${i.n}) im Worktree ${wt(i.n)}
(Branch issue-${i.n}; fehlt der Worktree, wie üblich aus origin/issue-${i.n} anlegen).
Befunde:
${befunde.map(b => '- ' + b).join('\n')}
Nur diese Punkte, im Umfang des Issues. Tests grün ("docker compose -p issue-${i.n} run --rm …"),
committen, "git push origin issue-${i.n}". ok=false nur, wenn es wirklich nicht lösbar ist.`

const mergePrompt = (i, pr) => `${BASIS}

Merge PR #${pr} (Issue #${i.n}), Worktree ${wt(i.n)}, Branch issue-${i.n}.
1. Im Worktree: "git fetch origin" und "git merge origin/main". Konflikte so lösen, dass beide Seiten
   erhalten bleiben (dieses Issue und was inzwischen auf main kam).
2. Hat der Merge etwas geändert: alle Tests ausführen ("docker compose -p issue-${i.n} run --rm …");
   Fehler minimal beheben, committen, "git push origin issue-${i.n}".
3. PR per mcp__github__merge_pull_request mergen (merge_method squash). Scheitert das, weil main sich
   erneut bewegt hat, Schritte 1–3 wiederholen.
4. Issue #${i.n} schließen (mcp__github__issue_write, state closed, state_reason completed), falls
   noch offen; Branch issue-${i.n} auf GitHub löschen ist nicht nötig.
5. Worktree entfernen: im Repository ${REPO} "git worktree remove --force ${wt(i.n)}".
merged=true nur, wenn der PR wirklich gemergt ist.`

const markierePrompt = (i, grund) => `${BASIS}

Issue #${i.n} ist in der Automatik hängen geblieben. Setze per MCP das Label "braucht-hilfe" am
Issue und schreibe einen Kommentar: "Automatik ist hängen geblieben: ${grund.replace(/"/g, "'")}".
Sonst nichts ändern.`

async function markiere(i, grund) {
  log(`#${i.n} braucht Hilfe: ${grund}`)
  await agent(markierePrompt(i, grund), { label: `markieren #${i.n}`, phase: 'Mergen', model: 'haiku', effort: 'low' })
  return { merged: false }
}

async function bearbeite(i) {
  const offen = offenePrs.get(i.n)
  const impl = offen ? { ok: true, pr: offen, zusammenfassung: 'PR existierte schon' } : await agent(umsetzenPrompt(i), { label: `umsetzen #${i.n}`, phase: 'Umsetzen', model: i.m, effort: i.e, schema: IMPL })
  if (!impl || !impl.ok || !impl.pr) return markiere(i, (impl && impl.grund) || 'Umsetzung ohne PR beendet')
  log(`#${i.n}: PR #${impl.pr} offen`)

  for (let runde = 1; ; runde++) {
    const rev = await agent(reviewPrompt(i, impl.pr), { label: `review #${i.n}`, phase: 'Review', model: 'opus', effort: 'high', schema: REV })
    if (!rev) return markiere(i, 'Review fehlgeschlagen')
    if (rev.freigabe) break
    if (runde > MAX_RUNDEN) return markiere(i, `nach ${MAX_RUNDEN} Runden keine Freigabe: ${rev.befunde.join('; ')}`)
    log(`#${i.n}: Review Runde ${runde} – ${rev.befunde.length} Befunde`)
    const fix = await agent(fixPrompt(i, impl.pr, rev.befunde, runde), { label: `beheben #${i.n} (${runde})`, phase: 'Umsetzen', model: i.m, effort: i.e, schema: IMPL })
    if (!fix || !fix.ok) return markiere(i, (fix && fix.grund) || 'Befunde nicht behebbar')
  }

  const mg = await agent(mergePrompt(i, impl.pr), { label: `mergen #${i.n}`, phase: 'Mergen', model: 'sonnet', effort: 'medium', schema: MERGE })
  if (!mg || !mg.merged) return markiere(i, (mg && mg.grund) || 'Merge fehlgeschlagen')
  log(`#${i.n} gemergt`)
  return { merged: true }
}

phase('Stand')
const stand = await agent(`${BASIS}

Ermittle per mcp__github__list_issues (state CLOSED, alle Seiten) die Nummern aller geschlossenen
Issues in MirkoSc/mitgliederverwaltung, und per mcp__github__list_pull_requests (state open) alle
offenen PRs, deren head-Branch "issue-<nr>" heißt (Issue-Nummer und PR-Nummer). Stelle außerdem
sicher, dass Docker läuft (siehe oben), und lege den Ordner ${WT} an. Ändere sonst nichts.`, {
  model: 'haiku', effort: 'low',
  schema: { type: 'object', properties: {
    geschlossen: { type: 'array', items: { type: 'number' } },
    offene_prs: { type: 'array', items: { type: 'object', properties: { issue: { type: 'number' }, pr: { type: 'number' } }, required: ['issue', 'pr'] } },
    docker_ok: { type: 'boolean' } }, required: ['geschlossen', 'offene_prs', 'docker_ok'] },
})
if (!stand) throw new Error('Stand konnte nicht ermittelt werden')
if (!stand.docker_ok) log('Warnung: Docker läuft nicht – die Agenten versuchen es selbst zu starten')

const done = new Set(stand.geschlossen)
const offenePrs = new Map(stand.offene_prs.map(x => [x.issue, x.pr]))
const failed = new Set()
const pending = PLAN.filter(i => !done.has(i.n))
const running = new Map()
log(`${done.size} Issues bereits geschlossen, ${pending.length} offen, ${offenePrs.size} davon mit offenem PR`)

while (pending.length || running.size) {
  for (const i of [...pending]) {
    if (i.d.some(x => failed.has(x))) {
      pending.splice(pending.indexOf(i), 1)
      failed.add(i.n)
      log(`#${i.n} übersprungen – Abhängigkeit hängt`)
    } else if (running.size < MAX_PARALLEL && i.d.every(x => done.has(x))) {
      pending.splice(pending.indexOf(i), 1)
      running.set(i.n, bearbeite(i).catch(e => ({ merged: false, err: String(e) })).then(r => ({ i, r })))
      log(`#${i.n} gestartet (${i.m}, ${i.e})`)
    }
  }
  if (!running.size) break
  const { i, r } = await Promise.race(running.values())
  running.delete(i.n)
  if (r && r.merged) done.add(i.n)
  else failed.add(i.n)
}

return {
  gemergt: PLAN.filter(i => done.has(i.n)).map(i => i.n),
  haengt_oder_uebersprungen: [...failed].sort((a, b) => a - b),
  nicht_gestartet: pending.map(i => i.n),
}
