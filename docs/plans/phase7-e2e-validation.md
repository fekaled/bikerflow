# Phase 7: End-to-End Validation

**Plan ID:** phase7-e2e-validation
**Created:** 2026-05-14
**Parent Plan:** `docs/plans/subagent-architecture-observability.md`
**Status:** ✅ Stages 0–4, 6–7 Complete — Phase 8 Cleanup Done
**Complexity:** Medium (manual validation + targeted fixes)

---

## Objective

Validate that the full BikerFlow subagent architecture works end-to-end: from a user issuing `/tdd US-01` through all 5 pipeline stages to the final dashboard summary. Identify and fix any integration gaps before declaring the architecture production-ready.

---

## Pre-Validation Gap Analysis

### Known Gaps (must fix before E2E run)

| # | Gap | Severity | Module | Fix |
|---|-----|----------|--------|-----|
| G-1 | **Missing tracker agent** — `.pi/agents/tracker.md` does not exist. The `full-tdd` workflow's 5th stage will fail with `Unknown agent: "tracker"`. | 🔴 Blocker | agents | Create `.pi/agents/tracker.md` from `.pi/skills/tracker/SKILL.md` |
| G-2 | **Missing sandbox agent** — `.pi/agents/sandbox.md` does not exist. While not used in current workflows, discoverability is incomplete. | 🟡 Minor | agents | Create `.pi/agents/sandbox.md` from `.pi/skills/sandbox/SKILL.md` |
| G-3 | **Incomplete .gitignore** — Only `docs/agents/logs/*.jsonl` is gitignored. The plan specifies `docs/agents/logs/` and `docs/agents/pipelines/`. Pipeline manifests could leak into git. | 🟡 Minor | gitignore | Add `docs/agents/pipelines/` to `.gitignore` |
| G-4 | **No `.gitkeep` files** — `docs/agents/logs/` and `docs/agents/pipelines/` exist but have no `.gitkeep` to preserve empty dirs. | 🟢 Trivial | gitignore | Add `.gitkeep` files |

### Potential Risks (to verify during E2E)

| # | Risk | How to Detect |
|---|------|---------------|
| R-1 | `pi --mode json` event types differ from expected `type` field names | Trace logs will be empty/incomplete; compare against actual stream |
| R-2 | Message accumulation (`messages[]`) may include tool result messages with large payloads | Memory pressure during long pipelines; check `SubagentResult.messages` size |
| R-3 | Temp prompt file cleanup may fail if process crashes | Leftover files in `storage/framework/pi-subagent-prompts/` |
| R-4 | `{previous}` context too large for next stage's prompt | Next stage may hit context limits or produce degraded output |
| R-5 | Cost accumulation may be inaccurate if `msg.usage.cost` structure differs | Compare `totalCost` in manifest against provider billing |
| R-6 | Dashboard TUI rendering may fail outside of pi's jiti runtime | `showDashboard()` should fall back to `ctx.ui.notify()` — verify fallback path |

---

## Validation Plan

### Stage 0: Pre-Flight Fixes

**Prerequisite:** Fix all known gaps (G-1 through G-4) before running E2E tests.

**Steps:**
1. Create `.pi/agents/tracker.md` with YAML frontmatter (name, description, tools: `read,write,edit`) + body from `.pi/skills/tracker/SKILL.md`
2. Create `.pi/agents/sandbox.md` with YAML frontmatter (name, description, tools: `bash`) + body from `.pi/skills/sandbox/SKILL.md`
3. Add `docs/agents/pipelines/` to `.gitignore`
4. Add `.gitkeep` to `docs/agents/logs/` and `docs/agents/pipelines/`
5. Verify extension loads: start pi, run `/agents` — should show "No pipelines found" (not an error)
6. Verify agent discovery: invoke `subagent` tool with `persona: "tracker"` and `task: "test"` — should NOT return "Unknown agent"

**Done when:** All 6 agents are discoverable, extension loads without errors, gitignore is correct.

---

### Stage 1: Single-Agent Smoke Test

**Goal:** Verify that a single subagent spawns, runs, and produces correct observability data.

**Steps:**
1. Invoke the `subagent` tool with:
   ```json
   { "persona": "planner", "task": "Read docs/bikerflow-prd.md and list the 6 business rules", "taskId": "SMOKE-01" }
   ```
2. Wait for completion (should take 10–30 seconds)
3. **Verify trace log:**
   - Check `docs/agents/logs/` for a file matching `*-planner-SMOKE_01.jsonl`
   - Verify it contains `agent_start`, `turn_start`, `tool_execution_start`, `tool_execution_end`, `message_end`, `turn_end`, `agent_end` events
   - Verify args are sanitized (write tool `content` stripped, bash output truncated)
4. **Verify tool result:**
   - `exitCode === 0`
   - `finalOutput` contains text about business rules
   - `usage.turns >= 1`
   - `usage.cost > 0`
   - `traceSummary.totalDurationMs > 0`
5. **Verify `/agents logs planner`:**
   - Shows trace output with turns and tool calls
6. **Cleanup:** Delete the smoke trace log

**Done when:** Single subagent runs successfully with full observability.

---

### Stage 2: Plan-Only Pipeline (2 stages)

**Goal:** Validate the simplest chain — `planner → tracker` — with pipeline manifest and stage handoff.

**Steps:**
1. Run: `/agents run plan-only phase-1 core schema and payout formula`
2. **During execution:**
   - Run `/agents` in another terminal — verify pipeline status shows stage progress
   - Verify manifest file created at `docs/agents/pipelines/plan-only-phase-1-*.json`
3. **After planner completes, verify:**
   - Stage 1 status = `completed`
   - `outputSummary` contains plan file path
   - `outputArtifacts` lists `docs/plans/...` file
   - `personaData.type === "planner"` with `planFilePath` populated
   - `turns >= 1`, `cost > 0`, `durationMs > 0`
   - Trace log written with correct events
4. **After tracker completes, verify:**
   - Stage 2 status = `completed` (if tracker agent exists; if not, verify graceful failure)
   - Pipeline manifest status = `completed` (or `failed` if tracker missing)
   - `{previous}` was substituted into tracker's task
5. **Run `/agents`:**
   - Dashboard shows both stages with summaries
6. **Run `/agents log <pipeline-id>`:**
   - Shows structured manifest with both stages

**Done when:** 2-stage pipeline completes (or fails gracefully on tracker), manifest and trace logs are correct.

---

### Stage 3: Full TDD Pipeline (5 stages) — Dry Run

**Goal:** Run the complete `/tdd` pipeline with a scoped task that exercises all 5 stages.

**Task candidate:** `BR-03 manual release payout formula` — a focused, self-contained business rule that:
- Planner can blueprint in isolation (read PRD, output plan)
- Tester can write targeted failing tests for (payout formula unit tests)
- Developer can implement (a service class + migration)
- Validator can audit (formula correctness, BCMath usage)
- Tracker can update progress for

> **Note:** This is a *validation run*, not a production feature. We're testing the pipeline mechanics, not the feature quality. The generated plan/tests/code may be imperfect — that's acceptable.

**Steps:**
1. Ensure devcontainer is running: `docker ps` should show `devcontainer_app_1` and `devcontainer_db_1`
2. Run: `/tdd BR-03 manual release payout formula`
3. **Monitor in real-time** using a second terminal:
   - Run `/agents` periodically to check stage progression
   - Check `docs/agents/pipelines/` for manifest updates
   - Check `docs/agents/logs/` for new trace files
4. **After each stage, verify the manifest** (read the JSON file):
   - Stage status transitions: `pending → running → completed`
   - `currentStageIndex` advances
   - Hook data populated correctly (see Expected Hook Outputs below)
5. **After pipeline completes:**
   - Verify pipeline status = `completed`
   - Verify 5 trace log files exist
   - Run `/agents` — shows all 5 stages with summaries
   - Run `/agents summary` — shows aggregate stats
   - Run `/agents logs planner BR-03` — shows planner trace
   - Run `/agents log <pipeline-id>` — shows full manifest

**Expected Hook Outputs:**

| Stage | personaData.type | Key Fields |
|-------|-----------------|------------|
| planner | `"planner"` | `planFilePath`: path to `docs/plans/BR-03-*.md` |
| tester | `"tester"` | `testFiles[]`: at least 1 test file, `redCount > 0` |
| developer | `"developer"` | `filesCreated[]`: migrations/models/services, `testsPassing: true` |
| validator | `"validator"` | `verdict`: `"PASS"` or `"FAIL"` (not `"UNKNOWN"`) |
| tracker | `"tracker"` | `progressUpdated: true` |

**Done when:** Full 5-stage pipeline runs from start to finish with correct observability at every step.

---

### Stage 4: Error Handling Validation

**Goal:** Verify the pipeline handles failures gracefully.

#### Test 4a: Invalid Persona
1. Invoke `subagent` tool with:
   ```json
   { "persona": "nonexistent", "task": "do something", "taskId": "ERR-01" }
   ```
2. **Verify:** Returns `exitCode: 1`, `stopReason: "error"`, helpful error message listing available agents
3. **Verify:** Trace log created with `agent_start` and `agent_end` (error) events

#### Test 4b: Invalid Workflow
1. Run: `/agents run nonexistent-workflow test task`
2. **Verify:** Returns error result: `Workflow "nonexistent-workflow" not found`

#### Test 4c: Intentional Stage Failure
1. Create a chain that will fail at stage 2:
   ```json
   {
     "chain": [
       { "persona": "planner", "task": "Read AGENTS.md" },
       { "persona": "nonexistent", "task": "This will fail" }
     ],
     "taskId": "ERR-02"
   }
   ```
2. **Verify:** Stage 1 completes, stage 2 fails
3. **Verify:** Pipeline manifest shows stage 1 `completed`, stage 2 `failed`
4. **Verify:** Pipeline status = `failed`, `errorMessage` populated
5. **Verify:** No further stages are spawned

#### Test 4d: Tool Scoping Enforcement
1. Invoke `subagent` with:
   ```json
   { "persona": "planner", "task": "Write a test file to tests/Feature/ScopeTest.php", "taskId": "SCOPE-01" }
   ```
2. **Verify:** Planner (tools: `read,grep,find,ls,bash`) should NOT have `write` tool available
3. **Verify:** If planner attempts to write, it fails gracefully; trace log records the failed tool call

**Done when:** All error cases produce correct pipeline state and useful error messages.

---

### Stage 5: Abort Handling Validation

**Goal:** Verify Ctrl+C mid-pipeline is handled correctly.

**Steps:**
1. Start a long-running pipeline:
   ```
   /tdd Phase-1 core schema and payout formula
   ```
2. Wait for stage 1 (planner) to start (check `/agents`)
3. **Send Ctrl+C** (or abort signal) while planner is running
4. **Verify:**
   - Subagent process receives SIGTERM → SIGKILL
   - Pipeline manifest: planner stage status = `aborted`, pipeline status = `aborted`
   - Trace log finalized with `stopReason: "aborted"`
   - No further stages spawned
   - Temp prompt file cleaned up

**Done when:** Abort produces clean pipeline state with no orphan processes or files.

---

### Stage 6: Dashboard Validation

**Goal:** Verify all `/agents` subcommands produce correct output.

| Command | Expected Output | Verify |
|---------|----------------|--------|
| `/agents` | Most recent pipeline status with all stages | Correct icons, durations, costs |
| `/agents summary` | 30-day aggregate stats | Persona counts, averages, totals |
| `/agents logs planner` | Trace log for planner | Tool calls with timings, turn breakdown |
| `/agents logs planner BR-03` | Trace log filtered by taskId | Correct trace file selected |
| `/agents log <pipeline-id>` | Full manifest dump | All fields populated |
| `/agents run plan-only test task` | Starts new pipeline | Notification of start + completion |

**Done when:** All dashboard commands render correctly (TUI or plain-text fallback).

---

### Stage 7: Observability Data Quality Audit

**Goal:** Verify the trace logs and manifests contain structurally correct, useful data.

**Steps:**
1. Collect all trace logs from previous stages
2. For each trace log, verify:
   - [ ] First line is `agent_start` with `persona`, `task`, `taskId`
   - [ ] Last line is `agent_end` with `exitCode`, `stopReason`, `totalTurns`, `totalDurationMs`, `totalCost`
   - [ ] `tool_execution_start` events have sanitized `args` (no file contents)
   - [ ] `tool_execution_end` events have `success`, `durationMs`
   - [ ] `message_end` events have `tokens` with `input`, `output`, `cost`
   - [ ] Timestamps are valid ISO-8601 and monotonically increasing
   - [ ] No duplicate events
   - [ ] JSONL is valid (each line parses independently)
3. For the pipeline manifest, verify:
   - [ ] All 5 stages have correct status transitions
   - [ ] `personaData` discriminated union is correct per stage
   - [ ] `outputArtifacts` lists real files that exist on disk
   - [ ] `cost` values are non-negative and reasonable (< $5 per stage for this workload)
   - [ ] `durationMs` values are reasonable (> 1s per stage)
   - [ ] `traceLog` paths point to real files

**Done when:** All trace logs and manifests pass structural validation.

---

## Success Criteria

Phase 7 is **DONE** when all of the following are true:

- [x] **G-1 through G-4 fixed** — All agents exist, gitignore complete
- [ ] **Single-agent smoke test passes** — Planner runs with full trace
- [ ] **Plan-only pipeline passes** — 2-stage chain with manifest
- [ ] **Full TDD pipeline passes** — 5-stage chain for BR-03 (or at minimum 4 stages if tracker is deferred)
- [ ] **Error handling verified** — Invalid persona, workflow, stage failure all produce correct state
- [ ] **Abort handling verified** — Ctrl+C produces clean aborted state
- [ ] **Dashboard commands work** — All `/agents` subcommands produce useful output
- [ ] **Trace logs pass quality audit** — Structurally correct JSONL with all expected events
- [ ] **Pipeline manifests pass quality audit** — Correct stage transitions, populated hook data

---

## Estimated Timeline

| Stage | Type | Duration |
|-------|------|----------|
| Stage 0: Pre-Flight Fixes | Code | 15 min |
| Stage 1: Single-Agent Smoke | Manual | 5 min |
| Stage 2: Plan-Only Pipeline | Manual | 10 min |
| Stage 3: Full TDD Pipeline | Manual | 30–60 min (depends on LLM speed) |
| Stage 4: Error Handling | Manual | 10 min |
| Stage 5: Abort Handling | Manual | 5 min |
| Stage 6: Dashboard Validation | Manual | 10 min |
| Stage 7: Data Quality Audit | Manual | 15 min |
| **Total** | | **~1.5–2 hours** |

---

## Rollback Plan

If critical issues are discovered:
1. Extension code is TypeScript in `.pi/extensions/` — changes are instant (jiti re-evaluates)
2. No database changes — all state is file-based
3. Trace logs and pipeline manifests can be deleted: `rm -rf docs/agents/logs/* docs/agents/pipelines/*`
4. Git snapshot before starting: `git add -A && git commit -m "pre-phase7-snapshot"`

---

## Post-Validation (Phase 8 Transition)

After Phase 7 succeeds:
- Phase 8 (Cleanup) becomes trivial — most gitignore work is done in Stage 0
- Decision point: keep skills alongside agents (recommended) or deprecate skills
- Update `AGENTS.md` if the workflow changes how developers interact with the system
