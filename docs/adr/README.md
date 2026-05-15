# Architecture Decision Records (ADR)

This directory contains all architecture decisions for the BikerFlow project. Each decision is recorded as a standalone Markdown file following the [ADR pattern](https://adr.github.io/).

## Index

| ADR | Title | Date | Status | Business Rules |
|-----|-------|------|--------|----------------|
| [ADR-001](./001-core-payout-schema.md) | Core Payout Schema — Entities, Enums & State Machine | 2026-05-14 | ✅ Accepted | BR-01, BR-02, BR-03, BR-04, BR-06 |
| [ADR-002](./002-auth-roles-magic-link.md) | Auth & Roles — Phone-Based Magic Link with RBAC | 2026-05-14 | ✅ Accepted | BR-03, BR-05 |
| [ADR-003](./003-shift-crud-lifecycle.md) | Shift CRUD & Lifecycle — Admin Management with Workflow Locking | 2026-05-14 | ✅ Accepted | BR-01, BR-05 |
| [ADR-004](./004-shift-biker-assignment.md) | Shift-Biker Assignment — Admin Biker Management with Status Guards | 2026-05-14 | ✅ Accepted | BR-01, BR-05 |
| ADR-005 | _Reserved for next decision_ | — | — | — |

## How to Create a New ADR

1. Copy `TEMPLATE.md` to `NNN-short-title.md` (increment the number).
2. Fill in all sections. Reference the originating pipeline, plan file, and business rules.
3. Add an entry to the index table above.
4. If the decision supersedes a previous ADR, update the old ADR's `Status` field to "Superseded by ADR-{NNN}".

## Naming Convention

- Files: `NNN-kebab-case-title.md` (zero-padded 3-digit number)
- Numbers are sequential and never reused (even if an ADR is deprecated).

## Linking from Code

When a code artefact (model, migration, service) is the direct result of an ADR, add a comment:

```php
// ADR-001: Core payout schema design
// See docs/adr/001-core-payout-schema.md
```

## Relationship to Other Documentation

| Document | Purpose |
|----------|---------|
| `docs/plans/` | Implementation blueprints (the *what* and *how*) |
| `docs/audits/` | Validation reports (the *verification*) |
| **`docs/adr/`** | **Architecture decisions (the *why*)** |
| `docs/progress.md` | Project progress board (the *status*) |
| `docs/archives/pipelines/` | Pipeline manifests for provenance |

## Template

See [`TEMPLATE.md`](./TEMPLATE.md) for the standard ADR format.
