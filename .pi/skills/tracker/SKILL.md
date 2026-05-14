---
name: tracker
description: Project progress tracking. Reads and updates docs/progress.md — the single source of truth for TDD pipeline status, business rule enforcement, and agent activity. Use after any agent completes a phase to record progress.
---

# 📊 Tracker — Project Progress Skill

## Purpose

Maintain a living progress board at `docs/progress.md` that tracks every User Story, Business Rule, and Core Entity through the **TDD pipeline**.

## The Progress File

**Location:** `docs/progress.md`

This file is the **single source of truth**. Every agent updates it after completing their work. You (the orchestrator) read it to decide what to do next.

## TDD Pipeline Flow

The canonical flow for every feature:

```
🔵 Not Started → 🟡 Planned → 🟥 Tests RED → 🟠 In Development → 🟩 Tests GREEN → 🟣 In Validation → 🟢 Validated → ✅ Done
```

Any step can route to 🔴 Blocked if user intervention is needed.

## Status Icons

| Icon | Status | When to Set |
|------|--------|-------------|
| 🔵 | Not Started | Default — no work done yet |
| 🟡 | Planned | After Planner produces a blueprint |
| 🟥 | Tests RED | After Tester writes failing tests (TDD RED phase) |
| 🟠 | In Development | After Developer starts coding |
| 🟩 | Tests GREEN | After Tester confirms all tests pass |
| 🟣 | In Validation | After Validator starts auditing |
| 🔴 | Blocked | Needs user decision or external input |
| 🟢 | Validated | After Validator approves |
| ✅ | Done | Merged to `main` |

## How to Update Progress

### Reading Status

Read `docs/progress.md` directly. The file is self-documenting with a status legend.

### Updating Status

Use the `edit` tool to modify specific cells in the markdown tables. **Only change the fields relevant to the agent that just completed work.**

#### Agent-Specific Update Rules

**After Planner completes (`/plan`):**
- Set the User Story status to 🟡 (Planned)
- Fill the `Plan` column with the plan file path (e.g., `docs/plans/US-01-trip-sheet-pdf.md`)
- Add entry to Agent Activity Log

**After Tester — RED mode (`/test <task> red`):**
- Set the User Story status to 🟥 (Tests RED)
- Fill the `Tests (RED)` column with test file paths
- Add entry to Agent Activity Log noting "Tests written — RED phase"

**After Developer completes (`/develop`):**
- Set the User Story status to 🟠 (In Development)
- Update Core Entity tables for any migrations/models/controllers created
- Fill file paths in the Entity table columns
- Add entry to Agent Activity Log

**After Tester — GREEN mode (`/test <task> green`):**
- If all tests GREEN:
  - Set the User Story status to 🟩 (Tests GREEN)
  - Fill the `Tests (GREEN)` column with "✅ All pass, 0 regressions"
  - Add entry to Agent Activity Log noting "Tests confirmed GREEN"
- If tests still RED:
  - Keep status at 🟠 (In Development)
  - Add entry to Agent Activity Log noting "Tests still RED — Developer fix required" with specific failures
  - Do NOT advance the status

**After Validator completes (`/validate`):**
- Set the User Story status to 🟣 → 🟢 (Validated)
- Fill the `Audit` column with the audit report path
- Update Business Rules table — fill `Verified By` column
- Add entry to Agent Activity Log

**After merge to main:**
- Set the User Story status to ✅ (Done)
- Add entry to Agent Activity Log

### Activity Log Format

Add a new row at the **top** of the Agent Activity Log table (newest first):

```markdown
| YYYY-MM-DD | Agent Name | Brief Action | Key details — file paths, decisions, outcomes |
```

### Phase Progression

When all User Stories in a phase reach ✅, update the Phase Overview table to mark that phase as ✅ and set the next phase to 🔵 (if not already).

## Architecture Decision Records (ADR)

When a pipeline completes and produces architectural decisions (new schemas, enums, state machines, service designs), create an ADR entry:

1. **Check** `docs/adr/` for the next available number (increment from the highest existing).
2. **Copy** `docs/adr/TEMPLATE.md` to `NNN-short-title.md`.
3. **Fill** all sections using the pipeline manifest (`docs/agents/pipelines/`) and the plan file (`docs/plans/`) as sources.
4. **Update** `docs/adr/README.md` index table with the new entry.
5. **Archive** the pipeline manifest by copying it to `docs/archives/pipelines/`.
6. **Add** `@see docs/adr/NNN-*.md` comments to the affected code files.

### When to Create an ADR

- After **Validator** approves a pipeline that introduces new schema, models, enums, or services.
- After any **manual** architectural decision that affects multiple files.
- When **superseding** a previous decision — update the old ADR's status to "Superseded by ADR-{NNN}".

### ADR Naming

- `NNN-kebab-case-title.md` (zero-padded 3-digit number)
- Numbers are sequential and **never reused**.

## Constraints

- **Never delete rows** from any table. Only add or update.
- **Never change status backwards** without user instruction (e.g., 🟢 → 🟠 is invalid unless explicitly requested).
- **Never skip TDD phases.** The flow must go through 🟥 (RED) before 🟠 (Development) before 🟩 (GREEN). If a status jump is attempted, flag it.
- **Always update the timestamp** in the header when making changes.
- **Always add an Activity Log entry** for every update.
- **Preserve the file structure** — do not reorder sections or change table formats.
