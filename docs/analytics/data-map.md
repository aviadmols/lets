# Analytics — data map

Every metric in the owner's spec (`spec.md`, sections 0–8), mapped to the LETS schema it can be computed
from, or marked **NOT TRACKED** with what would have to exist first. A card whose source is not tracked
renders the `not_tracked` empty state (`<x-rc.chart.empty variant="not_tracked" />`, a `—` value);
it never shows a zero or an invented number.

Legend: **✔ tracked** · **◐ partial** (derivable with a stated limitation) · **✖ not tracked**.

## Definitions we chose

| Term | Definition in LETS |
|---|---|
| Subscription | A row of `installment_plans` with `plan_kind = recurring` (PayPlus rail) **or** a row of `subscription_contracts` (Shopify-Payments rail). Deposit/instalment plans (`plan_kind = installments`) are *not* subscriptions — they belong to Payments. |
| Active subscription | `installment_plans.status = active` · `subscription_contracts.status = ACTIVE`. `paused`, `failed`, `awaiting_payment`, `awaiting_*` are outside the active book. |
| Subscriber | A person with ≥ 1 active subscription. Person key (`Sql::planCustomerKey`): `shopify_customer_id` → `external_customer_id` → lower(`customer_email`) → the plan itself. A contract's `shopify_customer_gid` tail is the same id, so one shopper on both rails is **one** subscriber. |
| MRR | Cycle amount normalised to a month (`Support\Mrr`): month cadences divide exactly (quarterly ÷3, yearly ÷12, every N months ÷N); day cadences × 30.44/days. Spec's `price × 30 / interval_days` is the same idea; exact month division keeps a ₪100 monthly plan at ₪100. `no_charge` (comped) plans count as subscribers, **₪0** MRR. |
| Frequency | One key per cadence (`Support\Frequency`): `m<N>` every N months, `d<N>` every N days. `quarterly×1` ≡ `monthly×3` = `m3`. |
| Selling plan | `installment_plans.product_subscription_plan_id → product_subscription_plans.plan_name`; plans without one = "No selling plan"; contracts = "Shopify subscriptions". |
| Country | **Israel only.** No table stores a customer country (only `meta.import.address.country` on imported plans). The Country chip renders disabled with that explanation. |
| Time zone | Buckets are calendar days in the app time zone (UTC). Israel is UTC+2/+3, so an event after 21:00/22:00 local lands on the next day. |
| History | Point-in-time values (active at a past date, the trend line) are **walked back from today**: today's count − additions after T + reductions after T. Every line ends on the KPI. |

### Movements (the spec's New / Reactivated / Resumed / Paused / Churned / Expired)

Source: `activity_events` `kind = status_changed` written by `HasGuardedStatus::transitionTo` with
`details.from`/`details.to` and `payment_id IS NULL` (a plan move, not a payment's), joined to the plan.
Classified by `Subscribers\MovementLog::classify()`:

| Move | Type |
|---|---|
| pre-active (`draft`, `awaiting_first_payment`, `awaiting_activation`) → `active` | New |
| plan created already `active` (CSV import, hand-typed, settled in one request — no activation event) | New, at `created_at` |
| `paused → active` | Resumed |
| `failed`/`awaiting_payment → active` | Reactivated (a lapsed subscription came back; `cancelled` is terminal in LETS, so a true "un-cancel" cannot happen — the customer gets a new plan, which counts as New) |
| `active → paused` | Paused (includes our own dunning hold, `action = unpaid_cycle_held`) |
| `active → cancelled` | Cancelled / Churned |
| `active → failed`/`awaiting_payment` | Cancelled / Churned (involuntary) |
| `active → completed` | Expired |
| anything not crossing `active` (e.g. `paused → cancelled`) | not a book movement |

Shopify rail: `subscription_contracts.created_at` = New; Timeline kinds `shopify_subscription_paused/resumed/cancelled`
(joined via `details.contract_gid`) = Paused/Resumed/Cancelled. **Limitation:** a contract cancelled or expired in
Shopify's own admin updates the mirror's `status` without a Timeline row, so it is invisible to movements
(the KPI is right, the bar is missing). Contract `EXPIRED` likewise has no event.

Subscriber level: a person crosses the edge only when their first active subscription arrives (0→1) or their
last one leaves (1→0) — `MovementSummary::crossings()`.

## 1. Subscribers

### 1.1 Overview — **built** (`Subscribers\SubscribersOverviewQuery`)

| Metric | Status | Source |
|---|---|---|
| Active subscribers / subscriptions / product qty / MRR | ✔ | `ActiveBook::totals()` (one SQL aggregate over the union of both rails). Qty: 1 per plan (a PayPlus plan renews one line); contracts = Σ `lines[].quantity`. |
| KPI deltas | ✔ | Same values at the comparison window's end, walked back through movements. MRR history uses each subscription's *current* MRR (price edits are not versioned). |
| Subscribers / subscriptions trend + activity panels | ✔ | Movements above. |
| By delivery interval / by selling plan / plan × frequency table | ✔ | `ActiveBook::byFrequency/bySellingPlan/planByFrequency` — **as of today** (labelled "Active today"); historical composition is not reconstructed. |
| Products activity: Checkout, Resumed, Reactivated, Cancelled, Paused, Expired | ✔ | Movement rows × quantity. |
| Products activity: Upsells, Quantity increased/decreased | ✖ | Plan line edits (`next_order` override, `shopify_subscription_products_edited`) carry no quantity delta. Needs a `qty_delta` on an edit event. |
| Churn rate, subscribers lost, cancelled MRR | ✔ | Subscriber crossings of type Cancelled ÷ active subscribers at period start. |
| 0-day churn contribution | ✔ | Lost subscribers whose subscription was born the same calendar day. |
| Upcoming-order churn | ✖ | Cancellation clears `next_charge_at`, and the Timeline row does not record it. Needs `details.next_charge_at` on the cancel event. |

### 1.2 Acquisition

| Metric | Status | Source / what's needed |
|---|---|---|
| Acquired subscribers, 0-day churn, by selling plan × frequency, acquired MRR | ✔ | New subscriber crossings; `ActiveBook` joins. |
| Subscriber acquisition by product (qty / revenue) | ✔ | `installment_plans.external_product_id`/`shopify_product_id` → `products.title`; revenue from `payment_ledger` (`charge_context` `recurring`/`deposit`) by `plan_id`. |
| Non-subscribed customers, acquisition rate | ✖ | LETS does not ingest store orders/customers that never touched a plan. Needs an orders feed (Woo plugin report / Shopify `orders/create` webhook into an `orders` table). |
| Acquisition by order number #1–#10 | ✖ | Needs the customer's order history (same orders feed). |

### 1.3 Order funnel

| Metric | Status | Source / what's needed |
|---|---|---|
| Order-wise active subscriptions (by completed orders) | ✔ | `payment_ledger` succeeded rows per `plan_id` (count), or `installment_payments.sequence`. |
| Scheduled → attempted / success / failed & retrying / failed no retry left | ◐ | Scheduled = plans with `next_charge_at` in window; attempted/success/failed from `payment_ledger.status` (`pending`/`succeeded`/`failed`/`retry_scheduled`); retries left from `installment_payments.attempt_count` vs the shop's max. A due charge that was never attempted leaves no row. |
| Rescheduled / skipped | ◐ | Shopify: `shopify_subscription_rescheduled` (`details.skipped_delivery`). PayPlus: `plan_edited` with a date change; no explicit "skip" verb. |
| Paused / cancelled before the order | ◐ | Movements whose time precedes the plan's scheduled date — the scheduled date at cancel time is not stored (see Upcoming-order churn). |

### 1.4 Revenue

| Metric | Status | Source / what's needed |
|---|---|---|
| Subscription revenue, recurring vs checkout | ✔ | `payment_ledger` succeeded − refunded: `charge_context = recurring` (+`retry`) = recurring; `deposit` / the first charge of a plan = checkout. Shopify rail: `subscription_billing_attempts` (status `succeeded`) — amount from the contract. |
| Overall store revenue, non-subscription revenue, subscriber vs non-subscriber revenue, AOV per order type | ✖ / ◐ | Needs the orders feed. WooCommerce shops on the invoicing `all_orders` scope have `issued_documents` for every order (amounts in the provider payload) — a partial proxy for those shops only. |

### 1.5 Lifetime value

| Metric | Status | Source / what's needed |
|---|---|---|
| Subscriber LTV, segments by order number they subscribed at | ◐ | Subscriber revenue from `payment_ledger` per person key ✔; segmenting by the order number they joined at and non-subscriber LTV need the orders feed ✖. `loyalty_accounts.lifetime_spend` is available for club members only. |

## 2. Cohorts

| Metric | Status | Source |
|---|---|---|
| Subscriber / subscription retention by joining month | ✔ | Cohort = month of the New movement; active at month m via the same walk-back per cohort. |
| Orders placed, cumulative orders, revenue realised, LTV per cohort | ✔ | `payment_ledger` succeeded rows per plan/person, bucketed by months since cohort start. |
| Country / product / plan / frequency filters | ✔ except country | `Filters`. |

## 3. Payments

Ledger facts: `payment_ledger` has ONE row per `(shop_id, idempotency_key)` and a retry re-uses the key, so a
row's final status hides its earlier failures. Attempt history lives in the Timeline:
`charge_attempt_started`, `charge_failed`, `charge_retry_scheduled`, `charge_succeeded` (plan + payment ids),
and in `installment_payments.attempt_count` / `next_retry_at` / `failure_message`.

| Metric | Status | Source / what's needed |
|---|---|---|
| Attempted, success %, realised, lost, under recovery | ✔ | `payment_ledger.status` (`succeeded`/`failed`/`retry_scheduled`/`pending`), `amount`, `refunded_amount`. Existing `App\Domain\Dashboard\PaymentMetrics` already computes snapshot + monthly + upcoming. |
| First attempt success, recovery cycles, recovered via retry | ◐ | First attempt = first `charge_failed`/`charge_succeeded` per payment on the Timeline; recovered = a payment with ≥1 `charge_failed` then `charge_succeeded`. "Cycles" as Loop defines them do not exist — LETS retries on an interval up to a max; treat each retry as one cycle. |
| Recovered via card update | ✔ | `card_updated` Timeline event on the plan between the failure and the success (`CardUpdateService`). |
| Backup payment method | ✖ | LETS charges one saved token per plan; no backup-card attempt exists. |
| Payment source | ◐ | PayPlus token (PayPlus rail) vs Shopify Payments (contracts) — two sources only. |
| Failure reasons | ◐ | `payment_ledger.failure_message` (Hebrew text). PayPlus `failure_code` is always 1 (memory: decline codes undifferentiated), so reasons are grouped by message substring, with an "Other" bucket. |
| Country-wise distribution | ✖ | No customer country (see Definitions). |
| Upcoming payments list, risk | ✔ | `installment_plans.next_charge_at` + `status`; card status/expiry from `installment_payment_methods.exp_month/exp_year/status`; last error from the latest `payment_ledger.failure_message`; retries left from `installment_payments`. Keep the per-day drill-down into the Subscriptions list (`tableFilters[next_charge_at]`) the old Analytics page had. |

## 4. Products

| Metric | Status | Source |
|---|---|---|
| Active subscribers / qty by product | ✔ | Active book grouped by `external_product_id`/`shopify_product_id` → `products.title` (fallback `meta.item_title`); contracts by `lines[].product_id`. |
| Product-wise acquisition count/revenue, status split | ✔ | Plans by product × current status; revenue from the ledger by `plan_id`. |
| Swapped / removed | ◐ | `plan_switched` (account-offer switches, `meta.account_offer`), `shopify_subscription_products_edited`. |
| One-time upsells billed | ✔ | `upsell_offer_events` `charge_succeeded` `revenue_amount`; `next_order_extra` adds. |
| Product-wise churn (MRR / qty) | ✔ | Cancelled movements by the plan's product. |
| Cancellation reasons per product | ◐ | `details.reason` is free text typed by the merchant (or `customer_area` / `customer_portal` markers). Needs a reason list on the cancel flow. |
| Cancellation-flow effectiveness, save rate | ✖ | No cancellation flow with retention steps exists. |

## 5. Upsells

| Metric | Status | Source |
|---|---|---|
| Items added / sold, revenue, avg per order | ✔ | `upsell_offer_events` (`impression`, `accepted`, `declined`, `charge_succeeded`, `charge_failed`; `revenue_amount`), `App\Domain\Upsell\UpsellMetrics`. |
| Channel: Thank-you page | ✔ | `upsell_offer_events` (post-purchase / thank-you flows). |
| Channel: Account area | ✔ | `account_offers.accepted_count` + `account_offer_accepted` Timeline events (`meta.account_offer` on the created plan). |
| Channel: Admin / Campaign | ◐ / ✖ | Admin-added next-order extras: `next_order_extra` (actor `admin:*`). Campaign-attributed upsells are not tracked. |
| Upsell profiles | ✖ | No profile concept — the nearest is the flow (`upsell_flows`), usable as a stand-in. |

## 6. Cancellations

| Metric | Status | Source |
|---|---|---|
| Churn rate, subscribers lost, cancellation rate, subscriptions cancelled, MRR lost | ✔ | Movements (see 1.1). |
| Orders before cancellation | ✔ | Succeeded `payment_ledger` rows per cancelled plan. |
| Order-wise cancellations / churn | ✔ | Same, bucketed by order count. |
| Channel-wise cancellations | ◐ | `activity_events.actor` (`customer`, `customer_area`, `admin:<id>`, `platform_admin:<id>`, `system`, `webhook`) + `details.reason` markers (`customer_portal`, `replaced:<offer>`). `active → failed` = "Payment failed". |
| Reason-wise cancellations | ◐ | Free-text `details.reason`; group by exact text, "No reason" when empty. |
| Saves, retention tools, benefits page, winback | ✖ | No cancellation flow exists. Needs a cancellation-flow table (attempt, reason, tool, outcome, saved_mrr). |
| Risk analysis list | ✔ | Plans + ledger success % + successive failures (`installment_payments.attempt_count`) + `payment_failed_at` + card expiry (`installment_payment_methods`). Contracts: `card_exp` on the mirror. |

## 7. Forecast

| Metric | Status | Source |
|---|---|---|
| Scheduled orders + expected revenue 30/60/90 days | ✔ | Active plans' `next_charge_at` stepped by cadence × `installment_amount` (`no_charge` excluded) × the shop's trailing success rate from `payment_ledger`; contracts via `next_billing_date` + `amount`. |

## 8. Reports

| Report family | Status |
|---|---|
| Subscribers, subscriptions, activity logs, upcoming orders, transaction logs, product-wise sales, cancellation logs | ✔ — tables above + `activity_events`. |
| Checkout/processed order line items, bundles, inventory, prepaid credit, Streak | ✖ — no order lines, bundles-as-orders, inventory or prepaid ledger in LETS (thank-you bundles are `upsell_flow_offers.bundle_*`). |
| Exports history | ✖ — needs an `analytics_exports` table if exports become queued jobs; today Export streams a CSV directly. |

## Indexes worth adding before production scale

`activity_events (shop_id, kind, created_at)` — the movement log filters on `kind` within a shop and window;
today it uses `(shop_id, created_at)` + the `kind` index. `installment_plans (shop_id, plan_kind, status)`
for the active-book union.
