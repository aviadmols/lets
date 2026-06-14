# 30 — Subscriptions (List + Subscription Details)

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system`.
> **Reuses + extends the engine:** `Filament/Resources/InstallmentPlanResource/Pages/ViewInstallmentPlan.php`,
> `plan-events-timeline.blade.php`, `plan-events-log.blade.php`, `PlanEventPresenter`, `EmailPreviewRenderer`.
> **Delta = both `plan_kind`s in one resource + ledger view + re-skin to tokens.**
> **Tokens:** [00-design-system.md](00-design-system.md). **i18n domain:** `subscriptions.*`, `billing.*`, `actions.*`.
> **Pillars served:** installments + recurring. **State machines:** [ARCHITECTURE.md §3.3](../../ARCHITECTURE.md).

---

# Part A — Subscriptions List

## Purpose
One list for **both** plan kinds, with a kind filter, so a merchant can triage everything billable in one place
— installments (with remaining balance + paid/total) and recurring (with frequency).

## Entry points / nav
`Customers ▸ Subscriptions` (`nav.subscriptions`). Deep-links from Customer Details, Orders, Order Errors, Timeline.

## Layout (regions)

```
┌──────────────────────────────────────────────────────────────────────┐
│  Subscriptions                                        [ + Add filter ] │
│  [ All | Installments | Recurring ]   [ 🔍 Search…  ]                   │  ← kind segmented filter + search
│ ──────────────────────────────────────────────────────────────────────│
│ ☐ Customer    Kind          Status     Next charge   Amount/Balance     │
│ ☐ Dana Levi   Installments  [active]   12 Jul        ₪300 / ₪1,200      │  ← shows paid/total + bal
│ ☐ Yossi Cohen Recurring     [active]   20 Jul        ₪89 / 30d          │  ← shows frequency
│ ☐ Maya Bar    Installments  [failed]   —             ₪0 / ₪600          │  ← red badge
│ ☐ Avi Mor     Recurring     [paused]   —             ₪120 / 30d         │
└──────────────────────────────────────────────────────────────────────┘
```

- **Kind segmented filter:** `All | Installments | Recurring` (`subscriptions.filter.kind.*`).
- **+ Add filter** fields: status (badge map), `plan_kind`, next-charge date range, has-failed-charge,
  product, frequency (recurring), remaining-balance range (installments), created date.
- **Table columns:**

| Column | Installments shows | Recurring shows | Source |
|---|---|---|---|
| Customer | customer name | customer name | `customers` |
| Kind | "Installments" | "Recurring" | `plan.plan_kind` |
| Status | badge (§4.2) | badge (§4.2) | `plan.status` (state machine §3.3) |
| Next charge | `next_charge_at` (or `—` if terminal) | `next_charge_at` | plan model |
| Amount / Balance | `paid / total` + remaining balance | `amount / frequency` (e.g. `₪89 / 30d`) | plan model |

> Statuses are **only** the canonical ones (`draft`, `awaiting_first_payment`, `active`, `paused`,
> `completed`, `failed`, `cancelled`). `completed` applies to installments only; recurring never auto-completes.

## States (list)
- **Loading** — skeleton rows; kind filter + search interactive.
- **Empty (first-run)** — `subscriptions.list.empty.first_run`: "No subscriptions yet. Create a plan on a
  product or take a deposit checkout to get started."
- **Empty (filtered)** — `states.empty.no_results` + "Clear filters".
- **Error** — table error + retry.

---

# Part B — Subscription Details

## Purpose
The single plan's full record: header status + next charge / remaining balance, plan items, billing schedule
(installments vs recurring rendered differently), the per-plan payment ledger, the per-plan Timeline, and every
money-moving action — each routed through the ledger + `DocumentPolicy` + Shopify rule (ARCHITECTURE.md §4.4).

## Entry points / nav
From Subscriptions list, Customer Details subscription card, Orders, Order Errors, Timeline deep-links.

## Layout (regions)

```
┌──────────────────────────────────────────────────────────────────────┐
│  ← Plan #PLN-1042 · Dana Levi          [active]      [ ⋯ Actions ]     │  ← header
│  Installments · Next charge 12 Jul · Remaining ₪900 of ₪1,200          │  ← summary line (kind-aware)
│ ──────────────────────────────────────────────────────────────────────│
│  Plan items                                                            │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ 1× Product A …  ₪1,200                                          │  │
│  └────────────────────────────────────────────────────────────────┘  │
│  Billing schedule                                                      │
│  ┌── INSTALLMENTS ─────────────────────┐  OR  ┌── RECURRING ────────┐ │
│  │ Deposit  ₪300  [paid] 1 Jun         │      │ Every 30 days       │ │
│  │ #1       ₪300  [paid] 1 Jul         │      │ Next: 20 Jul        │ │
│  │ #2       ₪300  [due]  12 Jul        │      │ Last: 20 Jun [ok]   │ │
│  │ #3       ₪300  [scheduled] 12 Aug   │      │ Started 20 Apr      │ │
│  │ ── paid ₪600 / ₪1,200 · bal ₪600 ──│      └─────────────────────┘ │
│  │ 🔒 Fulfillment locked until paid    │                             │
│  └─────────────────────────────────────┘                             │
│  Payment ledger (this plan)                                           │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ Date  Context     Amount  Status        Tx                      │  │
│  └────────────────────────────────────────────────────────────────┘  │
│  ▸ Timeline (this plan)                                  (accordion)  │
└──────────────────────────────────────────────────────────────────────┘
```

### Header
- Public plan id, customer (links to Customer Details), **status badge** (§4.2), `⋯ Actions` menu.
- **Summary line is kind-aware:**
  - Installments → `Next charge :date · Remaining :balance of :total`.
  - Recurring → `Every :frequency · Next charge :date`.

### Plan items
Line items in the plan (product, variant, qty, amount). Source: plan items (engine).

### Billing schedule — **two renderings by `plan_kind`**
- **Installments:** deposit row + each installment row (sequence #, amount, status, scheduled/charged date),
  a **paid-of-total progress** summary (`paid / total`, remaining balance), and the **fulfillment-locked
  indicator** until fully paid. When the final payment succeeds, the schedule shows the
  **"Order released for fulfillment"** state (`subscriptions.detail.order_released`).
- **Recurring:** frequency, next cycle date, last cycle result, start date. No total/completion. Each successful
  cycle links to its created fulfillable Shopify order.

| Field | Source |
|---|---|
| Deposit + installment rows (seq, amount, status, date) | installment plan `payments` (engine) |
| paid/total, remaining_balance | `total_amount`, `total_charged`, `outstandingBalance()` (engine) |
| Fulfillment-locked flag + release state | `FulfillmentLockService` + `ReleaseFulfillmentIfFullyPaidJob` (engine) |
| Recurring frequency, next/last cycle | recurring plan fields | laravel-backend (`plan_kind=recurring`) |

### Payment ledger (this plan) — component §4.3
The **immutable per-plan ledger** (ARCHITECTURE.md §3.1). Columns: date · `charge_context` (deposit /
installment / recurring / upsell / retry / manual) · amount · ledger status (badge §4.2: pending / succeeded /
failed / retry_scheduled / refunded / cancelled) · masked transaction ref. **No raw token, no `invoice_url`.**

| Field | Source |
|---|---|
| ledger rows | `payment_ledger` scoped to `plan_id` + `shop_id` | laravel-backend |
| charge_context, amount, status, failure_code | ledger columns | laravel-backend |
| payplus_transaction_uid (masked/short) | ledger | laravel-backend |

### Timeline (this plan) — accordion §4.9 + Timeline rows §4.14
Reuses `ViewInstallmentPlan` Timeline (`plan-events-timeline.blade.php`). Every actor/action: charges, refunds,
state transitions, emails (previewable inline via isolated iframe), webhooks, admin actions, documents.
A raw-log tab (`plan-events-log.blade.php`) is available for power users. **Never render `invoice_url`/`document_url`.**

---

## Actions (Subscription Details) — money-moving, all via §4.4

Each action: confirmation dialog (component §4.12), writes a **ledger event**, calls **`DocumentPolicy`**, and
**updates Shopify** — the spec defines the *confirmation copy*, not the mechanism (`laravel-backend` owns the rule).

| Action | Applies to | Confirmation key | Consent? | Side-effect (reference) |
|---|---|---|---|---|
| **Pause** | active recurring (and active installments if merchant allows) | `actions.pause.confirm` | no | status `active → paused`; ledger + Timeline event; no further auto-charge |
| **Resume** | paused | `actions.resume.confirm` | no | status `paused → active`; recompute `next_charge_at` |
| **Cancel** | any non-terminal | `actions.cancel.confirm` (+ sub-choice for recurring) | no | status `→ cancelled`; ledger + `DocumentPolicy` (cancellation doc) + Shopify update |
| **Charge now** | active/awaiting with a saved token | `actions.charge_now.confirm` | **yes** (future charge on saved token) | writes `pending` ledger → charge via saved reference → success/fail event |
| **Send payment link** | awaiting / failed (no auto-charge path) | `actions.send_payment_link.confirm` | n/a (customer re-confirms) | sends manual-payment email (engine `ManualRecurringPaymentMail`); idempotent via `meta.manual_payment_sent_at` |
| **Refund** | a succeeded ledger row | `actions.refund.confirm` (+ amount + scope) | no | ledger `succeeded → refunded`; `PayPlusInstallmentGateway::refund()`; `DocumentPolicy` (credit doc); optional store-credit/gift-card; Shopify update |

### Cancel semantics (ARCHITECTURE.md §4.4 lists BOTH for recurring)
- **Installments cancel** sub-cases (per §4.4): cancel before full payment · refund deposit only · refund one
  installment · refund all · cancel after full payment but before fulfillment · cancel after fulfillment release.
  The dialog presents the **refund scope** choice where applicable.
- **Recurring cancel** sub-choice: **"Cancel immediately"** vs **"Cancel at end of billing period"** —
  ARCHITECTURE.md §4.4 explicitly lists both, so the dialog must offer both with distinct copy.
  **Default proposed: cancel at end of billing period** (customer keeps what they paid for) — **see D1, ASK Aviad.**

### Charge-now consent (ARCHITECTURE.md §4.3)
"Charge now" hits a saved PayPlus token for an out-of-schedule charge → the confirmation dialog includes the
**consent disclosure** (`upsell.consent.disclosure` pattern adapted: `actions.charge_now.disclosure` — "Your
saved card will be charged :amount now.") and a `customer_consents`-equivalent admin-initiated record is written.

---

## States (Subscription Details)
- **Loading** — header skeleton; schedule skeleton; ledger skeleton rows; Timeline collapsed.
- **Empty** — a `draft` plan with no payments yet: schedule shows the planned rows with all `scheduled`;
  ledger shows `subscriptions.detail.ledger_empty` ("No charges yet."); Timeline shows the creation event only.
- **Error** — per-region error + retry; an action failure surfaces inside the confirmation dialog
  (`states.error.action_failed`) without losing the plan view.
- **Partial** — a charge just fired but the PayPlus webhook hasn't confirmed: the ledger row shows `pending`
  with `states.partial.pending_webhook`; the schedule row stays "due"/"processing" until confirmation, never
  prematurely "paid".

---

## i18n keys (en + he) — selected

| Key | EN | HE |
|---|---|---|
| `subscriptions.list.title` | Subscriptions | מנויים |
| `subscriptions.filter.kind.all` | All | הכול |
| `subscriptions.filter.kind.installments` | Installments | תשלומים |
| `subscriptions.filter.kind.recurring` | Recurring | מנוי חוזר |
| `subscriptions.list.col.kind` | Kind | סוג |
| `subscriptions.list.col.next_charge` | Next charge | חיוב הבא |
| `subscriptions.list.col.amount_balance` | Amount / Balance | סכום / יתרה |
| `subscriptions.list.empty.first_run` | No subscriptions yet. Create a plan on a product or take a deposit checkout to start. | אין עדיין מנויים. צרו תוכנית על מוצר או קבלו מקדמה כדי להתחיל. |
| `subscriptions.detail.remaining_of_total` | Remaining :balance of :total | יתרה :balance מתוך :total |
| `subscriptions.detail.every_frequency` | Every :frequency | כל :frequency |
| `subscriptions.detail.plan_items` | Plan items | פריטי התוכנית |
| `subscriptions.detail.billing_schedule` | Billing schedule | לוח חיובים |
| `subscriptions.detail.deposit` | Deposit | מקדמה |
| `subscriptions.detail.installment_n` | Installment :n | תשלום :n |
| `subscriptions.detail.fulfillment_locked` | Fulfillment locked until fully paid | המימוש נעול עד לתשלום מלא |
| `subscriptions.detail.order_released` | Order released for fulfillment | ההזמנה שוחררה למימוש |
| `subscriptions.detail.payment_ledger` | Payment ledger | יומן תשלומים |
| `subscriptions.detail.ledger_empty` | No charges yet. | אין עדיין חיובים. |
| `subscriptions.detail.timeline` | Timeline | ציר זמן |
| `actions.pause.confirm` | Pause this subscription? No further charges until you resume. | להשהות את המנוי? לא יבוצעו חיובים עד לחידוש. |
| `actions.resume.confirm` | Resume this subscription? The next charge will be scheduled. | לחדש את המנוי? החיוב הבא יתוזמן. |
| `actions.cancel.confirm` | Cancel this subscription? The customer will not be charged again. | לבטל את המנוי? הלקוח לא יחויב שוב. |
| `actions.cancel.recurring.immediately` | Cancel immediately | ביטול מיידי |
| `actions.cancel.recurring.period_end` | Cancel at end of billing period | ביטול בסוף תקופת החיוב |
| `actions.charge_now.confirm` | Charge this subscription now? | לחייב את המנוי עכשיו? |
| `actions.charge_now.disclosure` | The saved card will be charged :amount now. | הכרטיס השמור יחויב ב-:amount כעת. |
| `actions.send_payment_link.confirm` | Email a secure payment link to the customer? | לשלוח ללקוח קישור תשלום מאובטח באימייל? |
| `actions.refund.confirm` | Refund :amount for this charge? | לזכות :amount עבור חיוב זה? |
| `actions.refund.scope.deposit` | Deposit only | מקדמה בלבד |
| `actions.refund.scope.one` | This installment only | תשלום זה בלבד |
| `actions.refund.scope.all` | All payments | כל התשלומים |
| `states.error.action_failed` | The action couldn't be completed. No charge was made. | הפעולה לא הושלמה. לא בוצע חיוב. |

> Status labels reuse `billing.status.*`; ledger-status labels `billing.ledger_status.*`; charge contexts
> `billing.charge_context.*` (all already in `lang/en/billing.php`). Currency via ILS formatter.

---

## RTL notes
- Header status badge + `⋯ Actions` move to the start-side.
- Installments schedule progress + the `paid / total` summary fill from the start-side (right in HE).
- Ledger + schedule numeric/currency cells stay LTR inside RTL rows.
- The recurring frequency string (`Every 30 days`) is fully translated, not a glued number+unit.

## Plan-gate behavior
- Subscriptions list + detail + actions are core (every tier). Tier limits (max active subscriptions) are
  enforced at **creation** time by `saas-multitenancy-billing`; an over-limit shop sees a creation-gate, not a
  view-gate. `TODO-GATE` for the exact limit copy.

## Definition of Done
- **Installments DoF:** deposit + each installment + paid/remaining + next charge shown; awaiting/active/
  completed/failed badges; fulfillment-locked indicator; "order released" state; failed → Order Errors + retry +
  dunning; per-plan ledger + Timeline; four states; EN/HE.
- **Recurring DoF:** saved-method (masked) display via Customer panel; frequency + next/last cycle; cycle →
  fulfillable order link; pause/cancel (immediate vs period-end) with copy; failed cycle → Order Errors + retry;
  per-plan ledger + Timeline; four states; EN/HE.
Linked in [99-i18n-conventions.md](99-i18n-conventions.md).

---

## Open decisions for Aviad (ASK before build)
- **D1 — Recurring cancel default.** Immediate vs end-of-billing-period? Both are specced (ARCHITECTURE.md §4.4
  lists both). **Recommended default: cancel at end of billing period.** Confirm the default + whether merchants
  may choose per-cancel.
- **D2 — Can merchants pause/cancel *installments* (not just recurring)?** §4.7 has a per-shop "pause/cancel
  allowance" toggle. Recommend: installments cancel allowed (with refund-scope), pause **not** offered for
  installments by default (a deposit-installment plan pausing is unusual). Confirm.
- **D3 — Manual "Charge now" on a recurring plan** — allowed for all tiers, or Pro only? `TODO-GATE`.
