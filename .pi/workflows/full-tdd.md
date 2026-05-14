---
name: full-tdd
description: Full TDD pipeline — plan, write failing tests, implement, validate, track
stages:
  - planner
  - tester
  - developer
  - validator
  - tracker
---

# Full TDD Pipeline

Complete feature implementation following the TDD methodology.

1. **Planner** — Reads PRD + Tech Docs, creates a detailed plan with acceptance criteria
2. **Tester** — Reads the plan, writes comprehensive failing tests (RED)
3. **Developer** — Reads plan + failing tests, implements code to make tests pass (GREEN)
4. **Validator** — Audits implementation against PRD, checks financial accuracy, security
5. **Tracker** — Updates progress board to reflect completed pipeline
