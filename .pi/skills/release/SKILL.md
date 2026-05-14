---
name: release
description: Commit, push and create GitHub PRs for BikerFlow. Groups changes into conventional commits, pushes branches and generates pull requests. Use when you want to publish work to GitHub with proper review gates.
---

# Release to GitHub

This skill automates the commit → push → PR flow with **manual gates at every step**. The user reviews and approves before any git operation that touches the remote.

## Prerequisites

- `gh` CLI authenticated (`gh auth status`)
- Remote `origin` pointing to `https://github.com/fekaled/bikerflow.git`
- Working on an `agent-work-*` branch (created by `snapshot.sh`)

If prerequisites are not met, stop and tell the user what's missing.

## Phase 1: Analyze Changes

Run these commands and present a **human-readable summary** of what changed:

```bash
git status --short
git diff --stat
git diff --cached --stat
```

For each changed/new file, briefly explain what it is and why it changed (based on file path, git diff content, and project context).

## Phase 2: Group & Commit

### Grouping Rules

Group files into logical commits using this priority order:

1. **`fix(scope):`** — Bug fixes, broken scripts, wrong configs
2. **`chore:`** — Build tooling, gitignore, CI configs, meta files
3. **`docs:`** — Documentation, ADRs, plans, progress tracking
4. **`feat(scope):`** — New features organized by domain layer:
   - `feat(domain)` — Enums, value objects
   - `feat(domain)` — Eloquent models
   - `feat(db)` — Migrations
   - `feat(test)` — Factories, test helpers
   - `feat(services)` — Business logic services, exceptions
   - `feat(ui)` — Blade views, controllers, routes
5. **`refactor(scope):`** — Code restructuring without behavior change
6. **`test:`** — PHPUnit tests (unit + feature)

### Commit Message Format

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <short summary in imperative mood>

<blank line>
<body explaining WHY the change was made, not just what>
```

Rules:
- **Title line** ≤ 72 characters
- **Imperative mood**: "add", "fix", "update" — not "added", "fixing", "updates"
- **Body** explains the *reason* for the change, referencing business rules or context
- **No trailer/footers** unless explicitly requested

### Presentation to User

Present the commit plan as a numbered table:

```
| # | Type     | Scope    | Files | Title |
|---|----------|----------|-------|-------|
| 1 | fix      | bin      | 2     | ...   |
| 2 | chore    | —        | 1     | ...   |
```

Then show the **full message** for each commit (title + body).

**🛑 GATE: Ask the user to approve, adjust grouping, or edit messages before proceeding.**

Once approved, execute each commit:

```bash
git add <files...>
git commit -m "<message>"
```

Do NOT proceed to Phase 3 without explicit user approval.

## Phase 3: Push

Before pushing, show:

```bash
git log --oneline main..HEAD
```

Present the user with:
- **Base branch**: `main`
- **Feature branch**: current branch name
- **Remote**: `origin`
- **Number of commits** to push

**🛑 GATE: Ask the user to confirm the push.**

Execute:

```bash
# Ensure main is up to date on remote (only if behind)
git push origin main

# Push the feature branch
git push -u origin <branch-name>
```

Do NOT proceed to Phase 4 without explicit user approval.

## Phase 4: Create Pull Request

Generate a PR body using this template:

```markdown
## Summary

<1-2 sentence description of what this PR delivers>

### What's included (<N> commits)

<numbered list of commits with type and title>

### Business rules enforced

- **BR-XX**: <description>

## Test plan

- [ ] `php artisan test` — all tests pass
- [ ] <any additional manual verification>
```

Rules for PR generation:
- **Title**: `feat: <short description>` or `fix: <short description>` matching the dominant commit type
- **Body**: auto-generated from commits, but curated (not a raw dump)
- Include relevant business rule references
- Test plan should be actionable checkboxes

Show the full PR title and body to the user.

**🛑 GATE: Ask the user to approve or edit the PR content.**

Once approved, create:

```bash
gh pr create \
  --base main \
  --head <branch-name> \
  --title "<title>" \
  --body-file - <<'EOF'
<body>
EOF
```

Print the resulting PR URL.

## Phase 5: Post-Merge Cleanup

After the PR is created, remind the user:
- The PR URL
- That merging can be done via GitHub UI or `gh pr merge <number>`
- That `rollback.sh` is available if they need to restore the environment

**🛑 GATE: Wait for the user to confirm the PR has been merged.**

Once confirmed, offer to run the cleanup:

```bash
./bin/agent-jail/reset.sh
```

This script will:
1. Switch to `main` and pull merged changes
2. Delete the local feature branch
3. Delete the remote feature branch
4. Leave the user on `main`, ready for the next task

After cleanup, remind the user to start the next cycle:

```bash
./bin/agent-jail/snapshot.sh   # creates new agent-work-* branch + DB dump
```

## Important Rules

1. **Never push without explicit user approval** — show what will be pushed first
2. **Never create a PR without showing the title and body first**
3. **Never commit files the user hasn't seen** — always show the grouping plan
4. **Never modify the `main` branch directly** — all work goes through PRs
5. **Do not include `.snapshots/`** — it's operational data, not source
6. **Do not include `docs/agents/logs/*.jsonl` or `docs/agents/pipelines/*.json`** — gitignored runtime data
7. **If `git status` is clean**, tell the user there's nothing to commit and exit
