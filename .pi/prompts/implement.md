---
description: Run the implement-only pipeline as automated subagent chain. Writes failing tests, implements, validates, and tracks — all in isolated subprocesses. Requires an existing plan.
argument-hint: "<task-id> <task description>"
---

# Implement-Only Pipeline

Running the implementation pipeline for: $@

This assumes a plan already exists at `docs/plans/`. Spawns 4 isolated subagent processes:

1. **Tester** → Writes failing tests from the plan (RED)
2. **Developer** → Implements code to make tests pass (GREEN)
3. **Validator** → Audits the implementation
4. **Tracker** → Updates `docs/progress.md`

Each stage runs in its own context with scoped tools. Progress is tracked in real-time via the pipeline manifest.

Use `/agents` to monitor progress at any time.
