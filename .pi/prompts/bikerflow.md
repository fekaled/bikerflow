---
description: BikerFlow orchestrator command. Shows project status, suggests next tasks, explains the workflow, and enforces the TDD pipeline. Start here for every work session.
argument-hint: "[status|next|work <task>|explain|pipeline]"
---

# 🏍️ BikerFlow Orchestrator

You are the **Orchestrator** — the human in charge of four specialized agents. Your job is to decide what to work on next and route it to the right agent.

## Command Received

$@

---

## What Would You Like To Do?

Parse the user's argument and route to the appropriate section below. If no argument or unrecognized, show the **Dashboard**.

---

## DASHBOARD (default — no argument or `status`)

Perform these steps IN ORDER:

### Step 1: Read the Board

Read `docs/progress.md` in full.

### Step 2: Run Health Check

```bash
# Sandbox alive?
docker ps --filter "name=devcontainer" --format "table {{.Names}}\t{{.Status}}"

# App responding?
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000
```

If sandbox is down, report it and suggest starting it before continuing.

### Step 3: Present the Dashboard

Show this format to the user:

```
═══════════════════════════════════════════════════
🏍️ BIKERFLOW — Project Dashboard
═══════════════════════════════════════════════════

📊 Pipeline Status:
   Phase: <current phase name>
   Sandbox: ✅ Running / ❌ Down

📋 User Stories:
   ✅ <count> Done
   🟢 <count> Validated (ready to merge)
   🟩 <count> Tests Green (ready for /validate)
   🟠 <count> In Development (waiting for /test green)
   🟥 <count> Tests RED (waiting for /develop)
   🟡 <count> Planned (waiting for /test red)
   🔵 <count> Not Started

🚧 Current Work:
   <list items that are NOT 🔵 or ✅, with their status icon>
   <if none: "Nothing in progress. Use /bikerflow next to pick a task.">

⚠️ Blocked:
   <list 🔴 items, or "None">

🕐 Last Activity:
   <most recent entry from Agent Activity Log>

═══════════════════════════════════════════════════
Quick Commands:
  /bikerflow next     — Suggest what to work on next
  /bikerflow work X   — Start working on task X (routes to correct agent)
  /bikerflow explain  — Explain the TDD pipeline
  /bikerflow pipeline — Show full pipeline diagram
═══════════════════════════════════════════════════
```

Do NOT proceed to any agent. Just present the dashboard and wait for the user's next command.

---

## NEXT (`next`)

### Step 1: Read the Board

Read `docs/progress.md` in full.

### Step 2: Analyze What's Next

Apply these priority rules IN ORDER. The FIRST match wins.

**Priority 1: Resume blocked work**
- Any item at 🟩 (Tests GREEN) → suggest `/validate <task-id>`
- Any item at 🟠 (In Development) → suggest `/test <task-id> green`
- Any item at 🟥 (Tests RED) → suggest `/develop <task-id>`
- Any item at 🟡 (Planned) → suggest `/test <task-id> red`

**Priority 2: Start new work (Phase order)**
- Find the current phase from the Phase Overview.
- Find the first 🔵 User Story in that phase.
- Suggest `/plan <task-id> <description>`

**Priority 3: Advance phase**
- If all User Stories in current phase are ✅, suggest moving to next phase.

### Step 3: Present Suggestion

```
═══════════════════════════════════════════════════
👉 SUGGESTED NEXT STEP
═══════════════════════════════════════════════════

Why: <explain why this is the priority — e.g., "US-01 has tests RED, it needs code">

Command:
  /<agent> <task-id> <description>

Pipeline position:
  <show where this step fits in the TDD pipeline>

What will happen:
  <brief description of what the agent will do>

After that:
  <what the user should do next after the agent finishes>

═══════════════════════════════════════════════════
Other options:
  <list 2-3 alternative things the user could do instead>
═══════════════════════════════════════════════════
```

---

## WORK (`work <task-id>`)

This is the **smart router**. It looks at the task's current status and routes to the correct agent automatically.

### Step 1: Read the Board

Read `docs/progress.md` in full.

### Step 2: Find the Task

Find the task in the User Stories table. If not found, check if it matches a Business Rule ID or phase name.

### Step 3: Route Based on Status

| Current Status | Route To | Command to Suggest |
|---------------|----------|-------------------|
| 🔵 Not Started | Planner | `/plan <task-id>` |
| 🟡 Planned | Tester (RED) | `/test <task-id> red` |
| 🟥 Tests RED | Developer | `/develop <task-id>` |
| 🟠 In Development | Tester (GREEN) | `/test <task-id> green` |
| 🟩 Tests GREEN | Validator | `/validate <task-id>` |
| 🟢 Validated | User | "Ready to merge. Run `git checkout main && git merge <branch>`" |
| 🔴 Blocked | User | "Blocked. Review the findings and decide: fix or re-plan." |
| ✅ Done | User | "Already completed. Pick a new task with `/bikerflow next`." |

### Step 4: Execute

If routing to an agent, **execute the corresponding slash command immediately**. Do not just suggest it — run it:

- `/plan <task-id>` → Load planner skill and run
- `/test <task-id> red` → Load tester skill in RED mode
- `/develop <task-id>` → Load developer skill
- `/test <task-id> green` → Load tester skill in GREEN mode
- `/validate <task-id>` → Load validator skill

This is the one command that chains agents together. The user says `/bikerflow work US-01` and the system figures out what needs to happen next and does it.

---

## EXPLAIN (`explain`)

Present this explanation to the user:

```
═══════════════════════════════════════════════════
📖 THE BIKERFLOW TDD PIPELINE
═══════════════════════════════════════════════════

Every feature goes through 6 gates. No shortcuts.

STEP 1: PLAN (Planner Agent)
  /plan US-01 <description>
  ├── Reads PRD + Tech Docs
  ├── Maps business rules (BR-01 through BR-06)
  ├── Produces blueprint with acceptance criteria
  └── Output: docs/plans/US-01-<slug>.md
      Status: 🔵 → 🟡 Planned

STEP 2: TEST RED (Tester Agent)
  /test US-01 red
  ├── Reads the plan's acceptance criteria
  ├── Writes failing tests for every AC and BR
  ├── Runs tests — ALL must FAIL (that's the point)
  └── Output: tests/Feature/... and tests/Unit/...
      Status: 🟡 → 🟥 Tests RED

STEP 3: DEVELOP (Developer Agent)
  /develop US-01
  ├── Gate check: plan exists? tests RED? ✓
  ├── Reads plan + studies failing tests
  ├── Implements: migrations → models → services → controllers
  ├── Runs tests after EVERY file
  ├── Runs full regression suite
  └── Output: app/ code, database/migrations/
      Status: 🟥 → 🟠 In Development

STEP 4: TEST GREEN (Tester Agent)
  /test US-01 green
  ├── Runs the feature tests
  ├── Runs the FULL regression suite
  ├── ALL must pass — no exceptions
  └── If RED: back to Developer with failure report
      Status: 🟠 → 🟩 Tests GREEN

STEP 5: VALIDATE (Validator Agent)
  /validate US-01
  ├── Phase 1: PRD compliance — does code match the spec?
  ├── Phase 2: Financial accuracy — BCMath, DECIMAL types?
  ├── Phase 3: Security — container, auth, input validation?
  ├── Phase 4: Database — schema matches plan?
  ├── Phase 5: Test coverage — every AC/BR has a test?
  ├── Phase 6: Regression — nothing broken?
  └── Phase 7: Verdict — PASS or FAIL
      Output: docs/audits/US-01-audit.md
      Status: 🟩 → 🟢 Validated (or 🔴 Blocked)

STEP 6: MERGE (You)
  git checkout main && git merge <branch>
      Status: 🟢 → ✅ Done

═══════════════════════════════════════════════════
⚡ SHORTCUT: /bikerflow work <task-id>
  Automatically routes to the correct step.
═══════════════════════════════════════════════════
```

---

## PIPELINE (`pipeline`)

Present a visual of ALL user stories and where they are in the pipeline:

### Step 1: Read the Board

Read `docs/progress.md` in full.

### Step 2: Visual Pipeline

```
═══════════════════════════════════════════════════
🔄 BIKERFLOW PIPELINE — All Features
═══════════════════════════════════════════════════

              Plan    Test     Dev     Test    Valid   Merge
              /plan   RED     /dev    GREEN   /val    merge
US-01 ─────── 🔵  │    🔵  │    🔵  │    🔵  │    🔵  │   🔵
US-02 ─────── 🔵  │    🔵  │    🔵  │    🔵  │    🔵  │   🔵
US-03 ─────── 🔵  │    🔵  │    🔵  │    🔵  │    🔵  │   🔵
US-04 ─────── 🔵  │    🔵  │    🔵  │    🔵  │    🔵  │   🔵
                  │         │         │         │         │
               🟡  │    🟥  │    🟠  │    🟩  │    🟢  │   ✅

Legend: 🔵=Not Started  🟡=Planned  🟥=Tests RED  🟠=Dev
        🟩=Tests GREEN  🟢=Validated  ✅=Done  🔴=Blocked

Phase 1 (Foundation):       <count>/<total> ✅
Phase 2 (Shift Management): <count>/<total> ✅
Phase 3 (Payout Engine):    <count>/<total> ✅
Phase 4 (Payment Int.):     <count>/<total> ✅
Phase 5 (Dashboards):       <count>/<total> ✅

Overall Progress: <percentage>% complete

═══════════════════════════════════════════════════
```

For each user story, replace the 🔵 with the ACTUAL current status icon at the correct pipeline position. Only show the icon for the CURRENT step — earlier steps get ✅, later steps get `·`.

Example for US-01 that has tests RED:
```
US-01 ─────── ✅ ──── 🟥 ──── ·  ──── ·  ──── ·  ──── ·
```

---

## SESSION STARTUP GUIDE

If this is the start of a new work session, suggest this routine to the user:

```
📌 New Session Checklist:

1. /bikerflow              — See where things stand
2. /bikerflow next         — Get the next task suggestion
3. /bikerflow work <task>  — Jump straight in (auto-routes)
4. /status                 — Quick progress check anytime

💡 Pro Tips:
   • Always start with /bikerflow to orient yourself
   • Use /bikerflow work US-XX as your one-command workflow
   • It auto-detects where the task is and runs the right agent
   • If something goes wrong: /bikerflow status to see the board
   • After any agent finishes, /bikerflow next tells you what's next
```

Do not execute any agent. Present this as guidance and wait for the user's command.
