---
name: implement-only
description: Implement-only pipeline — write tests, implement, validate, track (requires existing plan)
stages:
  - tester
  - developer
  - validator
  - tracker
---

# Implement-Only Pipeline

For when a plan already exists. Writes failing tests, implements, validates, and tracks.

1. **Tester** — Reads the existing plan, writes comprehensive failing tests (RED)
2. **Developer** — Reads plan + failing tests, implements code to make tests pass (GREEN)
3. **Validator** — Audits implementation against PRD, checks financial accuracy, security
4. **Tracker** — Updates progress board to reflect completed pipeline
