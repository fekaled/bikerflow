# ADR-006: Payout Engine Lifecycle & Settlement Policy

**Date:** 2026-05-17
**Status:** ✅ Accepted
**Decision Maker:** Planner (blueprint), Product Owner (OQ-3C-01/02/03), Validator (audit)
**Task ID:** Phase 3A / 3B / 3C / 4C
**Pipeline:** implement-only-Phase-3C-20260517123007 (final phase)
**Business Rules:** BR-02, BR-03, BR-04, BR-06
**Related Plans:** `docs/plans/phase-3a-*.md`, `docs/plans/phase-3b-*.md`, `docs/plans/phase-3c-*.md`, `docs/plans/phase-4b-*.md`, `docs/plans/phase-4c-*.md`

---

## Context

ADR-001 defined the core schema, enums, and state machine (`PaymentStatus`: pending → processing → paid/failed; `ShiftStatus`: draft → open → closed → approved → paid). ADR-005 resolved five prerequisite decisions before Phase 3 planning began. However, during the three Phase 3 sub-phases, seven additional architectural decisions emerged that together define **how the complete payout lifecycle actually operates end-to-end**. These decisions are inseparable — they form a single settlement policy that governs the entire `closed → approved → paid` arc.

Without documenting these decisions, future developers would need to reverse-engineer intent from code and plan files scattered across three phases.

---

## Decisions

### Decision 1: Two-Step Close Gate (Close Review → Confirm)

> **Origin:** Phase 3A
> **ADR-005 D5 context:** "Admin must confirm no contested trips before closing."

The Admin close flow is a **two-step gate**: `GET shifts/{shift}/close/review` (display trip counts + payout preview + eligibility warnings) → `POST shifts/{shift}/close` with `confirmed=1` (create Payment rows, transition shift to `closed`).

Payment rows are created **at close time**, not at release time. This ensures every shift-biker has a Payment row in `pending` status immediately after close, making the payout visible and auditable before any release action.

**Consequences:**
- Close review shows warnings (not blocks) for bikers without User accounts or verified PIX keys — the Admin can still close the shift.
- Payment creation uses `firstOrCreate` for idempotency — re-submitting close does not duplicate Payment rows.

---

### Decision 2: Two-Tier Eligibility Policy (Warning → Hard Block)

> **Origin:** Phase 3A → 3B escalation
> **ADR-005 D4 context:** "Bikers must have User accounts to be paid."

Eligibility checks (verified PIX key, linked User account) are enforced at **two tiers** depending on the action:

| Action | Missing PIX/User | Behavior |
|--------|------------------|----------|
| Close review (3A) | Warning badge displayed | Shift still closes, Payment row still created in `pending` |
| Release payment (3B) | **Hard block** — RuntimeException thrown | Payment stays `pending`, cannot be released |
| Retry payment (3C) | **Hard block** — RuntimeException thrown | Payment stays `failed`, cannot be retried |

**Rationale:** Closing the shift is an administrative milestone that should not be blocked by a biker's incomplete onboarding. But releasing money (transitions to `processing`) and retrying a failed payment both require the biker to be fully eligible — no funds move without verified PIX and a User account.

**Consequences:**
- A shift can have payments stuck in `pending` indefinitely if bikers never complete onboarding. This is intentional — the Admin sees block reasons and can take manual action.
- The same `isEligibleForRelease()` check is reused at release and retry time, ensuring consistency.

---

### Decision 3: Batch Release Skips Ineligible (Does Not Abort)

> **Origin:** Phase 3B
> **BR-04 context:** "Each payment fails/succeeds independently."

When the Admin clicks "Release All Eligible", the service iterates all `pending` payments and **skips** ineligible ones rather than aborting the entire batch. Each release is wrapped in a try/catch — one failure does not prevent others from succeeding.

**Consequences:**
- The batch response includes `releasedCount`, `blockedCount`, and `ineligibleCount` so the Admin sees exactly what happened.
- This is the BR-04 interpretation for release: granular, best-effort, independent.

---

### Decision 4: Shift Auto-Transition Gates

> **Origin:** Phase 3B + 3C + 4B
> **Extends ADR-001 state machine.**

Two automatic shift state transitions occur as payments progress:

| Trigger | Transition | Code Path |
|---------|------------|-----------|
| All payments for a shift are `processing` (or beyond) | `closed → approved` | `PaymentReleaseService::checkAndTransitionShiftToApproved` — called after each individual and batch release |
| All payments for a shift are `paid` | `approved → paid` | `PaymentSettlementService::reconcileShiftStatus` — called after **manual** mark-paid |
| All payments for a shift are `paid` | `approved → paid` | `PixPaymentService::reconcileShiftStatus` — called after **gateway auto-paid** (sync "processed" response) |
| All payments for a shift are `paid` | `approved → paid` | `PixWebhookService` → `PixPaymentService::reconcileShiftStatus` — called after **webhook callback** (async "processed" response) |

> **Note (Phase 4B → 4C):** The `approved → paid` transition has **three** trigger paths: (1) manual Admin mark-paid via the settlement dashboard, (2) automatic gateway response when the PIX gateway returns `status = "processed"` synchronously (Phase 4B), and (3) webhook callback when the gateway delivers the final status asynchronously (Phase 4C). All three call the same `PixPaymentService::reconcileShiftStatus()` method, which is idempotent. The webhook service delegates to `PixPaymentService` to keep all gateway-driven reconciliation in one place.

Both transitions are:
- **Guarded at the service layer** — short-circuit if shift is not in the expected source status.
- **Idempotent** — calling again after transition is a no-op.
- **Non-regressing** — a shift never moves backward. A single `failed` or `processing` payment keeps the shift at `approved`.

**Consequences:**
- `Shift::allPaymentsReleased()` checks every payment is `processing`, `paid`, or `failed` (all are past `pending`).
- `Shift::allPaymentsPaid()` checks every payment is exactly `paid`.
- A shift with any `failed` payments stays `approved` until the Admin resolves them (retry → mark paid, or manual intervention).

---

### Decision 5: Hard Retry Cap at 3 with Auto-Fail

> **Origin:** Phase 3C — Product Owner decision (OQ-3C-01)
> **BR-06 context:** "Payment retries."

A failed payment can be retried at most **3 times** (`retry_count` ceiling). The enforcement is two-fold:

1. **Pre-check guard:** If `retry_count >= 3` when retry is attempted, the service throws a RuntimeException with a message directing the Admin to intervene manually (e.g., manual bank transfer, contact biker). No state change, no audit log.
2. **Post-retry auto-fail:** On the 3rd successful retry (retry_count becomes 3), the payment is **immediately auto-failed** within the same database transaction: status → `failed`, `failed_at` → now, `failure_reason` → "Limite de retentativas atingido (3/3). Intervenção manual necessária.". A `PaymentAuditLog` with `action = fail` and `payload.reason = "retry_cap_exceeded"` is written.

**Rationale:** Unbounded retries could indicate a systemic issue or operator misuse. The cap forces human intervention before the situation escalates, while still allowing reasonable retry attempts (3) for transient failures.

**Consequences:**
- The payment lifecycle for a capped payment is: `pending → processing → failed → processing → failed → processing → failed` (terminal). The UI hides the "Tentar Novamente" button when `retry_count >= 3`.
- The retry cap is enforced in the service layer, not the database — a future phase could raise or lower the cap without schema changes.
- **Phase 4B:** On the 3rd retry (retry_count becomes 3), the auto-fail happens **without calling the gateway** — no money moves. The gateway is only called for retries where the cap has NOT been reached.

---

### Decision 6: Free-Form Failure Reason

> **Origin:** Phase 3C — Product Owner decision (OQ-3C-02)

`failure_reason` is a free-form `string(500)` column on `payments`, set when the Admin marks a payment as failed. There is no preset enum of failure reasons.

**Rationale:** BikerFlow has no real PIX gateway integration yet (Phase 4). Failure reasons will initially reflect manual observations ("Chave PIX inválida", "Biker não encontrado"). Once real failure data accumulates, the reasons can be normalized to a preset enum in a later phase.

**Consequences:**
- No `FailureReason` enum is needed now.
- `failure_reason` is required on mark-failed (min 3 chars, max 500) and cleared to NULL on retry.
- The auto-fail at retry cap uses a fixed reason string, not Admin input.

---

### Decision 7: Re-Eligibility Check on Retry

> **Origin:** Phase 3C — Product Owner decision (OQ-3C-03)

When retrying a failed payment, the service **re-runs the same `isEligibleForRelease()` check** used during initial release. If the biker's PIX key was revoked or User account was removed between release and retry, the retry is refused.

**Rationale:** State can drift. A biker who was eligible at release time may no longer be eligible days or weeks later when a failed payment is retried. Re-checking ensures BR-02 and ADR-005 D4 remain enforced at every state transition.

**Consequences:**
- `isEligibleForRelease()` is the single source of truth for eligibility — called at release and retry time.
- No additional PIX re-confirmation flow is needed. The existing check is sufficient.

---

## Alternatives Considered

| # | Alternative | Pros | Cons | Why Rejected |
|---|-------------|------|------|--------------|
| 1 | **Single-step close** (no review page) | Simpler flow | Admin cannot preview payouts or see warnings before committing; no contestation checkpoint (ADR-005 D5) | Contradicts ADR-005 D5 and BR-03 manual control |
| 2 | **Hard block at close time** (not just warning) | Prevents "stuck pending" payments | Admin cannot close shift until every biker completes onboarding; operational bottleneck | Too restrictive — close is an administrative milestone, not a funds movement |
| 3 | **Batch release aborts on first ineligible** | Simpler error handling | One ineligible biker blocks all other eligible payments; contradicts BR-04 independence | Violates BR-04 granular failure principle |
| 4 | **Unlimited retries** (no cap) | Maximum flexibility | No forced escalation; could mask systemic issues indefinitely | Product Owner explicitly chose cap at 3 |
| 5 | **Preset failure reason enum** | Consistent data, queryable | Premature normalization without real failure data; every new reason requires code change | Free-form chosen for MVP; normalize later with data |
| 6 | **Skip re-eligibility on retry** | Faster retry, less friction | PIX revoked or User removed after release → money could move to ineligible biker | Unacceptable security gap; violates BR-02 |

---

## Consequences

### Positive

- **Complete audit trail** — Every state transition (close, release, mark-paid, mark-failed, retry, auto-fail) writes a `PaymentAuditLog` row with unique `transaction_ref` and structured `payload`.
- **BR-04 enforced end-to-end** — Individual payment independence holds across all three sub-phases: batch release, individual mark-paid, individual retry.
- **Human escalation built-in** — The retry cap forces Admin intervention, preventing infinite retry loops.
- **Idempotent operations** — `firstOrCreate` at close, status guards at release/settle/retry ensure safe re-submission.
- **Single eligibility check** — `isEligibleForRelease()` reused at release and retry time ensures consistent behavior.

### Negative

- **Payments can be stuck in `pending`** if biker never completes onboarding (PIX verification + User account). No automated cleanup or escalation.
- **Auto-fail on 3rd retry is invisible to the Admin** if they're not watching — the payment briefly becomes `processing` then immediately `failed` in the same transaction. Only the audit log distinguishes it from a manual mark-failed.
- **Free-form failure reasons** will require normalization effort in a future phase.
- **Close review and payment review are separate views** — Admin must navigate between them to see the full picture.

### Risks

- **Eligibility drift between release and settlement** — A biker's PIX could be revoked after release but before mark-paid. This is acceptable: the money is already "in processing" and the Admin is responsible for verifying before marking paid.
- **Race condition on concurrent mark-paid** — Two admins clicking simultaneously. Mitigated by `DB::transaction()` + `payment.refresh()` + status guard. Second request sees `paid` status and throws.
- **Retry cap too aggressive** — 3 retries may be insufficient for transient PIX infrastructure issues. Can be raised later since the cap is in service code, not a DB constraint.

---

## Artefacts Affected

| Type | File | Phase | Role |
|------|------|-------|------|
| Service | `app/Services/ShiftCloseService.php` | 3A | Close review + batch Payment creation (D1, D2 warning tier) |
| Service | `app/Services/PaymentReleaseService.php` | 3B/4B | Release + eligibility hard block (D2 block tier, D3, D4) + gateway call via `gatewayInitiateTransfer()` |
| Service | `app/Services/PaymentSettlementService.php` | 3C/4B | Mark paid/failed/retry + reconciliation (D4, D5, D6, D7) + gateway call on retry + gateway fields in settlement data |
| Service | `app/Services/PixPaymentService.php` | 4B/4C | Gateway call orchestrator — initiateTransfer, auto-transition on sync responses, audit logging (D4 gateway path) + shared `reconcileShiftStatus()` called by webhook service |
| Service | `app/Services/PixWebhookService.php` | 4C | Webhook payload processing — resolves payment by `gateway_transaction_id`, updates status, writes audit log, delegates reconciliation to `PixPaymentService` (D4 webhook path) |
| Service | `app/Services/Gateway/MockPixGateway.php` | 4A/4B/4C | Gateway mock — `.01`→processed, `.02`→failed, default→queued + `checkPaymentStatus()` deterministic patterns (`-sync-failed`, `-sync-pending`) |
| Migration | `database/migrations/2026_05_17_000001_add_failure_columns_to_payments_table.php` | 3C | `failed_at`, `failure_reason`, `retry_count` (D5, D6) |
| Migration | `database/migrations/2026_05_17_000002_add_gateway_columns_to_payments_table.php` | 4B | `gateway_transaction_id`, `gateway_status` for gateway reconciliation |
| Model | `app/Models/Payment.php` | 3A/3B/3C/4B | `isEligibleForRelease()`, `isEligibleForRetry()`, fillable/casts (D2, D7) + gateway fields |
| Model | `app/Models/Shift.php` | 3B/3C | `allPaymentsReleased()`, `allPaymentsPaid()` (D4) |
| View | `resources/views/shifts/close-review.blade.php` | 3A | Close review with warnings (D1) |
| View | `resources/views/shifts/payment-review.blade.php` | 3B/4B | Payment release with block reasons (D2, D3) + gateway status badge |
| View | `resources/views/shifts/payment-status.blade.php` | 3C/4B | Settlement dashboard with retry cap UI (D5) + gateway txn ID + status |
| Request | `app/Http/Requests/ConfirmCloseShiftRequest.php` | 3A | `confirmed` field validation (D1) |
| Request | `app/Http/Requests/MarkFailedRequest.php` | 3C | `failure_reason` validation (D6) |
| Request | `app/Http/Requests/RetryPaymentRequest.php` | 3C | Retry authorization (D5, D7) |
| Policy | `app/Policies/ShiftPolicy.php` | 3A/3B/3C | Admin-only guards for all actions |
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | 3A/3B/3C | All payout lifecycle endpoints |
| Enum | `app/Enums/PaymentAuditAction.php` | 4A/4B | `VerifyPix` + `GatewayAttempt` enum cases |
| Controller | `app/Http/Controllers/Webhook/PixWebhookController.php` | 4C | Unauthenticated webhook endpoint — receives POST callbacks at `/webhooks/pix/status` |
| Middleware | `app/Http/Middleware/VerifyPixWebhookSignature.php` | 4C | HMAC signature verification for webhook authenticity |
| Request | `app/Http/Requests/PixWebhookRequest.php` | 4C | Validates webhook payload structure (transaction_id, status, amount, error_code, error_message, timestamp) |
| Command | `app/Console/Commands/VerifyPixPayment.php` | 4C | Artisan `pix:webhook:verify` — manual status check for Admins |
| Model | `app/Models/PixWebhookLog.php` | 4C | Eloquent model for webhook delivery log (operational/debugging) |
| Migration | `database/migrations/2026_05_18_000002_create_pix_webhook_logs_table.php` | 4C | `pix_webhook_logs` table — webhook delivery audit trail |
| Test | `tests/Unit/Services/ShiftCloseServiceTest.php` | 3A | 35 unit tests |
| Test | `tests/Unit/Services/PaymentReleaseServiceTest.php` | 3B | Unit tests |
| Test | `tests/Unit/Services/PaymentSettlementServiceTest.php` | 3C/4B | 51 unit tests |
| Test | `tests/Unit/Services/PixPaymentServiceTest.php` | 4B | 38 unit tests |
| Test | `tests/Feature/Controllers/ShiftCloseControllerTest.php` | 3A | 49 feature tests |
| Test | `tests/Feature/Controllers/PaymentReleaseControllerTest.php` | 3B | Feature tests |
| Test | `tests/Feature/Controllers/PaymentSettlementControllerTest.php` | 3C | 25 feature tests |
| Test | `tests/Feature/Controllers/PixPaymentControllerTest.php` | 4B | 25 feature tests |
| Test | `tests/Feature/Controllers/PaymentReleaseWithGatewayTest.php` | 4B | 9 feature tests |
| Test | `tests/Feature/Controllers/PaymentRetryWithGatewayTest.php` | 4B | 10 feature tests |
| Test | `tests/Unit/Services/PixWebhookServiceTest.php` | 4C | Unit tests for webhook processing logic |
| Test | `tests/Unit/Middleware/VerifyPixWebhookSignatureTest.php` | 4C | Unit tests for HMAC middleware |
| Test | `tests/Unit/Gateway/MockPixGatewayTest.php` | 4C | Unit tests for `checkPaymentStatus()` deterministic patterns |
| Test | `tests/Feature/Controllers/PixWebhookControllerTest.php` | 4C | Feature tests for webhook endpoint |

---

## Acceptance Criteria Covered

This ADR consolidates decisions underlying the following ACs:

- **Phase 3A:** AC-3A-01 through AC-3A-44 (close review, payout creation, eligibility warnings)
- **Phase 3B:** AC-3B-01 through AC-3B-46 (payment release, eligibility hard block, batch skip, auto-transition to approved)
- **Phase 3C:** AC-3C-01 through AC-3C-48 (mark paid/failed, retry cap, auto-fail, auto-transition to paid, re-eligibility)
- **Phase 4B:** AC-4B-01 through AC-4B-50 (gateway integration, auto-transition on sync response, gateway audit trail, settlement dashboard updates)
- **Phase 4C:** AC-4C-01 through AC-4C-59 (webhook processing, HMAC verification, async status resolution, manual verify command)

Total: **247 acceptance criteria** across five phases.

---

## References

- ADR-001: Core Payout Schema — Entities, Enums & State Machine
- ADR-005: Phase 3 Payout Engine — Prerequisite Decisions
- Plan: `docs/plans/phase-3a-shift-close-payout-calculation.md`
- Plan: `docs/plans/phase-3b-payment-release-admin-approval.md`
- Plan: `docs/plans/phase-3c-payment-failure-and-retry.md`
- Plan: `docs/plans/phase-4b-pix-payment-execution.md`
- Plan: `docs/plans/phase-4c-pix-webhooks-async-status.md`
- Audit: `docs/audits/phase-3a-shift-close-payout-calculation-audit.md`
- Audit: `docs/audits/phase-3b-payment-release-admin-approval-audit.md`
- Audit: `docs/audits/phase-3c-audit.md`
- Audit: `docs/audits/phase-4b-pix-payment-execution-audit.md`
- Audit: `docs/audits/phase-3c-audit.md`
- PRD Sections 2C, 4 (BR-02, BR-03, BR-04, BR-06)
- Tech Doc Sections 3, 5 (Business Logic, Security & Guardrails)

---

_See [ADR Index](./README.md) for all decisions._
