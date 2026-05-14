# 📄 Product Requirements Document: "BikerFlow" Automation

## 1. Executive Summary
**Problem:** The current manual process of tracking deliveries via paper, calculating payouts, and manually entering PIX keys in a banking app is time-consuming and prone to human error.
**Solution:** A web/mobile platform that digitizes delivery tracking at the restaurant level, automates payout calculations based on negotiated rates, and integrates with a banking API for bulk PIX payments.

---

## 2. User Personas & Main Flows
### A. The Restaurant Manager (The "Input")
* **Workflow Choice:** At shift start, chooses between **"Live Tick"** (real-time + button) or **"End-of-Shift Entry"** (manual total entry).
* **Tracking:** Increments delivery counts for assigned bikers.
* **Collection:** At shift close, sees the total amount owed to the company. Can choose to **Pay via PIX immediately** or **Mark for Invoice**.

### B. The Biker (The "Worker")
* **Visibility:** Accesses a read-only dashboard to see their live trip count and payment status (`Pending` -> `Paid`).
* **Onboarding:** Can self-register personal data and PIX keys via a secure link.

### C. The Company Manager (The "Admin/Controller")
* **Registry:** Manages Restaurant contracts (Rates) and Biker data (PIX).
* **Approval:** Reviews "Closed" shifts, verifies margins, and clicks "Release Payment."
* **Recovery:** Handles payment failures via a "Retry" button.

---

## 3. Rate & Revenue Management
The system must track two distinct values per trip to calculate the company's margin:
* **Restaurant Rate (Revenue):** What the restaurant is charged.
* **Biker Rate (Cost):** What the biker is paid.
* **Base Fee:** A fixed "show-up" fee paid to the biker regardless of trip count (as per user clarification).

> **Formula:**
> $$\text{Biker Payout} = \text{Base Fee} + (\text{Biker Rate} \times \text{Trips})$$
> $$\text{Company Revenue} = (\text{Restaurant Rate} \times \text{Trips}) - \text{Biker Payout}$$

---

## 4. Key Business Rules (The "Guardrails")

| ID | Rule | Description |
| :--- | :--- | :--- |
| **BR-01** | **Workflow Locking** | Once a shift starts, the tracking method (Live vs. Manual) cannot be changed. |
| **BR-02** | **PIX Verification** | PIX keys must be validated against the Bank API (Account Holder Name) before payment is enabled. |
| **BR-03** | **Manual Release** | No automated payments occur without explicit Admin "Approval" per shift. |
| **BR-04** | **Granular Failure** | A failed payment for Biker A does not stop the successful payment of Biker B. |
| **BR-05** | **Last Minute Biker** | Only the Admin can add/replace bikers once a shift has been initiated. |
| **BR-06** | **Payment Retries** | All "Retry" actions must be logged as unique transaction attempts to prevent double-billing. |

---

## 5. Functional Requirements (User Stories)
* **US-01:** As a Restaurant Manager, I want to print a PDF "Trip Sheet" for manual tracking so I have a backup during busy rushes.
* **US-02:** As an Admin, I want to override the default trip rate for specific "Holiday" shifts to handle special pricing.
* **US-03:** As an Admin, I want to see a "Margin Dashboard" showing Revenue vs. Payout for the month.
* **US-04:** As a Biker, I want to be notified if my PIX payment fails so I can contact the manager to fix my data.

---

## 6. Future Enhancements (Backlog)
* **Timer-Based Auto-Pay:** Move from "Manual Release" to "Auto-Release at 8:00 AM" once trust is established.
* **Automatic Invoicing:** Integrate with an ERP to generate and email invoices to restaurants automatically on Mondays.
* **OCR Integration:** Use the camera to "read" handwritten trip sheets for restaurants that refuse to go digital.

---

