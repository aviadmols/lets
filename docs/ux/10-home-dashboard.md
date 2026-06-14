# 10 — Home Dashboard

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system` (custom Filament/Livewire page).
> **Tokens:** [00-design-system.md](00-design-system.md). **i18n domain:** `dashboard.*`, `states.*`.
> **Pillars served:** platform (rolls up installments + recurring + upsell).
> **Status:** data-pending (KPI aggregate contract owned by `laravel-backend`).

---

## Purpose

The merchant's at-a-glance command center: how much revenue processed, how the subscriber base is moving
(new vs churned), and where attention is needed (order errors). First screen after login.

## Entry points / nav

`nav.home` (top of sidebar) + brand-row click. Tabs deep-link to focused views; KPI cards and the
performance table deep-link into Subscriptions / Orders / Order Errors.

---

## Layout (regions)

```
┌──────────────────────────────────────────────────────────────┐
│  Home                                          [ Date range ▾ ]│  ← page title + date-range picker
│  Home | Revenue | Upcoming orders | Order errors |             │  ← top tab bar
│       Subscriptions | Customers | Benchmarks                   │
│ ──────────────────────────────────────────────────────────────│
│ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐           │  ← 4 KPI cards
│ │Processed │ │ Active   │ │  New     │ │ Churned  │           │
│ │ Revenue  │ │Subscriber│ │Subscriber│ │Subscriber│           │
│ │ ₪x ▲ y%  │ │  n  ▲ y% │ │  n ▲ y%  │ │  n ▼ y%  │           │
│ └──────────┘ └──────────┘ └──────────┘ └──────────┘           │
│ ┌──────────────────────────────────────────────────────────┐ │  ← promo / onboarding banner
│ │  [promo banner — dismissible]                             │ │
│ └──────────────────────────────────────────────────────────┘ │
│  Performance at a glance                                       │  ← section heading
│ ┌──────────────────────────────────────────────────────────┐ │
│ │ Metric            | This period | Prev period |  Δ        │ │  ← performance table
│ │ MRR               | …           | …           | ▲ …       │ │
│ │ Installment bal.  | …           | …           | …         │ │
│ │ Upsell revenue    | …           | …           | ▲ …       │ │
│ │ Charge-success %  | …           | …           | …         │ │
│ └──────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### Region 1 — Page title + date-range picker
- Title `dashboard.title`. Date-range picker (presets: Today / 7d / 30d / 90d / This month / Custom).
  The selected range scopes **every** KPI + the performance table + tab content.

### Region 2 — Top tab bar (component §4.8)
`Home | Revenue | Upcoming orders | Order errors | Subscriptions | Customers | Benchmarks`.
Each is a focused view of the same period:
- **Home** — the default overview (KPIs + performance table below).
- **Revenue** — revenue breakdown by charge_context (deposit/installment/recurring/upsell).
- **Upcoming orders** — projected next charges (links into Orders → Upcoming).
- **Order errors** — the dunning summary (links into Order Errors).
- **Subscriptions** — active/paused/failed counts by `plan_kind`.
- **Customers** — new/returning/churned over the range.
- **Benchmarks** — gated (Pro); cohort comparison (TODO-GATE).

### Region 3 — 4 KPI cards (component §4.1)

| Card | KPI key | Value | Delta | good_direction |
|---|---|---|---|---|
| Processed Revenue | `dashboard.kpi.processed_revenue` | sum of `succeeded` ledger amounts in range (ILS) | vs previous equal range, % | up = good |
| Active Subscribers | `dashboard.kpi.active_subscribers` | distinct customers with ≥1 `active` plan (either kind) | vs previous range | up = good |
| New Subscribers | `dashboard.kpi.new_subscribers` | plans first activated in range | vs previous range | up = good |
| Churned Subscribers | `dashboard.kpi.churned_subscribers` | plans cancelled/failed-terminal in range | vs previous range | **up = bad** (delta color inverts) |

### Region 4 — Promo / onboarding banner
- **First-run merchant:** onboarding banner — "Connect PayPlus, create your first plan."
  CTA → Settings → PayPlus Connection. Dismissible per-shop.
- **Established merchant:** product promo / upgrade nudge (gated-feature teaser), dismissible.

### Region 5 — "Performance at a glance" table
A compact metric table for the selected range with This period / Prev period / Δ columns.

| Metric | Source | Notes |
|---|---|---|
| MRR | recurring active plans normalized to monthly | recurring pillar only |
| Installment balance outstanding | Σ `remaining_balance` of active installment plans | installments pillar |
| Upsell revenue | Σ `succeeded` ledger where `charge_context=upsell` | upsell pillar |
| Charge-success rate | succeeded / attempted charges in range | observability |
| Failed charges | count of `failed` ledger rows in range | links to Order Errors |

---

## Data fields (source: which backend contract)

All KPI + table values come from a **dashboard aggregate contract** that `laravel-backend` must expose,
scoped to `shop_id` and the selected date range. Marked `TODO-DATA` until that contract is confirmed.

| Field | Source | Status |
|---|---|---|
| Processed revenue (range, ILS) | aggregate over `payment_ledger` (status=succeeded) | TODO-DATA |
| Active / new / churned subscriber counts | aggregate over plan models + state-transition events | TODO-DATA |
| Delta vs previous range | aggregate computes both ranges | TODO-DATA |
| MRR | recurring plans → monthly normalization | TODO-DATA (normalization rule) |
| Installment balance outstanding | Σ active installment `remaining_balance` | TODO-DATA |
| Upsell revenue | ledger filtered by `charge_context=upsell` | TODO-DATA |
| Charge-success rate | ledger succeeded/attempted | TODO-DATA |
| Sparkline series (D1) | time-bucketed aggregate | TODO-DATA (deferred) |

> **Question queued for `laravel-backend`:** confirm the exact KPI keys + aggregate shape (a single
> `DashboardMetrics::for($shop, $range)` returning the values above), the MRR normalization rule, and whether
> deltas are computed server-side. Until confirmed, this page is **data-pending**, not buildable.

---

## States

- **Loading** — every KPI card shows the skeleton variant (§4.1); the performance table shows 4–6 skeleton rows;
  tabs are interactive (switching re-triggers load).
- **Empty (first-run)** — shop has no plans/orders yet: KPI values render `—`, and the **onboarding banner**
  takes over as the primary content with a "Create your first plan" CTA. `dashboard.empty.first_run.*`.
- **Empty (range has no data)** — valid established shop but the selected range has zero activity: KPIs show
  `—` with `states.kpi.no_data`; table shows `states.empty.no_results` with "Widen date range".
- **Error** — a KPI/table that fails shows the error variant (`!` + retry). One card failing does **not** blank
  the others — each card loads independently. `states.error.generic` + retry.
- **Partial** — some KPIs resolved, others still loading (independent loads) → resolved cards show values,
  pending cards keep skeletons. Acceptable steady-state for a few hundred ms.

---

## Actions

| Action | Trigger | Confirmation | Side-effect |
|---|---|---|---|
| Change date range | picker | none | re-scopes all metrics |
| Switch tab | tab click | none | loads focused view |
| Dismiss banner | banner ✕ | none | persists dismissal per-shop |
| Drill into metric | click KPI/table row | none | deep-link to Subscriptions / Orders / Order Errors filtered to that metric+range |

No money-moving actions on this screen.

---

## i18n keys (en + he)

| Key | EN | HE |
|---|---|---|
| `dashboard.title` | Home | בית |
| `dashboard.tab.home` | Home | בית |
| `dashboard.tab.revenue` | Revenue | הכנסות |
| `dashboard.tab.upcoming_orders` | Upcoming orders | הזמנות קרובות |
| `dashboard.tab.order_errors` | Order errors | שגיאות הזמנה |
| `dashboard.tab.subscriptions` | Subscriptions | מנויים |
| `dashboard.tab.customers` | Customers | לקוחות |
| `dashboard.tab.benchmarks` | Benchmarks | מדדי השוואה |
| `dashboard.kpi.processed_revenue` | Processed revenue | הכנסות שעובדו |
| `dashboard.kpi.active_subscribers` | Active subscribers | מנויים פעילים |
| `dashboard.kpi.new_subscribers` | New subscribers | מנויים חדשים |
| `dashboard.kpi.churned_subscribers` | Churned subscribers | מנויים שעזבו |
| `dashboard.performance.title` | Performance at a glance | ביצועים במבט מהיר |
| `dashboard.performance.this_period` | This period | תקופה נוכחית |
| `dashboard.performance.prev_period` | Previous period | תקופה קודמת |
| `dashboard.performance.metric.mrr` | Monthly recurring revenue | הכנסה חודשית חוזרת |
| `dashboard.performance.metric.installment_balance` | Installment balance outstanding | יתרת תשלומים פתוחה |
| `dashboard.performance.metric.upsell_revenue` | Upsell revenue | הכנסות מהצעות נלוות |
| `dashboard.performance.metric.charge_success` | Charge-success rate | שיעור חיוב מוצלח |
| `dashboard.performance.metric.failed_charges` | Failed charges | חיובים שנכשלו |
| `dashboard.range.today` | Today | היום |
| `dashboard.range.7d` | Last 7 days | 7 ימים אחרונים |
| `dashboard.range.30d` | Last 30 days | 30 ימים אחרונים |
| `dashboard.range.90d` | Last 90 days | 90 ימים אחרונים |
| `dashboard.range.month` | This month | החודש |
| `dashboard.range.custom` | Custom | מותאם |
| `dashboard.empty.first_run.title` | Let's process your first payment | בואו נעבד את התשלום הראשון |
| `dashboard.empty.first_run.body` | Connect your PayPlus account and create a plan to see live numbers here. | חברו את חשבון PayPlus וצרו תוכנית כדי לראות כאן נתונים חיים. |
| `dashboard.empty.first_run.cta` | Connect PayPlus | חיבור PayPlus |
| `states.kpi.no_data` | No data for this range | אין נתונים לטווח זה |
| `states.kpi.error` | Couldn't load | טעינה נכשלה |
| `states.error.generic` | Something went wrong. | משהו השתבש. |
| `states.error.retry` | Retry | נסו שוב |
| `states.empty.no_results` | No results match your filters. | אין תוצאות התואמות את הסינון. |

> **Currency/number formatting:** revenue uses the ILS locale formatter (`₪` placed per locale), never a
> hardcoded string. Counts use `trans_choice` where labeled with units.

---

## RTL notes

- Date-range picker moves to the start-side (left in HE); the dropdown opens RTL.
- KPI card order mirrors right-to-left; the delta arrow (▲/▼) is **vertical** so it does not flip, but the
  `+/-%` sign placement follows RTL number formatting.
- Performance table columns mirror; numeric cells stay LTR-internal inside RTL rows.
- `₪` symbol placement follows HE convention (typically after the number) via the formatter.

---

## Plan-gate behavior

- **Benchmarks** tab is gated (Pro, `TODO-GATE`): shows the locked state (`states.gate.locked`) with an
  inline upgrade CTA rather than data.
- All other tabs + KPIs are available on every tier (Starter sees its own data).

## Definition of Done

Platform DoF: Home KPI dashboard with loading skeletons + first-run empty state, period scoping,
drill-through deep-links, EN+HE keys, RTL. Linked in [99-i18n-conventions.md](99-i18n-conventions.md#platform).

---

## Open decisions for Aviad

- **D1 — Which 4 KPIs on the cards?** The brief names *Processed Revenue / Active / New / Churned Subscribers*
  (specced above). The agent brief alternatively suggested *MRR / Active subs / Installment balance / Upsell
  revenue*. Recommendation: keep the brief's 4 subscriber-centric cards on top and put MRR / installment balance /
  upsell revenue in the **performance table** (done above). Confirm.
- **D2 — Sparklines now or Phase 8?** Deferred to Phase 8 by default (needs time-series contract). Confirm.
