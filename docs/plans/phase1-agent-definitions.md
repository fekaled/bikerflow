# Plan: Phase 1 — Agent Definitions for Subagent Architecture

**Task ID:** phase1-agents
**Date:** 2026-05-13
**Source Plan:** `docs/plans/subagent-architecture-observability.md`
**Complexity:** Small (4 files to create, 2 persona decisions to resolve)

---

## 1. Objective

Convert existing BikerFlow persona SKILL.md files into `.pi/agents/*.md` format so that the future `bikerflow-subagents` extension (Phases 2–6) can discover and spawn them as isolated `pi --mode json` subprocesses.

This phase produces the **4 core reasoning personas** as agent definitions. It also resolves the question of whether sandbox and tracker should become subagents (see Section 3).

---

## 2. What an Agent Definition Looks Like

Per the pi subagent example (`examples/extensions/subagent/agents.ts`), agent discovery works as follows:

1. **User-level agents** are loaded from `~/.pi/agent/agents/*.md`
2. **Project-level agents** are loaded from `.pi/agents/*.md` (requires `agentScope: "project"` or `"both"`)
3. Each file is a Markdown file with YAML frontmatter:

```yaml
---
name: planner           # unique identifier, used in tool calls
description: ...        # shown in agent listing
tools: read,grep,find,ls,bash   # comma-separated, optional
model: claude-sonnet-4-5         # optional model override
---

<System prompt body — the persona's instructions>
```

**Key constraint:** The subagent system uses `--append-system-prompt` to inject the body. The agent runs as a **separate `pi` process** with its own context window, its own tool set, and optionally its own model. It does NOT share memory with the parent.

---

## 3. Decision: Sandbox & Tracker as Subagents

### 3a. Sandbox — Keep as Skill, NOT a Subagent

| Factor | Assessment |
|--------|-----------|
| **Nature** | Infrastructure utility (start/stop containers, run commands) |
| **Reasoning needed** | None — it's shell commands with fixed patterns |
| **LLM cost** | Wasteful to spawn a full LLM context just to run `docker exec` |
| **Latency** | ~5–10s cold start per subagent invocation for a trivial task |
| **Current usage** | Other agents (developer, tester, validator) already run `docker exec` directly via bash |
| **Overlap** | The "run commands inside container" pattern is embedded in every agent's instructions already |

**Decision:** Keep sandbox as a **skill only**. Do NOT create `.pi/agents/sandbox.md`.

**Rationale:** Sandbox is not a reasoning persona. It's a utility. The docker commands are already documented in AGENTS.md and each agent's system prompt. Spawning a separate LLM process to run `docker exec devcontainer_app_1 php artisan test` is pure overhead. If the future extension needs container health checks, that belongs in the extension's TypeScript code (Phase 6), not in an LLM subprocess.

**Action:** Remove sandbox from the agent definitions list. Keep `.pi/skills/sandbox/SKILL.md` as-is for interactive use.

### 3b. Tracker — Keep as Skill, NOT a Subagent (for now)

| Factor | Assessment |
|--------|-----------|
| **Nature** | Mechanical: read progress.md, update specific cells, add log entries |
| **Reasoning needed** | Minimal — it follows strict rules to update a markdown table |
| **Frequency** | Called after EVERY pipeline stage (5× per TDD cycle) |
| **LLM cost** | 5 subagent spawns × $0.01–0.02 = $0.05–0.10 per pipeline run for trivial edits |
| **Alternative** | Stop hooks can update progress.md programmatically in TypeScript |
| **Interactive use** | User sometimes asks "update progress" manually — skill covers this |

**Decision:** Keep tracker as a **skill only** for Phase 1. Do NOT create `.pi/agents/tracker.md`.

**Rationale:** Tracker is the most mechanical persona. Its work is formulaic — it follows deterministic rules to update markdown tables. The planned stop hook system (`hooks.ts`, Phase 4) can update `docs/progress.md` via TypeScript file I/O without spawning an LLM. This saves ~5 subagent invocations per pipeline run.

**Future option:** If progress tracking becomes more sophisticated (e.g., analyzing trends, generating reports, making recommendations), tracker could become a subagent in a later phase. For Phase 1, it's premature.

**Action:** Remove tracker from the agent definitions list. Keep `.pi/skills/tracker/SKILL.md` as-is. In Phase 4, the stop hooks will handle progress.md updates programmatically.

---

## 4. Agent Definitions to Create

### 4 agents, down from 6:

| # | Agent File | Source Skill | Tools | Model | Rationale |
|---|-----------|-------------|-------|-------|-----------|
| 1 | `.pi/agents/planner.md` | `.pi/skills/planner/SKILL.md` | `read,grep,find,ls,bash` | *(project default)* | Read-only + bash for reading docs. No model override needed — planning benefits from the best available model. |
| 2 | `.pi/agents/tester.md` | `.pi/skills/tester/SKILL.md` | `read,grep,find,ls,bash,write,edit` | *(project default)* | Needs write/edit for test files, bash for running tests. |
| 3 | `.pi/agents/developer.md` | `.pi/skills/developer/SKILL.md` | *(all default)* | *(project default)* | Full capabilities for implementation. |
| 4 | `.pi/agents/validator.md` | `.pi/skills/validator/SKILL.md` | `read,grep,find,ls,bash` | *(project default)* | Read-only audit. Bash for running tests. No code writes. |

> **Model note:** The original plan suggested `claude-sonnet-4-5` for planner, `claude-haiku-4-5` for simpler personas. However, the current project is configured with `zai/glm-5.1` as default model. Setting per-agent model overrides would require those models to be available in auth.json. For Phase 1, we use the project default for all agents. Model overrides can be added in `.pi/agents/*.md` frontmatter later once providers are configured.

---

## 5. Adaptations from Skill → Agent Format

When converting SKILL.md → agent .md, the following changes are needed:

### 5a. Remove "Load the Tracker Skill" References

All four personas contain instructions like:
> "Load the tracker skill: read `.pi/skills/tracker/SKILL.md`. Update `docs/progress.md`..."

**Change:** Remove these sections entirely. In the subagent pipeline, progress tracking is handled by stop hooks (Phase 4), not by the agent itself. The agent's job is to do its work and report results — the pipeline infrastructure handles bookkeeping.

### 5b. Remove "Load the Sandbox Skill" References

The tester and developer reference running commands through the sandbox skill.

**Change:** Replace with direct bash command instructions. The agent already has `bash` in its tool set. The docker exec patterns are documented in the agent's system prompt body.

### 5c. Adjust Relative Reference Paths

The skills reference files like `references/plan-template.md`. These resolve relative to the skill directory. For agent definitions in `.pi/agents/`, the references are at `.pi/skills/<name>/references/`.

**Change:** Convert all relative links to absolute paths relative to the project root:
- `references/plan-template.md` → `.pi/skills/planner/references/plan-template.md`
- `references/test-patterns.md` → `.pi/skills/tester/references/test-patterns.md`
- `references/coding-standards.md` → `.pi/skills/developer/references/coding-standards.md`
- `references/audit-checklist.md` → `.pi/skills/validator/references/audit-checklist.md`
- `references/audit-template.md` → `.pi/skills/validator/references/audit-template.md`
- `references/prd-rules.md` → `.pi/skills/planner/references/prd-rules.md`

### 5d. Remove Skill-Level Frontmatter

The YAML frontmatter in SKILL.md uses `name` and `description` (skill format). The agent frontmatter needs `name`, `description`, and optionally `tools` and `model`.

### 5e. Add Subagent Context

Each agent system prompt should include a note that it's running as an isolated subprocess. This helps the model understand:
- It cannot interact with the user directly
- It should produce structured output for the pipeline
- Its output will be passed to the next stage via `{previous}`

### 5f. Embed Sandbox Commands Directly

For agents that need Docker (tester, developer, validator), embed the container command patterns directly in the system prompt instead of referencing the sandbox skill:

```
All commands run through Docker:
  docker exec devcontainer_app_1 <command>
```

---

## 6. Files to Create

### 6.1 `.pi/agents/planner.md`

**Frontmatter:**
```yaml
---
name: planner
description: The Rigorous Architect. Translates business goals into precise technical blueprints grounded in the PRD. Reads source documents and outputs a plan to docs/plans/.
tools: read,grep,find,ls,bash
---
```

**Body:** Adapted from `.pi/skills/planner/SKILL.md` with:
- Tracker references removed (Progress Tracking section removed)
- Reference paths updated to absolute
- Subagent context note added
- "Interaction Pattern" section removed (not applicable to subagent)

### 6.2 `.pi/agents/tester.md`

**Frontmatter:**
```yaml
---
name: tester
description: The Quality Sentinel. Writes and enforces TDD tests — validates business rules, financial formulas, and edge cases using PHPUnit within the Dev Container.
tools: read,grep,find,ls,bash,write,edit
---
```

**Body:** Adapted from `.pi/skills/tester/SKILL.md` with:
- Tracker references removed
- Sandbox references replaced with direct docker exec commands
- Reference paths updated to absolute
- Subagent context note added
- Interaction Pattern section removed

### 6.3 `.pi/agents/developer.md`

**Frontmatter:**
```yaml
---
name: developer
description: The Jailed Craftsman. Executes technical blueprints inside the Dev Container, writing Laravel 13 code that makes the Tester's failing tests pass.
---
```

No `tools` restriction — developer gets all default tools.

**Body:** Adapted from `.pi/skills/developer/SKILL.md` with:
- Tracker references removed
- Sandbox references replaced with direct docker exec commands
- Reference paths updated to absolute
- Subagent context note added
- Interaction Pattern section removed

### 6.4 `.pi/agents/validator.md`

**Frontmatter:**
```yaml
---
name: validator
description: The Gatekeeper of Truth. Performs the final audit — verifies PRD compliance, financial accuracy, security integrity, and business rule enforcement.
tools: read,grep,find,ls,bash
---
```

**Body:** Adapted from `.pi/skills/validator/SKILL.md` with:
- Tracker references removed
- Reference paths updated to absolute
- Subagent context note added
- Interaction Pattern section removed

---

## 7. What Stays as Skills (No Changes)

| Skill | Reason for Keeping |
|-------|-------------------|
| `.pi/skills/planner/SKILL.md` | Interactive use — user manually invokes planner in current session |
| `.pi/skills/tester/SKILL.md` | Interactive use — user manually invokes tester |
| `.pi/skills/developer/SKILL.md` | Interactive use — user manually invokes developer |
| `.pi/skills/validator/SKILL.md` | Interactive use — user manually invokes validator |
| `.pi/skills/tracker/SKILL.md` | Interactive use + stop hooks may call it programmatically |
| `.pi/skills/sandbox/SKILL.md` | Interactive use — user checks container status |

As stated in the plan's "Skills vs Agents Decision" section: **keep both.** Skills for interactive/manual use, agents for automated pipelines.

---

## 8. Directory Creation

```
mkdir -p .pi/agents/
```

The `.pi/agents/` directory does not yet exist. Creating it enables the subagent extension's `discoverAgents()` function (when `agentScope: "project"` or `"both"`) to find project-level agent definitions.

---

## 9. Done When

Phase 1 is complete when:

- [ ] `.pi/agents/` directory exists
- [ ] `.pi/agents/planner.md` has valid YAML frontmatter (`name`, `description`, `tools`) and a self-contained system prompt body
- [ ] `.pi/agents/tester.md` has valid YAML frontmatter and adapted body
- [ ] `.pi/agents/developer.md` has valid YAML frontmatter and adapted body
- [ ] `.pi/agents/validator.md` has valid YAML frontmatter and adapted body
- [ ] No agent references the tracker skill for progress tracking
- [ ] No agent references the sandbox skill for container management
- [ ] All reference file paths are absolute (project-root relative)
- [ ] Existing `.pi/skills/` files remain unchanged
- [ ] Agents can be discovered by the example subagent extension with `agentScope: "project"`

---

## 10. Verification

After creating the files, verify with:

1. **Structure check:** `ls -la .pi/agents/` shows 4 `.md` files
2. **Frontmatter check:** Each file has `---` delimiters with `name` and `description` fields
3. **Content check:** No references to "load the tracker skill" or "load the sandbox skill" in any agent body
4. **Path check:** All reference paths start with `.pi/skills/`
5. **Discovery check (requires Phase 6 extension):** The subagent tool can list all 4 agents when `agentScope: "project"` is set

---

## 11. Risks

| Risk | Mitigation |
|------|-----------|
| Agent body too large → token waste in system prompt | The subagent extension uses `--append-system-prompt` with a temp file, so size is less critical than in skill mode. Still, keep instructions focused. |
| Reference file paths break if CWD changes | Subagents are spawned with `--cwd` set to the project root. Paths like `.pi/skills/...` resolve correctly from there. |
| Model not available | No model override in Phase 1 — all agents use the project default. Model selection can be added later. |
| Skill-agent divergence over time | Both share the same conceptual source. Consider symlinking or generating agents from skills in Phase 8. |
