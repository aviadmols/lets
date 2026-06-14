# 40 — Post-Purchase Offers (Hub + Flow Builder)

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system` (4-tab resource + custom
> Livewire+Alpine Flow-Builder canvas). **Backend/model:** `laravel-backend` + `shopify-integration`.
> **Reuses the engine:** `Storefront\ReturnController` (thank-you render), `ShopifyDraftOrderService`
> (default order strategy). **Tokens:** [00-design-system.md](00-design-system.md).
> **i18n domain:** `upsell.*`, `states.*`, `actions.*`.
> **Pillar served:** post-purchase upsell. **Data model + flow:** [ARCHITECTURE.md §4](../../ARCHITECTURE.md).
> **Status:** data-pending (upsell tables owned by `laravel-backend`; thank-you widget render in
> [60-customer-portal.md](60-customer-portal.md)).

---

## Purpose
The merchant builds, prioritizes, and measures token-based thank-you-page upsells: one-click offers charged on
the **already-saved PayPlus token** after a completed purchase. This hub is where flows are authored
(Flow Builder), ordered by priority, and analyzed (funnel: impression → accepted → charge_succeeded → revenue).

## Entry points / nav
`Cross-Sell & Upsell` → **Post-Purchase Offers** (`nav.post_purchase_offers`). Plan-gated (Pro, `TODO-GATE`).

## Pillar reminder (ARCHITECTURE.md §4)
- **Upsell is a `charge_context`, not necessarily a plan.** Idempotency key:
  `upsell:{shop_id}:{flow_id}:{offer_id}:{parent_order_id}:{customer_id}` — a double-clicked accept charges **once**.
- Default Shopify order strategy = **separate linked child order via draft-order-completed-as-paid**
  (`ShopifyDraftOrderService`). Order-edit is future-only.
- Every accept must show a **consent disclosure** that the saved token is charged an additional amount.

---

## The 4 tabs (component §4.8) + Flow Builder

```
Post-Purchase Offers
[ Overview | Performance | Activity | Settings ]            ( + New flow )
```

---

## Tab 1 — Overview

### Layout
- Summary KPI row (component §4.1): Impressions · Conversion · Charge-success · Revenue (period-scoped).
- **Flows table** ordered by **priority**, with drag-to-reorder.

| Column | Field | Source |
|---|---|---|
| ↕ (drag) | reorder handle | client → writes `upsell_flows.priority` |
| Priority | integer order | `upsell_flows.priority` |
| Flow name | name | `upsell_flows.name` |
| Status | badge: `live` (teal/green) · `paused` (gray) · `draft` (gray) | `upsell_flows.status` |
| Trigger summary | "product X / collection Y / min ₪Z" | `upsell_flow_triggers` |
| Offers | count of offers in the flow | `upsell_flow_offers` |
| Impressions / Conv. | period metrics | `upsell_offer_events` aggregate |
| Row actions | Edit (→ Flow Builder) · Duplicate · Pause/Activate · Delete | — |

**Reorder = priority.** The thank-you render picks the **first** flow (by ascending priority) whose triggers
match; reordering changes which offer a customer sees first (ARCHITECTURE.md §4).

### Data fields
| Field | Source | Status |
|---|---|---|
| Flows (name, status, priority) | `upsell_flows` | TODO-DATA (laravel-backend) |
| Triggers | `upsell_flow_triggers` (product/collection/tag/min order value) | TODO-DATA |
| Offer count | `upsell_flow_offers` | TODO-DATA |
| KPI summary | `upsell_offer_events` aggregate | TODO-DATA |

### States
- **Loading** — KPI skeletons + skeleton table rows.
- **Empty (first-run)** — `upsell.overview.empty.first_run`: "Create your first post-purchase offer."
  + primary CTA → Flow Builder (new flow).
- **Empty (filtered)** — `states.empty.no_results`.
- **Error** — table error + retry; KPI cards load independently.

---

## Tab 2 — Performance

6 analytics cards + a revenue-over-time chart. The funnel is the heart of the upsell DoF.

### 6 cards (component §4.1)
| # | Card | Definition | Source |
|---|---|---|---|
| 1 | Impressions | count `impression` events | `upsell_offer_events` |
| 2 | Conversion rate | accepted / impression | derived |
| 3 | **Charge-success rate** | **charge_succeeded / accepted** (separates "said yes" from "card revoked") | derived |
| 4 | Revenue | Σ `charge_succeeded.revenue` | `upsell_offer_events` |
| 5 | AOV uplift | avg upsell revenue per accepting order (vs base AOV) | derived · TODO-DATA (base AOV) |
| 6 | Decline rate | declined / impression | derived |

> Card 3 is its own metric on purpose (ARCHITECTURE.md §4): a high conversion with a low charge-success means
> tokens are being revoked/declined at charge time — a dunning signal, not a copy problem.

### Revenue-over-time chart
Line/area chart of upsell revenue across the selected range, bucketed (day/week). Per-flow filter optional.
Chart tokens use `--rc-blue` for the series line on `--rc-card`.

### Data fields
All from `upsell_offer_events` (append-only: `impression | accepted | declined | charge_succeeded |
charge_failed`, revenue, masked context) — `TODO-DATA` until `laravel-backend` confirms the aggregate contract.
Base AOV for card 5 needs an order-value source (`TODO-DATA`).

### States
- **Loading** — 6 card skeletons + chart skeleton.
- **Empty** — `upsell.performance.empty`: "No offer activity in this range yet."
- **Error** — per-card error; chart error + retry.
- **Partial** — `charge_failed` rows present but webhook for a recent accept pending → card 3 shows the
  confirmed figure with a `states.partial.pending_webhook` footnote.

---

## Tab 3 — Activity

Append-only feed of `upsell_offer_events` (the upsell analogue of the Timeline) — every impression, accept,
decline, charge_succeeded, charge_failed, with masked context.

### Layout — table (component §4.3) + + Add filter
| Column | Field | Source |
|---|---|---|
| Time | `created_at` | `upsell_offer_events` |
| Event | type (impression/accepted/declined/charge_succeeded/charge_failed) badge | event type |
| Flow / Offer | flow + offer names | join |
| Customer | masked customer ref | event (masked) |
| Amount | offer/charged amount | event |
| Parent order | linked parent order | event |
| Result | charge tx / failure_code (no raw token, no `invoice_url`) | event (masked) |

**+ Add filter:** event type, flow, date range, failure_code.

### States
- **Loading** — skeleton rows.
- **Empty** — `upsell.activity.empty`: "No offer events yet."
- **Error** — table error + retry.

---

## Tab 4 — Settings (upsell-scoped)

Upsell-specific settings (the global versions also live in [50-settings.md](50-settings.md) → Merchant Billing).

| Setting | Control | Notes / source |
|---|---|---|
| Default order strategy | radio: **Child order (draft-completed-as-paid)** [default] · Order-edit (disabled/"where supported") | ARCHITECTURE.md §4.1 locked default |
| Default consent copy | textarea (i18n-key-backed default + per-shop override) | `upsell.consent.disclosure` |
| Partial-paid-order handling | radio: how to treat upsells on orders that aren't fully paid (e.g. installment parent still `payment_pending`) | **D2 — ASK** |
| Offer display cap | number: max offers shown per thank-you | per-shop setting |
| Global enable/disable | toggle | gates the thank-you widget |

### Partial-paid-order handling (the critical edge case)
An installment parent order is `payment_pending` until fully paid. The merchant must decide whether a
thank-you upsell may charge against the saved token while the parent is not yet fully paid:
- **Option A — Allow** (charge upsell immediately on the saved token; child order is independent).
- **Option B — Defer** (queue/skip upsell until the parent plan completes).
- **Option C — Block** (no upsell on installment-parent orders; recurring/one-time only).

**Recommended default: Option A** (the upsell child order is a separate, fully-paid order via
draft-completed-as-paid; it does not depend on the parent's installment state). **See D2 — ASK Aviad.**

### States
- **Loading** — form skeleton.
- **Error** — save error banner + retry.
- **Saved** — contextual save bar confirmation.

---

## Flow Builder (custom Livewire + Alpine canvas)

### Purpose
Author a single flow visually: **Trigger → Offer → (Accept / Decline branches) → next Offer**, with priority
ordering against other flows. Maps 1:1 to `upsell_flow_triggers`, `upsell_flow_offers`, `upsell_flow_branches`.

### Node types (component §4.15)
| Node | Maps to | Required config |
|---|---|---|
| **Trigger** | `upsell_flow_triggers` | match on purchased product / collection / tag / min order value (≥1 condition) |
| **Offer** | `upsell_flow_offers` | offer product/variant, discount, i18n headline key, i18n CTA key, charge amount |
| **Branch: Accept** | `upsell_flow_branches` | → next Offer node OR end |
| **Branch: Decline** | `upsell_flow_branches` | → next Offer node OR end |

### Canvas layout
```
┌──────────────────────────────────────────────────────────┐
│  Flow: "Summer add-on"   [ Priority 1 ]   [Draft] [ Save ] │
│                                                            │
│   ┌─Trigger─┐                                              │
│   │ buys A  │──▶ ┌─Offer 1──┐ ──accept──▶ ┌─Offer 2──┐    │
│   └─────────┘    │ +B 20%   │ ──decline─▶  │ +C 10%   │    │
│                  └──────────┘              └──────────┘    │
│  [ + Add offer ]  [ + Add trigger ]                        │
└──────────────────────────────────────────────────────────┘
```

### Validation rules (node = `invalid` red ring + inline reason)
- A flow must have **≥1 trigger** with ≥1 condition → else `upsell.builder.error.no_trigger`.
- A flow must have **≥1 offer** with a product + amount → else `upsell.builder.error.no_offer`.
- Every Offer must have an i18n **headline** + **CTA** key set → else `upsell.builder.error.missing_copy`.
- Accept/Decline branches must point to a valid next node or "end" → else `upsell.builder.error.dangling_branch`.
- A flow cannot be set **Live** while any node is invalid → "Save" allows draft; "Activate" blocked with a
  summary of invalid nodes.

### States
- **Empty canvas (new flow)** — `upsell.builder.empty`: a placeholder Trigger node + "Add your first offer"
  prompt; the canvas explains the trigger→offer→branch model.
- **Loading** — canvas skeleton (node placeholders).
- **Invalid** — invalid nodes ringed red with inline reasons; an "issues" summary chip counts them.
- **Error (save)** — save failed banner; the canvas keeps unsaved edits.
- **Saved** — toast + status pill updates (draft/live).

---

## Actions

| Action | Trigger | Confirmation/consent | Side-effect |
|---|---|---|---|
| New flow | Overview "+ New flow" | none | opens empty Flow Builder |
| Reorder flows | drag in Overview | none | writes `upsell_flows.priority` |
| Activate / Pause flow | row action / builder | `actions.upsell.activate.confirm` (activate validates first) | `upsell_flows.status` change |
| Delete flow | row action | `actions.upsell.delete.confirm` (destructive §4.7) | soft-delete flow |
| Edit consent copy | Settings | none | per-shop setting write |
| **Customer accept (thank-you)** | storefront widget (specced in [60](60-customer-portal.md)) | **consent disclosure required** (§4.16) | idempotent charge on saved token → child order → `charge_succeeded` event |

> The **customer-facing accept** + its consent disclosure + double-click "processing" lock are specced as the
> **thank-you widget** in [60-customer-portal.md](60-customer-portal.md#part-b--thank-you-upsell-widget-storefrontreturncontroller); this hub is the
> **merchant authoring/analytics** side.

---

## i18n keys (en + he) — selected

| Key | EN | HE |
|---|---|---|
| `upsell.title` | Post-Purchase Offers | הצעות לאחר רכישה |
| `upsell.tab.overview` | Overview | סקירה |
| `upsell.tab.performance` | Performance | ביצועים |
| `upsell.tab.activity` | Activity | פעילות |
| `upsell.tab.settings` | Settings | הגדרות |
| `upsell.new_flow` | New flow | תהליך חדש |
| `upsell.col.priority` | Priority | עדיפות |
| `upsell.col.flow` | Flow | תהליך |
| `upsell.col.trigger` | Trigger | טריגר |
| `upsell.col.offers` | Offers | הצעות |
| `upsell.flow_status.live` | Live | פעיל |
| `upsell.flow_status.paused` | Paused | מושהה |
| `upsell.flow_status.draft` | Draft | טיוטה |
| `upsell.kpi.impressions` | Impressions | חשיפות |
| `upsell.kpi.conversion` | Conversion rate | שיעור המרה |
| `upsell.kpi.charge_success` | Charge-success rate | שיעור חיוב מוצלח |
| `upsell.kpi.revenue` | Revenue | הכנסות |
| `upsell.kpi.aov_uplift` | AOV uplift | עלייה ב-AOV |
| `upsell.kpi.decline` | Decline rate | שיעור דחייה |
| `upsell.event.impression` | Impression | חשיפה |
| `upsell.event.accepted` | Accepted | אושר |
| `upsell.event.declined` | Declined | נדחה |
| `upsell.event.charge_succeeded` | Charge succeeded | חיוב הצליח |
| `upsell.event.charge_failed` | Charge failed | חיוב נכשל |
| `upsell.settings.order_strategy` | Default order strategy | אסטרטגיית הזמנה ברירת מחדל |
| `upsell.settings.order_strategy.child` | Linked child order (recommended) | הזמנת בת מקושרת (מומלץ) |
| `upsell.settings.order_strategy.edit` | Edit original order (where supported) | עריכת ההזמנה המקורית (היכן שנתמך) |
| `upsell.settings.partial_paid` | Upsells on partially-paid orders | הצעות על הזמנות ששולמו חלקית |
| `upsell.consent.disclosure` | By accepting, your saved card will be charged :amount now. | באישור, הכרטיס השמור שלך יחויב ב-:amount כעת. |
| `upsell.overview.empty.first_run` | Create your first post-purchase offer. | צרו את ההצעה הראשונה שלכם לאחר רכישה. |
| `upsell.performance.empty` | No offer activity in this range yet. | אין עדיין פעילות הצעות בטווח זה. |
| `upsell.activity.empty` | No offer events yet. | אין עדיין אירועי הצעות. |
| `upsell.builder.empty` | Add your first offer to start this flow. | הוסיפו הצעה ראשונה כדי להתחיל את התהליך. |
| `upsell.builder.error.no_trigger` | Add a trigger so this flow knows when to show. | הוסיפו טריגר כדי שהתהליך ידע מתי להופיע. |
| `upsell.builder.error.no_offer` | Add at least one offer. | הוסיפו לפחות הצעה אחת. |
| `upsell.builder.error.missing_copy` | This offer needs a headline and a button label. | להצעה זו דרושים כותרת וטקסט לכפתור. |
| `upsell.builder.error.dangling_branch` | This branch doesn't lead anywhere. | הענף הזה לא מוביל לשום מקום. |
| `actions.upsell.activate.confirm` | Activate this flow? It will start showing on the thank-you page. | להפעיל את התהליך? הוא יתחיל להופיע בעמוד התודה. |
| `actions.upsell.delete.confirm` | Delete this flow? This cannot be undone. | למחוק את התהליך? לא ניתן לבטל פעולה זו. |

> Headline/CTA copy on offers are **per-offer i18n keys the merchant sets** (`upsell.offer.{id}.headline` /
> `.cta`), distinct from UI chrome keys — the merchant authors customer-facing copy; the chrome is fixed.

---

## RTL notes
- Tabs + table columns mirror.
- **Flow Builder canvas:** the left-to-right flow (`Trigger ▶ Offer ▶ branches`) **mirrors to right-to-left** in
  HE — connectors flow RTL, node order reverses, accept/decline branch labels follow the text direction.
  This is the one surface where "RTL is not a pure flip" — connector arrowheads point start-ward.
- Revenue chart x-axis (time) flips to RTL; currency on the y-axis stays LTR.
- Consent disclosure renders RTL with `:amount` LTR-internal.

## Plan-gate behavior
- The entire Post-Purchase Offers hub is **Pro-tier** (`TODO-GATE`). On Starter/Growth the nav item shows the
  locked state ([01-navigation.md](01-navigation.md)); reaching it directly shows an upgrade page (owned with
  `saas-multitenancy-billing`). Flow count / offer count may have per-tier caps (`TODO-GATE`).

## Definition of Done
Upsell DoF: Flow Builder (trigger → offer → accept/decline) with empty-canvas + invalid-node states · thank-you
widget render + consent disclosure (in [60](60-customer-portal.md)) · idempotent one-click accept with
processing lock · Performance funnel (impressions/conversion/charge-success/revenue/AOV uplift) · Activity feed ·
default order-strategy setting · four states · EN/HE. Linked in
[99-i18n-conventions.md](99-i18n-conventions.md#post-purchase-upsell).

---

## Open decisions for Aviad (ASK)
- **D1 — Multi-offer chains in v1?** Accept→next-offer (upsell→cross-sell→downsell chains) is in the model
  (`upsell_flow_branches`). Recommend: support 2-step chains in v1 UI, deeper chains Phase 8. Confirm depth.
- **D2 — Partial-paid-order upsell handling (Settings).** Allow / Defer / Block (recommended **Allow**). Confirm.
- **D3 — Tier caps.** How many live flows / offers per tier? `TODO-GATE` for `saas-multitenancy-billing`.
