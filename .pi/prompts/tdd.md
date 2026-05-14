---
description: Run the full TDD pipeline as automated subagent chain. Plans, writes failing tests, implements, validates, and tracks — all in isolated subprocesses.
argument-hint: "<task-id> <task description>"
---

# Full TDD Pipeline

Running the complete TDD pipeline for: $@

This will spawn 5 isolated subagent processes in sequence:

1. **Planner** → Creates plan at `docs/plans/`
2. **Tester** → Writes failing tests (RED)
3. **Developer** → Implements code (GREEN)
4. **Validator** → Audits the implementation
5. **Tracker** → Updates `docs/progress.md`

Each stage runs in its own context with scoped tools. Progress is tracked in real-time via the pipeline manifest.

Use `/agents` to monitor progress at any time.
