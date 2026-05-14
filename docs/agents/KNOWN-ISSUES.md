# BikerFlow Subagent Architecture — Known Issues

**Created:** 2026-05-14
**Updated:** 2026-05-14
**Source:** Phase 7 E2E Validation (`docs/plans/phase7-e2e-validation.md`)

---

## ✅ Issue 1: Planner Hook Cannot Extract Plan File Path — FIXED

| Field | Value |
|-------|-------|
| **Module** | `hooks.ts` → `plannerStopHook()`, `agents/planner.md` |
| **Fix Date** | 2026-05-14 |
| **Fix Applied** | Two-pronged approach (option a): (1) Added `write` to planner's tool scope in `planner.md` so `TraceLogger` captures `docs/plans/` paths as `outputArtifacts` via `write` tool calls. Added instruction in planner system prompt to use `write` tool. (2) Expanded regex in `plannerStopHook` to cover more phrasing variants AND added a broader catch-all that matches any `docs/plans/*.md` path in the output text. |

---

## ✅ Issue 2: Validator Verdict Often Extracted as UNKNOWN — FIXED

| Field | Value |
|-------|-------|
| **Module** | `hooks.ts` → `validatorStopHook()` |
| **Fix Date** | 2026-05-14 |
| **Fix Applied** | Expanded regex patterns to cover: `AUDIT/RESULT/OUTCOME/CONCLUSION` prefixes, emoji-based `✅ PASS` / `❌ FAIL`, "implementation passes", "all criteria met", "no issues found", and a heuristic fallback (contains "pass" without "fail" → PASS). Negative patterns are checked first for correct precedence. |

---

## Issue 3: Bash Tool Bypasses Write Scoping — OPEN

| Field | Value |
|-------|-------|
| **Module** | `agents.ts` → tool scoping via `--tools` CLI flag |
| **Impact** | Agents with restricted tool scopes (e.g., planner with `read,write,grep,find,ls,bash` but no `edit`) can still create/modify files via `bash` commands (`echo > file`, `cat > file`, `tee`, etc.). This undermines the intended tool isolation. |
| **Root Cause** | The `bash` tool is inherently unrestricted — it can execute any shell command. Pi's `--tools` flag only controls which named tools are available, but `bash` provides a full shell escape. |
| **Recommendation** | This is **by design** for pi's architecture. The tool scoping is a soft boundary, not a security boundary. For true isolation, pi would need a sandboxed shell. Document this in the agent definitions so persona instructions explicitly state "do not create files" rather than relying on tool absence. Alternatively, remove `bash` from read-only personas and use a custom `run` tool that only allows specific command patterns. |
| **Severity** | Low — the persona instructions already constrain behavior; tool scoping is advisory |

---

## ✅ Issue 4: Temp Prompt Files May Leak on Crash — FIXED

| Field | Value |
|-------|-------|
| **Module** | `subagent.ts` → `writePromptToTempFile()` |
| **Fix Date** | 2026-05-14 |
| **Fix Applied** | Added self-healing startup cleanup in `writePromptToTempFile()` that scans `storage/framework/pi-subagent-prompts/` and removes files older than 1 hour before writing a new prompt file. This runs on every subagent spawn, so stale files are cleaned up automatically on the next run. |

---

## Issue 5: Cost Always Reports as 0 — OPEN

| Field | Value |
|-------|-------|
| **Module** | `observability.ts` → `handleMessageEnd()`, `subagent.ts` → usage accumulation |
| **Impact** | `totalCost` in both trace logs and pipeline manifests is always `0`. Token counts (input, output, cache) are captured correctly. |
| **Root Cause** | The provider (via pi) reports `usage.cost` as a scalar number (`0`) rather than an object (`{total: 0.035}`). The original code expected `cost.total` (object form). **Fix applied in Phase 7** — code now handles both scalar and object forms. However, the provider may genuinely report `cost: 0` if the model doesn't expose pricing data to pi's JSON stream. |
| **Recommendation** | The fix is in place (Phase 7). If cost remains 0 after the fix, the issue is upstream (provider/pi doesn't expose cost data in `--mode json` output). Verify with a model that reports costs. Consider computing cost from token counts + known model pricing as a fallback. |
| **Severity** | Low — token counts are correct; cost can be derived post-hoc |

---

## Disposition

Issues 1, 2, and 4 were fixed on 2026-05-14. Remaining open issues:

- **Issue 3** (bash bypass) — document-only; by design, low priority
- **Issue 5** (cost = 0) — needs upstream verification; consider fallback pricing

These issues are **non-blocking** for the subagent architecture. The pipeline completes successfully, observability data is comprehensive, and all persona hooks produce useful summaries.
