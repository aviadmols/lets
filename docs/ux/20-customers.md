# 20 — Customers (List + Customer Details)

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system`.
> **Reuses + extends the engine:** `Filament/Resources/CustomerResource/Pages/ViewCustomer.php`,
> `resources/views/filament/components/customer-subscriptions.blade.php`,
> `customer-future-payments.blade.php`, `plan-events-timeline.blade.php`, `PlanEventPresenter`,
> `EmailPreviewRenderer`. **Delta = multi-tenant + both `plan_kind`s + re-skin to tokens.**
> **Tokens:** [00-design-system.md](00-design-system.md). **i18n domain:** `customers.*`, `status.*`, `states.*`.
> **Pillars served:** platform (surfaces installments + recurring + upsell per customer).

---

# Part A — Customers List

## Purpose
Find a customer fast; see at a glance who has active subscriptions and whose payments are healthy.

## Entry points / nav
`Customers ▸ Customers` (`nav.customers`). Also reached from Subscriptions/Orders/Timeline deep-links.

## Layout (regions)

```
┌──────────────────────────────────────────────────────────────┐
│  Customers                                  [ + Add filter ]  │
│  [ 🔍 Search name or email…            ]                       │
│ ──────────────────────────────────────────────────────────────│
│ ☐ Customer            Email                 Active subs  Pay   │  ← table header
│ ☐ Dana Levi           dana@…                 2           ● green│
│ ☐ Yossi Cohen         yossi@…                1           ● amber│
│ ☐ Maya Bar            maya@…                 0           ● gray │
└──────────────────────────────────────────────────────────────┘
```

- **Search** — name or email (`customers.list.search_placeholder`).
- **+ Add filter** (component §4.4) — fields: active-subscriptions count, payment status, `plan_kind`,
  segment membership, created date, tags. Operator labels `common.filter_op.*`.
- **Table** (component §4.3, with checkbox for bulk + row link):

| Column | Field | Source |
|---|---|---|
| Customer | full name | `customers` table (engine, `shop_id`-scoped) |
| Email | email | `customers` |
| Active subscriptions | count of plans where status=`active` (both kinds) | derived; **TODO-DATA: counter contract** |
| Payment status (dot) | health of latest charge across the customer's plans | derived from ledger; **TODO-DATA: status derivation** |

**Payment status dot** (tokens from §1.3): green = last charge succeeded / all healthy · amber =
retry-scheduled or awaiting first payment · red = a failed charge needs attention · gray = no active plan.

## Actions
| Action | Trigger | Confirmation | Side-effect |
|---|---|---|---|
| Open customer | row click | none | → Customer Details |
| Bulk: add to segment / export | select + bulk bar | none (export) | TODO-DATA: bulk contract |

No money-moving actions in the list.

## States
- **Loading** — 6–8 skeleton rows; search + filter interactive.
- **Empty (first-run)** — `customers.list.empty.first_run`: "No customers yet. They appear after your store
  takes its first order." (engine syncs customers on `orders/paid`).
- **Empty (filtered)** — `states.empty.no_results` + "Clear filters".
- **Error** — table error variant + retry.

---

# Part B — Customer Details

## Purpose
The full customer picture: spend + activity KPIs, every subscription grouped by shipping address, upcoming and
recent orders, the per-customer Timeline, and right-rail context (overview, comms prefs, payment methods,
segments, tags, credits). This is the engine's `ViewCustomer` re-skinned and extended to both `plan_kind`s.

## Entry points / nav
From Customers list row, Subscription detail (customer link), Order/Timeline deep-links.

## Layout — 2 columns (70% main / 30% right sidebar; tokens `--rc-split`)

```
┌───────────────────────────────────────────────┬──────────────────┐
│  ← Dana Levi                       [ ⋯ Actions]│  RIGHT SIDEBAR   │
│ ┌─────────┐ ┌─────────┐ ┌─────────┐            │ ┌──────────────┐ │
│ │ Subscr. │ │ Orders  │ │ Streak  │            │ │Customer over-│ │
│ │ spend   │ │   n     │ │  n mo   │            │ │view          │ │
│ │ ₪x      │ │         │ │         │            │ └──────────────┘ │
│ └─────────┘ └─────────┘ └─────────┘            │ ┌──────────────┐ │
│  Subscriptions                                  │ │Communication │ │
│ ┌─────────────────────────────────────────────┐│ │preferences   │ │
│ │ 📍 Shipping address #1                       ││ └──────────────┘ │
│ │  ┌── Installments plan ── [active] ────────┐ ││ ┌──────────────┐ │
│ │  │ ₪paid / ₪total · bal ₪x · next 12 Jul   │ ││ │Payment       │ │
│ │  └──────────────────────────────────────────┘ ││ │methods (mask)│ │
│ │  ┌── Recurring plan ── [active] ───────────┐ ││ └──────────────┘ │
│ │  │ Every 30d · next 20 Jul                  │ ││ ┌──────────────┐ │
│ │  └──────────────────────────────────────────┘ ││ │Segments      │ │
│ └─────────────────────────────────────────────┘│ └──────────────┘ │
│ ▸ Upcoming orders                    (accordion)│ ┌──────────────┐ │
│ ▸ Recent orders                      (accordion)│ │Shopify tags  │ │
│ ▸ Timeline                           (accordion)│ └──────────────┘ │
│                                                 │ ┌──────────────┐ │
│                                                 │ │Credits       │ │
│                                                 │ └──────────────┘ │
└───────────────────────────────────────────────┴──────────────────┘
```

### Header + KPIs (component §4.1)
| KPI | Field | Source |
|---|---|---|
| Subscription spend | lifetime Σ `succeeded` ledger for this customer | ledger aggregate · TODO-DATA |
| Orders | count of orders linked to this customer | order sync · TODO-DATA |
| Streak | consecutive months with an active subscription | derived · TODO-DATA |

Header `⋯ Actions`: Email customer · Add to segment · Open portal link (generates a signed magic link, see
[60-customer-portal.md](60-customer-portal.md)) · View in Shopify.

### Subscriptions, grouped per shipping address (component §4.11)
Reuses `customer-subscriptions.blade.php`, **extended to render both `plan_kind`s**:
- **Group** = one card-group per shipping address (Recharge groups subscriptions by address).
- **Installments card:** paid/total, remaining balance, next charge, paid-of-total progress bar,
  fulfillment-locked indicator if not fully paid, status badge.
- **Recurring card:** frequency, next charge date, status badge.
- A `failed` card shows a red left-rule + "View error" → Order Errors. `awaiting_first_payment` shows amber.

| Field | Source |
|---|---|
| Shipping address grouping | order/address data | shopify-integration / engine · TODO-DATA |
| Plan kind, status, next_charge, frequency | plan model (`plan_kind` discriminator) | laravel-backend |
| paid/total, remaining_balance | installment plan fields | engine (`total_amount`, `total_charged`, `outstandingBalance()`) |
| Fulfillment-locked flag | `FulfillmentLockService` state | laravel-backend |

### Upcoming orders (accordion §4.9)
Reuses `customer-future-payments.blade.php`. Projected next charges across this customer's active plans
(recurring next cycle + installment next sequence). Each row: date, plan, projected amount, kind.

### Recent orders (accordion §4.9)
Processed/fulfillable orders for this customer (recurring cycles, released installment orders, upsell child
orders). Each row links to the order + its ledger entry.

### Timeline (accordion §4.9, components §4.14)
The **per-customer** Timeline — the engine's `plan-events-timeline.blade.php` aggregated across all the
customer's plans, `shop_id`-scoped. Shows every actor + action chronologically: charges, refunds, state
changes, emails (previewable inline via `EmailPreviewRenderer` isolated iframe), webhooks, admin actions,
documents. **Never render `invoice_url`/`document_url`.** Email rows expose a "Preview" button.

### Right sidebar panels (component §4.10)
| Panel | Contents | Source |
|---|---|---|
| Customer overview | name, email, phone, location, customer since | `customers` |
| Communication preferences | email opt-in, reminder prefs, language | per-customer prefs · TODO-DATA |
| Payment methods | **masked** card brand + last-4 + expiry only (`Visa •••• 4242 12/27`) | `InstallmentPaymentMethod` (engine) — **never raw token** |
| Segments | segment chips this customer belongs to | segments · TODO-DATA |
| Shopify tags | tags synced from Shopify (read-only) | shopify-integration · TODO-DATA |
| Credits | store-credit / gift-card balance + history | `InstallmentStoreCredit` (engine) |

---

## Actions (Customer Details)

| Action | Trigger | Confirmation/consent | Side-effect |
|---|---|---|---|
| Email customer | header ⋯ | none | opens compose / triggers a template send |
| Open portal link | header ⋯ | none | generates signed magic link (`SignedUrlService::portalShowUrl()`); shows copyable URL |
| Add to segment | header ⋯ | none | membership write |
| Preview email | Timeline email row → Preview | none | isolated `iframe srcdoc` (read-only) |
| Open plan | subscription card | none | → Subscription detail |
| View error | failed card | none | → Order Errors filtered to this plan |

Plan-level money actions (pause/cancel/charge/refund) live on the **Subscription detail** screen
([30-subscriptions.md](30-subscriptions.md)), not here — this screen links to them.

---

## States (Customer Details)
- **Loading** — KPI skeletons; subscription cards skeleton; accordions collapsed with skeleton on expand;
  right panels skeleton.
- **Empty** — customer exists but has **no subscriptions**: subscriptions region shows
  `customers.detail.no_subscriptions` ("This customer has no active plans."). Timeline can still have order/email events.
- **Empty (no timeline)** — `customers.detail.timeline_empty` ("No activity recorded yet.").
- **Error** — per-region error variant + retry; one panel failing must not blank the page.
- **Partial** — a webhook not yet arrived (e.g. an order just charged): show the ledger row with a
  `states.partial.pending_webhook` note ("Awaiting confirmation from PayPlus…") rather than a missing/erroring row.

---

## i18n keys (en + he) — selected (full catalog in 99-i18n-conventions)

| Key | EN | HE |
|---|---|---|
| `customers.list.title` | Customers | לקוחות |
| `customers.list.search_placeholder` | Search name or email | חיפוש שם או אימייל |
| `customers.list.col.customer` | Customer | לקוח |
| `customers.list.col.email` | Email | אימייל |
| `customers.list.col.active_subs` | Active subscriptions | מנויים פעילים |
| `customers.list.col.payment_status` | Payment | תשלום |
| `customers.list.empty.first_run` | No customers yet. They appear after your store takes its first order. | אין עדיין לקוחות. הם יופיעו לאחר ההזמנה הראשונה בחנות. |
| `customers.detail.kpi.subscription_spend` | Subscription spend | הוצאה על מנויים |
| `customers.detail.kpi.orders` | Orders | הזמנות |
| `customers.detail.kpi.streak` | Streak | רצף |
| `customers.detail.subscriptions_title` | Subscriptions | מנויים |
| `customers.detail.shipping_address` | Shipping address | כתובת למשלוח |
| `customers.detail.no_subscriptions` | This customer has no active plans. | ללקוח זה אין תוכניות פעילות. |
| `customers.detail.upcoming_orders` | Upcoming orders | הזמנות קרובות |
| `customers.detail.recent_orders` | Recent orders | הזמנות אחרונות |
| `customers.detail.timeline` | Timeline | ציר זמן |
| `customers.detail.timeline_empty` | No activity recorded yet. | טרם נרשמה פעילות. |
| `customers.detail.panel.overview` | Customer overview | סקירת לקוח |
| `customers.detail.panel.comm_prefs` | Communication preferences | העדפות תקשורת |
| `customers.detail.panel.payment_methods` | Payment methods | אמצעי תשלום |
| `customers.detail.panel.segments` | Segments | פלחים |
| `customers.detail.panel.tags` | Shopify tags | תגיות Shopify |
| `customers.detail.panel.credits` | Credits | זיכויים |
| `customers.detail.action.open_portal` | Copy portal link | העתקת קישור לפורטל |
| `states.partial.pending_webhook` | Awaiting confirmation from PayPlus… | ממתין לאישור מ-PayPlus… |

> Plan status labels reuse `billing.status.*` (already in `lang/en/billing.php`). Currency uses the ILS formatter.

---

## RTL notes
- The 70/30 split **mirrors**: main column on the right, sidebar on the left in HE.
- Installments progress bar fills from the start-side (right in HE).
- Masked card string (`Visa •••• 4242`) stays LTR inside an RTL panel.
- Timeline timestamps + actor chips mirror; the email "Preview" button moves to the start-side.

## Plan-gate behavior
- **Segments** panel/feature is gated (Growth+, `TODO-GATE`) — shows the locked panel state with upgrade CTA.
- Customer details + subscriptions + Timeline are available on every tier.

## Definition of Done
Platform DoF: Customer Details with KPIs + per-address subscription cards (both `plan_kind`s) + upcoming/recent
orders + per-customer Timeline (every actor/action, emails previewable inline, no `invoice_url`) + right sidebar +
all four states + EN/HE + RTL. Linked in [99-i18n-conventions.md](99-i18n-conventions.md#platform).

---

## Open decisions for Aviad
- **D1 — "Streak" definition.** Consecutive active-subscription months? Or consecutive successful charges?
  Recommend: consecutive months with ≥1 active plan. Confirm so `laravel-backend` builds the right aggregate.
- **D2 — Subscription-spend scope.** Lifetime, or within a range? Recommend lifetime (matches Recharge's
  "Subscription Spend"). Confirm.
- **D3 — Communication preferences source.** Do per-customer comms prefs exist in the engine, or is language the
  only pref in v1? Flagged `TODO-DATA` for `laravel-backend`.
