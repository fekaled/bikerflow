# BikerFlow — PRD Rules Quick Reference

## Business Rules

| ID | Rule | Constraint |
|----|------|-----------|
| **BR-01** | Workflow Locking | Once a shift starts, `workflow_type` (Live vs. Manual) cannot be changed. Bake into model architecture from day one. |
| **BR-02** | PIX Verification | PIX keys must be validated against the Bank API (Account Holder Name) before payment is enabled. Admin must manually verify before first payout. |
| **BR-03** | Manual Release | No automated payments occur without explicit Admin "Approval" per shift. |
| **BR-04** | Granular Failure | A failed payment for Biker A does not stop the successful payment of Biker B. |
| **BR-05** | Last Minute Biker | Only the Admin can add/replace bikers once a shift has been initiated. |
| **BR-06** | Payment Retries | All "Retry" actions must be logged as unique transaction attempts to prevent double-billing. |

## Payout Formula (Critical — BR-03)

```
Payout = 0.00                                    if trips_count = 0
Payout = base_fee + (biker_rate × trips_count)   if trips_count > 0
```

The **Base Fee** is a "show-up" fee that is only triggered if the biker completes at least one delivery.

## Revenue Formula

```
Revenue = (restaurant_rate × trips_count) - Payout
```

## User Personas

| Persona | Role | Key Actions |
|---------|------|-------------|
| **Restaurant Manager** | The Input | Chooses Live Tick or End-of-Shift Entry. Tracks deliveries. Closes shift. Pays via PIX or marks for Invoice. |
| **Biker** | The Worker | Read-only dashboard. Sees live trip count and payment status (Pending → Paid). Self-registers via secure link. |
| **Company Manager** | The Admin | Manages Restaurant contracts (rates) and Biker data (PIX). Reviews closed shifts. Releases payments. Handles retries. |

## User Stories

| ID | Story |
|----|-------|
| **US-01** | As a Restaurant Manager, I want to print a PDF "Trip Sheet" for manual tracking so I have a backup during busy rushes. |
| **US-02** | As an Admin, I want to override the default trip rate for specific "Holiday" shifts to handle special pricing. |
| **US-03** | As an Admin, I want to see a "Margin Dashboard" showing Revenue vs. Payout for the month. |
| **US-04** | As a Biker, I want to be notified if my PIX payment fails so I can contact the manager to fix my data. |

## Financial Constraints

- All monetary values: `DECIMAL(12,2)` in MySQL, **BCMath** in PHP.
- Currency: **BRL** (Brazilian Real).
- Two distinct rates per trip: **Restaurant Rate** (revenue) and **Biker Rate** (cost).
- Base Fee is a fixed "show-up" fee per shift.

## Security Constraints

- Agent isolation: all work within `/workspaces/bikerflow`.
- Workflow type locked on shift start (BR-01).
- PIX key identity verified before first payout (BR-02).
- Audit logging for all payment retries (BR-06).
- WhatsApp Magic Link for frictionless auth.
