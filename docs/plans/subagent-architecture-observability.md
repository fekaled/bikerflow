# Subagent Architecture with Full Observability

**Plan ID:** subagent-architecture
**Created:** 2026-05-13
**Status:** ✅ All Phases Complete (1–8)
**Complexity:** Complex (8 phases, multi-module TypeScript extension)

---

## Problem Statement

All 6 BikerFlow personas (planner, tester, developer, validator, tracker, sandbox) run as **skills** loaded into the primary agent's context. This causes:

1. **Context pollution** — Every persona's SKILL.md + references bloats the system prompt
2. **Identity confusion** — The model role-plays different archetypes in the same context window
3. **No isolation** — State and decisions from one persona leak into the next
4. **Token waste** — Loading developer references when running the planner
5. **No observability** — No structured logging of tool calls, costs, or pipeline state

## Solution

Spawn each persona as a **separate `pi` subprocess** (`pi --mode json`) via a custom extension. Each subagent gets its own context window, system prompt, scoped tools, and optional model selection. A structured logging layer captures full execution traces. A stop hook system manages pipeline progression between stages.

---

## Directory Structure

```
.pi/
├── extensions/
│   └── bikerflow-subagents/
│       ├── index.ts              # Extension entry: registers tool + commands
│       ├── subagent.ts           # Core subagent spawning + JSON stream processing
│       ├── observability.ts      # Trace logger + sanitizer
│       ├── pipeline.ts           # Pipeline manifest management + stop hook
│       ├── hooks.ts              # Per-persona stop hooks
│       └── dashboard.ts          # /agents command rendering
├── agents/                       # Persona definitions (system prompts with YAML frontmatter)
│   ├── planner.md
│   ├── tester.md
│   ├── developer.md
│   ├── validator.md
│   ├── tracker.md
│   └── sandbox.md
├── workflows/                    # Pipeline definitions (which personas in what order)
│   ├── full-tdd.md              # planner → tester → developer → validator → tracker
│   ├── plan-only.md             # planner → tracker
│   └── implement-only.md        # developer → validator → tracker
└── prompts/                      # Workflow prompt templates
    ├── tdd.md                   # /tdd <US-XX>
    ├── plan.md                  # /plan <task>
    └── implement.md             # /implement <task>
```

Generated at runtime (gitignored):

```
docs/agents/
├── logs/                         # Per-run trace logs (JSONL)
│   ├── 2026-05-13-planner-US-01.jsonl
│   ├── 2026-05-13-tester-US-01.jsonl
│   └── 2026-05-13-developer-US-01.jsonl
└── pipelines/                    # Pipeline manifests (JSON)
    ├── tdd-US-01-20260513-143000.json
    └── plan-BR-03-20260513-150000.json
```

---

## Agent Definitions

Each persona's current `SKILL.md` is adapted into a `.pi/agents/*.md` file with YAML frontmatter:

```yaml
---
name: planner
description: The Rigorous Architect
tools: read, grep, find, ls, bash
model: claude-sonnet-4-5
---

<full persona instructions as system prompt>
```

### Tool Scoping Per Persona

| Persona | Tools | Rationale |
|---------|-------|-----------|
| **planner** | read, grep, find, ls, bash | Read-only + bash for reading docs |
| **tester** | read, grep, find, ls, bash, write, edit | Writes test files, runs them |
| **developer** | all default | Full capabilities for implementation |
| **validator** | read, grep, find, ls, bash | Audits, runs tests, doesn't write code |
| **tracker** | read, write, edit | Updates `docs/progress.md` |
| **sandbox** | bash | Container management only |

### Skill Files → Agent Files Mapping

| Source | Destination |
|--------|-------------|
| `.pi/skills/planner/SKILL.md` | `.pi/agents/planner.md` |
| `.pi/skills/tester/SKILL.md` | `.pi/agents/tester.md` |
| `.pi/skills/developer/SKILL.md` | `.pi/agents/developer.md` |
| `.pi/skills/validator/SKILL.md` | `.pi/agents/validator.md` |
| `.pi/skills/tracker/SKILL.md` | `.pi/agents/tracker.md` |
| `.pi/skills/sandbox/SKILL.md` | `.pi/agents/sandbox.md` |

Reference files under each skill (e.g., `references/coding-standards.md`) are referenced by path inside the agent's system prompt body — the subagent reads them at runtime.

---

## Observability Layer

### Trace Log Format

Each subagent run produces a trace file at `docs/agents/logs/<date>-<persona>-<task-id>.jsonl`.

**Events captured from the `pi --mode json` stream:**

| JSON Event | What We Log |
|------------|-------------|
| `agent_start` | Timestamp, persona name, task description, task ID |
| `turn_start` / `turn_end` | Turn index, duration |
| `message_start` / `message_end` | Role, content length, usage stats (input/output tokens, cost) |
| `tool_execution_start` | Tool name, arguments (sanitized) |
| `tool_execution_update` | Progress markers |
| `tool_execution_end` | Tool name, success/failure, result summary, duration |
| `agent_end` | Final status, total usage, stop reason |

**Example trace log:**

```jsonl
{"ts":"2026-05-13T14:30:00.123Z","event":"agent_start","persona":"planner","task":"US-01 trip tracking","taskId":"US-01"}
{"ts":"2026-05-13T14:30:01.456Z","event":"turn_start","turn":1}
{"ts":"2026-05-13T14:30:01.500Z","event":"message_start","role":"assistant"}
{"ts":"2026-05-13T14:30:05.200Z","event":"tool_execution_start","tool":"read","args":{"path":"docs/bikerflow-prd.md"},"turn":1}
{"ts":"2026-05-13T14:30:05.800Z","event":"tool_execution_end","tool":"read","success":true,"durationMs":600,"resultSummary":"Read 450 lines","turn":1}
{"ts":"2026-05-13T14:30:12.000Z","event":"message_end","role":"assistant","tokens":{"input":2500,"output":800,"cost":0.035},"turn":1}
{"ts":"2026-05-13T14:30:12.100Z","event":"turn_end","turn":1,"durationMs":10644}
{"ts":"2026-05-13T14:30:25.000Z","event":"agent_end","stopReason":"end_turn","totalTurns":3,"totalDurationMs":25000,"totalCost":0.087,"output":"Plan saved to docs/plans/US-01-trip-tracking.md"}
```

### Log Sanitizer

Tool arguments can contain large/sensitive data. The sanitizer strips:

- **`write` tool**: Remove `content` field from args (keep `path` and content length)
- **`edit` tool**: Keep `path`, `oldText`/`newText` length only (strip full content)
- **`bash` tool**: Keep `command` as-is; truncate stdout/stderr in result summary to 500 chars
- **`read` tool**: Keep `path`, `offset`, `limit` (strip file contents from result)
- All other tool args pass through as-is

Sanitization happens inside the JSON stream processor, **before** writing to disk.

---

## Stop Hook System

### Concept

A stop hook fires when a subagent process completes (success, failure, or abort). It has three responsibilities:

1. **Finalize the trace log** — append the `agent_end` summary event and close the file
2. **Generate a persona summary** — structured output for the next pipeline stage and for the tracker
3. **Update the pipeline manifest** — the shared state file that tracks the overall workflow

### Pipeline Manifest

Located at `docs/agents/pipelines/<pipeline-id>.json`:

```json
{
  "pipelineId": "tdd-US-01-20260513-143000",
  "taskId": "US-01",
  "workflow": "full-tdd",
  "status": "running",
  "startedAt": "2026-05-13T14:30:00.000Z",
  "currentStage": "developer",
  "stages": [
    {
      "persona": "planner",
      "status": "completed",
      "startedAt": "2026-05-13T14:30:00.000Z",
      "completedAt": "2026-05-13T14:30:25.000Z",
      "durationMs": 25000,
      "turns": 3,
      "cost": 0.087,
      "traceLog": "docs/agents/logs/2026-05-13-planner-US-01.jsonl",
      "outputSummary": "Plan saved to docs/plans/US-01-trip-tracking.md. 3 affected tables, 8 files to create.",
      "outputArtifacts": ["docs/plans/US-01-trip-tracking.md"]
    },
    {
      "persona": "tester",
      "status": "completed",
      "startedAt": "2026-05-13T14:30:26.000Z",
      "completedAt": "2026-05-13T14:31:15.000Z",
      "durationMs": 49000,
      "turns": 5,
      "cost": 0.142,
      "traceLog": "docs/agents/logs/2026-05-13-tester-US-01.jsonl",
      "outputSummary": "12 failing tests written across 3 test files. All RED.",
      "outputArtifacts": [
        "tests/Feature/ShiftManagementTest.php",
        "tests/Unit/PayoutCalculationTest.php",
        "tests/Feature/TripTrackingTest.php"
      ]
    },
    {
      "persona": "developer",
      "status": "running",
      "startedAt": "2026-05-13T14:31:16.000Z",
      "traceLog": "docs/agents/logs/2026-05-13-developer-US-01.jsonl"
    },
    {
      "persona": "validator",
      "status": "pending"
    },
    {
      "persona": "tracker",
      "status": "pending"
    }
  ]
}
```

### Stop Hook Flow

```
Subagent process exits
  │
  ├─► 1. Finalize trace log
  │     • Append agent_end event with aggregate stats
  │     • Close the JSONL file handle
  │
  ├─► 2. Compute stage summary
  │     • Extract: turns, tokens, cost, duration, output text, stop reason
  │     • Extract: file artifacts created/modified (from write/edit tool calls in trace)
  │     • Classify: success / failure / abort
  │
  ├─► 3. Update pipeline manifest
  │     • Set stage status → completed / failed / aborted
  │     • Fill durationMs, turns, cost, outputSummary, outputArtifacts
  │     • Set next stage status as currentStage (if chain continues)
  │
  ├─► 4. If chain mode and stage succeeded: trigger next stage
  │     • Inject {previous} placeholder with stage summary
  │     • Spawn next subagent
  │
  └─► 5. If final stage completed or stage failed: finalize pipeline
        • Set pipeline status → completed / failed
        • Print pipeline summary to the user
```

### Error Handling

| Condition | Action |
|-----------|--------|
| Subagent exit code != 0 | Mark stage `failed`, log stderr, **stop chain** |
| `stopReason: "error"` | Mark stage `failed`, log error message, **stop chain** |
| `stopReason: "aborted"` (user Ctrl+C) | Mark stage `aborted`, **stop chain** |
| Stage fails mid-chain | Don't spawn next stage. Report which stage failed + why. |
| Trace log write I/O error | Log to stderr, continue (observability is non-blocking) |

---

## Module Responsibilities

| Module | Responsibility |
|--------|---------------|
| **index.ts** | Extension entry point. Registers `subagent` tool, `/agents` command, workflow prompt templates, `/tdd`, `/plan`, `/implement` commands. |
| **subagent.ts** | Spawns `pi --mode json` subprocess. Processes JSON event stream. Feeds all events to observability layer. Returns final output + metadata. Based on pi's `examples/extensions/subagent/index.ts` but enhanced with full event capture. |
| **observability.ts** | `TraceLogger` class — writes sanitized JSONL events to trace log files. `LogSanitizer` — strips sensitive/large payloads. Tracks per-run aggregate stats. |
| **pipeline.ts** | `PipelineManager` class — creates/reads/updates pipeline manifest JSON. Manages chain progression. Invokes stop hooks after each stage. |
| **hooks.ts** | Per-persona stop hook functions. Each extracts structured output from the subagent's final message: `plannerStopHook` extracts plan file path; `testerStopHook` extracts test results; `developerStopHook` extracts files created; `validatorStopHook` extracts PASS/FAIL; `trackerStopHook` confirms progress.md update. |
| **dashboard.ts** | Renders `/agents` command output. Reads pipeline manifest + trace logs. Formats cost/time summaries. |

---

## Data Flow (Full TDD Pipeline)

```
User: /tdd US-01
  │
  ▼
index.ts parses workflow, creates PipelineManager
  │
  ▼ pipeline manifest created: docs/agents/pipelines/tdd-US-01-...json
  │
  ▼ Stage 1: planner
  │   subagent.ts spawns: pi --mode json -p --no-session --tools read,grep,find,ls,bash \
  │                        --append-system-prompt @.pi/agents/planner.md \
  │                        "Task: Implement US-01 trip tracking for BikerFlow"
  │   │
  │   ├─ JSON stream → observability.ts → trace log: docs/agents/logs/2026-05-13-planner-US-01.jsonl
  │   ├─ Tool calls logged with sanitized args
  │   ├─ Usage stats accumulated per-turn
  │   │
  │   ▼ Process exits (success)
  │   │
  │   ▼ hooks.ts → plannerStopHook(result)
  │       • Parse final output → extract plan file path
  │       • pipeline.ts → mark stage 1 completed
  │       • pipeline.ts → set stage 2 as current
  │       • Return: "Plan created at docs/plans/US-01-trip-tracking.md. ..."
  │
  ▼ Stage 2: tester
  │   subagent.ts spawns with task = planner summary injected via {previous}
  │   │
  │   ├─ JSON stream → observability.ts → trace log
  │   │
  │   ▼ Process exits
  │   │
  │   ▼ hooks.ts → testerStopHook(result)
  │       • Parse final output → extract test file paths + RED status
  │       • pipeline.ts → mark stage 2 completed
  │
  ▼ Stage 3: developer
  │   subagent.ts spawns with task = tester summary injected via {previous}
  │   │
  │   ├─ JSON stream → observability.ts → trace log
  │   │
  │   ▼ Process exits
  │   │
  │   ▼ hooks.ts → developerStopHook(result)
  │       • Parse final output → extract files created/modified + GREEN test results
  │       • pipeline.ts → mark stage 3 completed
  │
  ▼ Stage 4: validator
  │   subagent.ts spawns with task = developer summary injected via {previous}
  │   │
  │   ├─ JSON stream → observability.ts → trace log
  │   │
  │   ▼ Process exits
  │   │
  │   ▼ hooks.ts → validatorStopHook(result)
  │       • Parse final output → PASS/FAIL + audit findings
  │       • pipeline.ts → mark stage 4 completed
  │
  ▼ Stage 5: tracker
  │   subagent.ts spawns with full pipeline summary as task
  │   │
  │   ├─ JSON stream → observability.ts → trace log
  │   │
  │   ▼ Process exits
  │   │
  │   ▼ hooks.ts → trackerStopHook(result)
  │       • Confirms docs/progress.md was updated
  │       • pipeline.ts → mark stage 5 completed
  │
  ▼ pipeline.ts → finalize pipeline manifest (status: completed)
  ▼ dashboard.ts → render final summary to user
```

---

## Dashboard Commands

### `/agents` — Pipeline Status

Shows current pipeline state:

```
Pipeline: tdd-US-01-20260513-143000
Task: US-01 - Trip Tracking
Workflow: full-tdd
Status: running (stage 3/5)

✓ planner    25s   3 turns   $0.087   → docs/plans/US-01-trip-tracking.md
✓ tester     49s   5 turns   $0.142   → 12 tests RED across 3 files
⏳ developer                  ... running ...
· validator   pending
· tracker     pending

Total so far: 74s  8 turns  $0.229
```

### `/agents logs <persona>` — Trace Log

Shows last trace log for a persona:

```
Trace: 2026-05-13-developer-US-01.jsonl
Persona: developer | Task: US-01 | Turns: 12 | Duration: 3m12s | Cost: $0.842

Turn 1:
  → read docs/plans/US-01-trip-tracking.md (600ms)
  → read tests/Feature/ShiftManagementTest.php (120ms)
  Assistant: "I see 12 failing tests. Starting with migrations..."

Turn 2:
  → write database/migrations/2026_05_13_000001_create_shifts_table.php (45ms)
  → write app/Models/Shift.php (38ms)
  → bash php artisan migrate (2.1s)
  → bash php artisan test --filter=ShiftManagementTest (4.8s)
  Assistant: "Shifts table created. 4/12 tests now passing."

...
```

### `/agents log <pipeline-id>` — Full Manifest

Dumps the pipeline manifest JSON in readable form.

### `/agents summary` — Aggregate Stats

```
BikerFlow Agent Summary (last 30 days)
──────────────────────────────────────
Pipelines: 12 completed, 2 failed, 1 running

Per Persona:
  planner     12 runs  avg 22s    avg 3.1 turns   total $0.94
  tester      12 runs  avg 45s    avg 4.8 turns   total $1.65
  developer   10 runs  avg 3m12s  avg 12.3 turns  total $8.42
  validator    8 runs  avg 1m05s  avg 5.2 turns   total $1.88
  tracker     12 runs  avg 8s     avg 1.0 turns   total $0.12

Total: $13.01 across 54 subagent runs
```

---

## Context Handoff Between Stages

The `{previous}` placeholder in chain tasks receives the **structured stage summary** from the stop hook:

```
[planner output]
Plan saved to: docs/plans/US-01-trip-tracking.md
Affected tables: shifts, trips, payout_batches
Files to create: 8
Files to modify: 2
Business rules: BR-01, BR-03, BR-05
Complexity: Medium

Key decisions:
- Separate trips table (not JSON column on shifts)
- BCMath for all financial columns
- Optimistic locking on shift status transitions
```

Each persona's system prompt already instructs it to **read specific files** (plans, tests, etc.). The handoff only needs to convey: file paths, summary of what was done, and any metadata. The subagent reads the actual files from disk at runtime.

---

## Gitignore

```gitignore
# Agent observability logs (operational data, not source)
docs/agents/logs/
docs/agents/pipelines/
```

Keep `docs/agents/` with `.gitkeep` files so directory structure exists.

---

## Skills vs Agents Decision

**Keep both.** Skills for interactive/manual use, agents for automated pipelines.

- **Skills** — Used when the user manually invokes a persona in the current session (e.g., "act as planner, help me think through this edge case")
- **Agents** — Used by the subagent extension for isolated pipeline execution (e.g., `/tdd US-01`)

They share the same markdown content. The agent `.md` file can reference the skill's SKILL.md via a symlink or copy.

---

## Implementation Phases

### Phase 1: Agent Definitions 🔵
**Effort:** Small
**Description:** Convert existing SKILL.md files into `.pi/agents/*.md` format (YAML frontmatter + body).
**Files:**
- Create `.pi/agents/planner.md` (from `.pi/skills/planner/SKILL.md`)
- Create `.pi/agents/tester.md` (from `.pi/skills/tester/SKILL.md`)
- Create `.pi/agents/developer.md` (from `.pi/skills/developer/SKILL.md`)
- Create `.pi/agents/validator.md` (from `.pi/skills/validator/SKILL.md`)
- Create `.pi/agents/tracker.md` (from `.pi/skills/tracker/SKILL.md`)
- Create `.pi/agents/sandbox.md` (from `.pi/skills/sandbox/SKILL.md`)
**Done when:** Each agent `.md` has valid YAML frontmatter with `name`, `description`, `tools`, and `model` fields, and the body contains the persona instructions.

### Phase 2: Observability Module 🔵
**Effort:** Medium
**Description:** Implement `TraceLogger` and `LogSanitizer`.
**Files:**
- Create `.pi/extensions/bikerflow-subagents/observability.ts`
**Key interfaces:**
- `TraceLogger.start(persona, task, taskId)` → creates trace file, returns logger
- `TraceLogger.logEvent(event)` → sanitizes + appends JSONL line
- `TraceLogger.finalize(agentEndEvent)` → writes final event, closes file
- `LogSanitizer.sanitizeArgs(toolName, args)` → strips sensitive/large fields
- `LogSanitizer.sanitizeResult(toolName, result)` → strips large outputs
**Done when:** Module can process all JSON event types from `pi --mode json` stream and write sanitized JSONL to disk.

### Phase 3: Subagent Spawning Module ✅
**Effort:** Medium
**Completed:** 2026-05-13

**Files created:**
- `.pi/extensions/bikerflow-subagents/agents.ts` — Agent discovery (104 lines)
- `.pi/extensions/bikerflow-subagents/subagent.ts` — Subagent spawning + JSON stream processing (295 lines)

**File modified:**
- `.pi/extensions/bikerflow-subagents/index.ts` — Added exports for new modules

#### Design Decisions

| Decision | Choice | Rationale |
|----------|--------|----------|
| Unknown persona handling | Return `SubagentResult` with `exitCode: 1` and error message (no exception) | Consistent with pi's subagent example pattern; caller inspects result, doesn't need try/catch |
| Pi invocation detection | `getPiInvocation()` copied inline into `subagent.ts` | Avoids a shared utils file for a single helper; can be extracted later if more modules need it |
| Event capture granularity | Full capture — all JSON stream event types fed to `TraceLogger.onEvent()` | Richer observability; larger log files are acceptable since they're gitignored and debug-valuable |
| Temp prompt file location | Project-local `storage/framework/pi-subagent-prompts/` | Easier debugging than `os.tmpdir()`; files visible in project tree; auto-cleaned after each run |
| Agent scope | Project-local only (`.pi/agents/*.md`) | BikerFlow only uses project agents; no user-level agents needed; simplifies discovery API |
| `AgentToolResult` import | From `@mariozechner/pi-coding-agent` (not `@mariozechner/pi-agent-core`) | `pi-agent-core` is bundled into `pi-coding-agent`; standalone package doesn't resolve at extension load time |
| `parseFrontmatter` import | From `@mariozechner/pi-coding-agent` | Officially re-exported from pi's public API |
| Tracker/Sandbox agents | Not created in this phase | Deferred; spawning module works with whatever agents exist at runtime |

#### Public API

```typescript
// agents.ts
export interface AgentConfig {
  name: string; description: string; tools?: string[];
  model?: string; systemPrompt: string; filePath: string;
}
export function discoverAgents(cwd: string): AgentConfig[]
export function findAgent(cwd: string, name: string): AgentConfig | undefined

// subagent.ts
export interface SubagentConfig {
  persona: string; task: string; taskId: string; cwd: string;
  logsDir: string; model?: string; signal?: AbortSignal;
  onUpdate?: (partial: AgentToolResult<SubagentResult>) => void;
}
export interface SubagentResult {
  persona: string; task: string; taskId: string;
  exitCode: number; messages: Message[]; stderr: string;
  usage: UsageStats; model?: string; stopReason?: string;
  errorMessage?: string; traceSummary: TraceSummary; finalOutput: string;
}
export async function runSubagent(config: SubagentConfig): Promise<SubagentResult>
```

#### Subagent Lifecycle (`runSubagent`)

```
1. discoverAgents(cwd) → find AgentConfig by persona name
   └─ Not found → return SubagentResult { exitCode: 1, stopReason: "error" }

2. Build pi CLI args:
   ["--mode", "json", "-p", "--no-session"]
   + model override (config.model || agent.model)
   + tools scoping (agent.tools → "--tools read,grep,...")
   + system prompt → temp file in storage/framework/pi-subagent-prompts/
   + "--append-system-prompt" <tmpPromptPath>
   + "Task: <task>"

3. TraceLogger.start(persona, task, taskId, logsDir)

4. spawn(pi, args, { cwd, shell: false })
   ├─ stdout: line-buffered JSON parsing
   │   ├─ Every event → traceLogger.onEvent(rawEvent)  [full capture]
   │   ├─ "message_end" (assistant) → accumulate usage, turns, model, stopReason
   │   └─ "tool_result_end" → accumulate messages
   ├─ stderr → accumulate error output
   └─ AbortSignal → SIGTERM → SIGKILL (5s fallback)

5. On process exit (finally block):
   ├─ traceLogger.finalize(exitCode, stopReason, finalOutput) → TraceSummary
   ├─ Clean up temp prompt file
   └─ Return SubagentResult
```

**Done when:** ✅ Can spawn a single pi subprocess for a given persona, capture all events via TraceLogger, and return structured SubagentResult with TraceSummary.

### Phase 4: Pipeline + Hooks Module ✅
**Effort:** Medium
**Completed:** 2026-05-13

**Files created:**
- `.pi/extensions/bikerflow-subagents/pipeline.ts` — Chain-aware PipelineManager (283 lines)
- `.pi/extensions/bikerflow-subagents/hooks.ts` — Per-persona stop hooks + PersonaData types (309 lines)

**File modified:**
- `.pi/extensions/bikerflow-subagents/index.ts` — Added exports for pipeline + hooks modules

**Test file:**
- `.pi/extensions/bikerflow-subagents/test-pipeline.ts` — 78 tests, all passing

#### Design Decisions

| Decision | Choice | Rationale |
|----------|--------|------------|
| Hook output extraction | Option D: artifacts from trace + regex for semantics | `outputArtifacts` from TraceLogger for file paths; lightweight regex on `finalOutput` for plan path, verdict, test counts |
| PipelineManager scope | Option B: chain-aware | `advanceStage()` knows stage order, returns next persona; orchestrator (Phase 6) doesn't need workflow definitions |
| Hook failure on missing data | Option C: partial summary + `hookWarning` | Subagent may succeed but output may not match expected patterns; warning lets Phase 6 decide |
| `{previous}` placeholder | Option A: hooks return data, Phase 6 substitutes | Placeholder substitution is orchestration; Phase 4 hooks only produce data |
| Persona-specific fields in manifest | Option A: typed `PersonaData` in `StageRecord` | Dashboard/Phase 5 can query structured verdict, planFilePath without re-parsing text |

#### Public API

```typescript
// pipeline.ts
export class PipelineManager {
  static create(params): PipelineManager     // Create new pipeline
  static read(pipelineId, dir): PM | null    // Read from disk
  static readPath(filePath): PM | null       // Read by full path
  static list(dir): PipelineManifest[]       // List all, newest-first
  advanceStage(stageIndex, result): AdvanceResult  // Complete stage, advance chain
  markStageRunning(stageIndex): void          // Mark stage as active
  finalize(status, errorMessage?): void       // Force-close pipeline
  formatSummary(): string                     // Text summary for console
  // Accessors: pipelineId, taskId, workflow, status, currentStage, currentPersona, etc.
}

// hooks.ts
export function getStopHook(persona: string): (result: SubagentResult) => StageSummary
// 6 persona hooks: planner, tester, developer, validator, tracker, sandbox
// Generic fallback for unknown personas
export type PersonaData = discriminated union (type + data) per persona
export interface StageSummary { outputSummary, outputArtifacts, personaData, hookWarning? }
```

### Phase 5: Dashboard Module ✅
**Effort:** Small
**Completed:** 2026-05-13

**File created:**
- `.pi/extensions/bikerflow-subagents/dashboard.ts` — Themed TUI rendering (570 lines)

**File modified:**
- `.pi/extensions/bikerflow-subagents/index.ts` — Added exports for all dashboard functions + DashboardTheme

**Test file:**
- `.pi/extensions/bikerflow-subagents/test-dashboard.ts` — 83 tests, all passing

#### Design Decisions

| Decision | Choice | Rationale |
|----------|--------|------------|
| Rendering approach | Container TUI components via lazy imports | Works in pi's runtime with full TUI; falls back to lightweight impl for testing |
| TUI import strategy | `require('@mariozechner/pi-tui')` with fallback classes | Avoids hard import that fails outside pi's jiti runtime |
| Trace log turn limit | Last 10 turns | Keeps view scannable; full JSONL always on disk |
| `/agents` with no args | Show most recent pipeline status | Most common use case; Phase 6 implements |
| Summary time window | Optional `since?: Date` param, default 30 days | Matches spec; trivial to implement |
| Manifest rendering | Structured key-value + stages table | More useful than raw JSON; includes persona-specific data expansion |

#### Public API

```typescript
// dashboard.ts
export function renderPipelineStatus(manifest, theme): ContainerLike
export function renderTraceLog(traceEvents, persona, taskId, theme): ContainerLike
export function renderManifest(manifest, theme): ContainerLike
export function renderSummary(manifests, theme, since?): ContainerLike
export function findTraceLog(persona, logsDir, taskId?): string | null
export function readTraceLog(filePath): TraceEvent[]
export interface DashboardTheme { fg, bg, bold }
```

### Phase 6: Extension Wiring ✅
**Effort:** Medium
**Completed:** 2026-05-14

**Files created:**
- `.pi/extensions/bikerflow-subagents/orchestrator.ts` — Chain execution engine (307 lines)
- `.pi/extensions/bikerflow-subagents/workflows.ts` — Workflow definition discovery (134 lines)
- `.pi/workflows/full-tdd.md` — Full TDD pipeline definition (5 stages)
- `.pi/workflows/plan-only.md` — Plan-only pipeline definition (2 stages)
- `.pi/workflows/implement-only.md` — Implement-only pipeline definition (4 stages)
- `.pi/prompts/tdd.md` — Prompt template for `/tdd`
- `.pi/prompts/implement.md` — Prompt template for `/implement`

**File modified:**
- `.pi/extensions/bikerflow-subagents/index.ts` — Full extension wiring (654 lines)

#### Design Decisions

| Decision | Choice | Rationale |
|----------|--------|----------|
| `/plan` and `/implement` commands | NOT registered as extension commands | Avoids shadowing existing `/plan` prompt template (interactive). Use `/agents run plan-only <task>` instead |
| `/agents run <workflow> <task>` | Added as subcommand of `/agents` | Provides access to any workflow without top-level command conflicts |
| Dashboard TUI fallback | `ctx.ui.custom()` with try/catch → `ctx.ui.notify()` fallback | Graceful degradation in non-interactive contexts |
| Workflow discovery | Walk up from cwd to find `.pi/workflows/` | Matches agent discovery pattern; project-local |
| Orchestration module | Separate `orchestrator.ts` with `executeChain` and `executeWorkflow` | Shared between tool handler and command handlers |
| Default stage task templates | Embedded in `orchestrator.ts` per persona | Each persona gets a sensible default prompt; workflow files can override |
| Tracker/sandbox agents | Not created; pipeline fails gracefully at those stages | `runSubagent` returns `exitCode: 1` with helpful error about available agents |

#### Registrations

- `subagent` tool — 3 modes: single (`persona+task`), chain (`chain` array), workflow (`workflow+task`)
- `/agents` command — subcommands: (no args=pipeline status), `logs <persona>`, `log <pipeline-id>`, `summary`, `run <workflow> <task>`
- `/tdd <task>` command — triggers full-tdd workflow

#### Public API (new modules)

```typescript
// orchestrator.ts
export function executeChain(config, taskId, workflow, stages): Promise<OrchestratorResult>
export function executeWorkflow(config, workflowName, taskId, userTask): Promise<OrchestratorResult>
export function buildStageTask(persona, userTask): string

// workflows.ts
export function discoverWorkflows(cwd): WorkflowDefinition[]
export function findWorkflow(cwd, name): WorkflowDefinition | undefined
```

**Done when:** ✅ Extension loads in pi, commands appear, tool is callable.

### Phase 7: End-to-End Validation 🟡
**Effort:** Manual (~1.5–2 hours)
**Detailed Plan:** `docs/plans/phase7-e2e-validation.md`
**Status:** Planning complete — pre-flight fixes needed before E2E run

**Pre-validation gaps identified:**
1. **G-1 (Blocker):** `.pi/agents/tracker.md` missing — 5th stage of full-tdd will fail
2. **G-2 (Minor):** `.pi/agents/sandbox.md` missing — discoverability incomplete
3. **G-3 (Minor):** `.gitignore` missing `docs/agents/pipelines/`
4. **G-4 (Trivial):** No `.gitkeep` files for empty dirs

**Validation stages (8 total):**
| Stage | Description | Type |
|-------|-------------|------|
| 0 | Pre-flight fixes (G-1 through G-4) | Code |
| 1 | Single-agent smoke test (planner) | Manual |
| 2 | Plan-only pipeline (2 stages) | Manual |
| 3 | Full TDD pipeline (5 stages, BR-03) | Manual |
| 4 | Error handling (4 scenarios) | Manual |
| 5 | Abort handling (Ctrl+C mid-pipeline) | Manual |
| 6 | Dashboard validation (all `/agents` subcommands) | Manual |
| 7 | Observability data quality audit | Manual |

**Done when:** Full TDD pipeline completes successfully for at least one user story, all observability data is correct.

### Phase 8: Cleanup ✅
**Effort:** Trivial
**Completed:** 2026-05-14

**Steps completed:**
1. ✅ `.gitignore` already had `docs/agents/logs/*.jsonl` and `docs/agents/pipelines/*.json`
2. ✅ `.gitkeep` files added to `docs/agents/logs/` and `docs/agents/pipelines/`
3. ✅ Decision: **keep both** skills and agents (documented in `AGENTS.md`)
4. ✅ `AGENTS.md` updated with full subagent architecture section
5. ✅ Non-blocking issues documented in `docs/agents/KNOWN-ISSUES.md`
6. ✅ Bugs from Phase 7 fixed: pipeline ID trailing period, cost scalar handling

**Done when:** ✅ Project is clean, gitignore is set, documentation reflects the new architecture.

---

## Dependencies

- **Pi version:** Current installed version (supports `--mode json`, agent discovery, extensions)
- **Node.js:** Available via pi's runtime (extensions are TypeScript via jiti)
- **No npm dependencies** — everything uses pi's built-in APIs (`@mariozechner/pi-coding-agent`, `typebox`, `@mariozechner/pi-tui`)
- **Container:** Subagents run `pi` locally but delegate to the Docker container via bash commands (same as current skill behavior)

## Key Risks

| Risk | Mitigation |
|------|------------|
| Context handoff fidelity — `{previous}` is just text | Each persona reads actual files from disk; summary only conveys paths + high-level status |
| Cold start latency per subagent | Use faster models for simpler personas (tracker, sandbox) |
| Cost of multiple LLM calls | Each call is cheaper (smaller context); track via `/agents summary` |
| Extension complexity | Modular design; each module has clear interface; can be tested independently |
| File system race conditions on pipeline manifest | Single writer (the parent extension); manifests are per-pipeline (no concurrent access) |
