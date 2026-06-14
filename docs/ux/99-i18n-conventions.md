# 99 — i18n Conventions + Definition of Done

> **Owner:** `product-ux-architect` (key design + EN/HE copy). **Wired by:** `admin-design-system` (calls `__()`).
> English is authoritative; Hebrew mirrors every key; everything is RTL-aware.
> **Aligns with the existing files:** `lang/en/nav.php`, `lang/en/common.php`, `lang/en/billing.php`
> (and their `lang/he/` mirrors) already exist — this doc extends, never contradicts, them.

---

## 1. The rules (from CLAUDE.md §10 + the agent brief §6)

1. **English default, every string via `__()`.** No bare strings in the UI. If a screen spec writes copy, it
   also assigns a key.
2. **`lang/he/*` mirrors `lang/en/*` key-for-key.** Same files, same nested shape. Provide HE copy, or an
   explicit `// HE-TODO: needs translator` marker — **never leave a key HE-empty silently**.
3. **RTL is a flip, not a redesign.** Logical CSS + `dir` switch (handled by `admin-design-system`). Per-screen
   RTL notes flag anything that is *not* a pure flip (Flow Builder canvas, progress bars, currency placement).
4. **Named placeholders only** (`:amount`, `:date`, `:count`, `:reason`, `:frequency`) — never positional.
   Document each placeholder where the key is introduced.
5. **Pluralization via `trans_choice`** for count-bearing strings: `{0} No orders|{1} :count order|[2,*] :count orders`.
6. **Currency + dates are locale-formatted**, never hardcoded. ILS default; `₪` placement per locale.

---

## 2. File / key naming scheme

One PHP file per **domain** under `lang/en/` and `lang/he/`. Keys are nested arrays. The existing files set the
pattern: `nav.php` uses **flat** keys; `billing.php` uses **nested** groups (`status.*`, `plan_kind.*`,
`charge_context.*`); `common.php` is flat shared chrome. New domains follow the same shape.

| Domain file | Scope | Shape | Example keys |
|---|---|---|---|
| `nav.php` ✓ | sidebar nav | flat | `nav.home`, `nav.subscriptions`, `nav.order_errors` |
| `common.php` ✓ | shared chrome | flat | `common.save`, `common.add_filter`, `common.test_connection` |
| `billing.php` ✓ | billing/plan domain | nested | `billing.status.active`, `billing.plan_kind.installments`, `billing.charge_context.deposit` |
| `dashboard.php` ✗ NEW | Home dashboard | nested | `dashboard.kpi.processed_revenue`, `dashboard.tab.revenue` |
| `customers.php` ✗ NEW | customers list + detail | nested | `customers.list.col.email`, `customers.detail.panel.payment_methods` |
| `subscriptions.php` ✗ NEW | subscriptions list + detail | nested | `subscriptions.filter.kind.installments`, `subscriptions.detail.fulfillment_locked` |
| `orders.php` ✗ NEW | orders + order errors | nested | `orders.tab.processed`, `order_errors.status.unresolved` |
| `segments.php` ✗ NEW | segments builder | nested | `segments.rule.add` |
| `products.php` ✗ NEW | products + discounts | nested | `products.eligibility.subscription` |
| `discounts.php` ✗ NEW | discounts | nested | `discounts.type.first_order_pct` |
| `upsell.php` ✗ NEW | post-purchase offers + thank-you | nested | `upsell.kpi.charge_success`, `upsell.consent.disclosure` |
| `portal.php` ✗ NEW | customer portal | nested | `portal.pay_next`, `portal.link_expired` |
| `settings.php` ✗ NEW | settings | nested | `settings.payplus.test`, `settings.billing.min_deposit` |
| `actions.php` ✗ NEW | confirmation/action copy | nested | `actions.cancel.confirm`, `actions.refund.scope.all` |
| `states.php` ✗ NEW | shared empty/loading/error/partial/gate | nested | `states.empty.no_results`, `states.partial.pending_webhook`, `states.gate.locked` |
| `validation.php` ✗ NEW | form validation copy | nested | `validation.min_deposit_positive` |
| `emails.php` ✗ NEW | **email UI labels only** (timeline labels, template names) | nested | `emails.timeline.welcome_sent` |

> **Key pattern:** `<domain>.<screen-or-component>.<element>[.<state>]`. Status labels live **once** in
> `billing.status.*` / `billing.ledger_status.*` and are reused everywhere — never re-declare a status string in a
> screen domain.

### Status keys — canonical, single home (reuse, don't duplicate)
Already in `billing.php` (`status.*`). The badge map in [00-design-system.md §4.2](00-design-system.md) points at
these. Ledger statuses need a sibling group (new):

| Key | EN | HE |
|---|---|---|
| `billing.ledger_status.pending` | Pending | בהמתנה |
| `billing.ledger_status.succeeded` | Succeeded | הצליח |
| `billing.ledger_status.failed` | Failed | נכשל |
| `billing.ledger_status.retry_scheduled` | Retry scheduled | ניסיון חוזר מתוזמן |
| `billing.ledger_status.refunded` | Refunded | זוכה |
| `billing.ledger_status.cancelled` | Cancelled | בוטל |

### Shared actor + filter-operator keys (used across lists/Timeline)
| Key | EN | HE |
|---|---|---|
| `common.actor.system` | System | מערכת |
| `common.actor.merchant` | Merchant | סוחר |
| `common.actor.customer` | Customer | לקוח |
| `common.actor.webhook` | Webhook | Webhook |
| `common.filter_op.is` | is | הוא |
| `common.filter_op.is_not` | is not | אינו |
| `common.filter_op.greater_than` | greater than | גדול מ |
| `common.filter_op.between` | between | בין |
| `common.filter_op.contains` | contains | מכיל |

### Shared state keys (one home for empty/loading/error/partial/gate)
| Key | EN | HE |
|---|---|---|
| `states.empty.no_results` | No results match your filters. | אין תוצאות התואמות את הסינון. |
| `states.empty.clear_filters` | Clear filters | ניקוי סינון |
| `states.loading` | Loading… | טוען… |
| `states.error.generic` | Something went wrong. | משהו השתבש. |
| `states.error.retry` | Retry | נסו שוב |
| `states.error.action_failed` | The action couldn't be completed. No charge was made. | הפעולה לא הושלמה. לא בוצע חיוב. |
| `states.partial.pending_webhook` | Awaiting confirmation from PayPlus… | ממתין לאישור מ-PayPlus… |
| `states.kpi.no_data` | No data for this range | אין נתונים לטווח זה |
| `states.kpi.error` | Couldn't load | טעינה נכשלה |
| `states.gate.locked` | Available on a higher plan. Upgrade to unlock. | זמין בתוכנית גבוהה יותר. שדרגו כדי לפתוח. |

---

## 3. The TWO string systems — never conflate {#two-string-systems}

| System | Mechanism | Where | Source of truth |
|---|---|---|---|
| **UI strings** | Laravel `__('domain.key')`, named `:placeholders` | all admin + portal chrome | `lang/en/*` + `lang/he/*` (this doc) |
| **Email-template tokens** | `{{first_name}}`, `{{amount}}` substituted via **`strtr()`** (NEVER `Blade::render()`) | merchant-edited email bodies | `Support/DefaultEmailTemplates` + `Mail/TemplateRenderer` (engine) |

- Email **bodies** are merchant HTML with `{{token}}` placeholders → `strtr()` only (RCE prevention, CLAUDE.md
  §10) → previewed in an isolated `iframe srcdoc` + `htmlspecialchars`. **Inline CSS in emails is the allowed
  exception** to the no-inline-CSS rule.
- Email **UI labels** (timeline kind labels, template names, the Mail Settings editor chrome) are normal `__()`
  keys in `emails.php` / `settings.php`.
- A screen spec must keep these straight: `upsell.consent.disclosure` is a `__()` key; `{{amount}}` in a welcome
  email is a `strtr()` token. They look similar; they are different systems.

---

## 4. RTL checklist (the non-pure-flips, collected from every screen)

| Surface | Non-flip concern |
|---|---|
| All screens | currency `₪` placement + number formatting via locale formatter (not glued strings) |
| KPI delta | ▲/▼ are vertical (don't flip); `+/-%` sign follows RTL number format; **churn delta color inverts** |
| Installments progress bar | fills from the start-side (right in HE) |
| Masked card / tx refs / URLs / credentials | stay **LTR** inside RTL containers |
| Timeline | timestamps + actor chips mirror; "Preview" affordance moves start-side; email preview iframe content follows its own content direction |
| **Flow Builder canvas** | the trigger→offer→branch flow mirrors to right-to-left; connector arrowheads point start-ward — the one true "not a flip" surface |
| Charts | x-axis (time) flips RTL; y-axis currency stays LTR |
| Labels (`--rc-type-label`) | uppercase transform is a no-op in HE; letter-spacing removed |
| Portal | **RTL-first** (LTR is the flip), opposite emphasis to the admin |

---

## 5. Per-pillar Definition of Done (acceptance checklists)

A pillar's UX is "done" only when every box below is a **written, state-complete** spec (data sources + four
states + EN/HE keys + RTL notes) that `admin-design-system` can build. Maps to plan §11.1 + the agent brief §9.
Each links to the screen spec that satisfies it.

### Installments {#installments}
- [ ] Configure deposit + schedule (Products / Settings → [50](50-settings.md))
- [ ] Subscription detail shows deposit + each installment + paid/remaining + next charge ([30](30-subscriptions.md))
- [ ] `awaiting_first_payment` / `active` / `completed` / `failed` status badges ([00 §4.2](00-design-system.md))
- [ ] Fulfillment-locked indicator until fully paid ([30](30-subscriptions.md))
- [ ] "Final payment released the order" state ([30](30-subscriptions.md))
- [ ] Failed payment → Order Errors + retry + dunning copy (Order Errors screen; tracked, see INDEX)
- [ ] Per-plan ledger + Timeline visible ([30](30-subscriptions.md))
- [ ] All four states per screen · EN+HE keys

### Recurring {#recurring}
- [ ] Create subscription product / rule (Products / Settings)
- [ ] Customer-start surface + saved-method (masked) display ([20](20-customers.md) panel, [60](60-customer-portal.md))
- [ ] Billing cycle → fulfillable order shown in Orders/Processed (Orders screen; tracked)
- [ ] Pause/cancel (immediate vs end-of-period) with confirmation copy ([30](30-subscriptions.md) D1, [60](60-customer-portal.md))
- [ ] Failed cycle → Order Errors + retry (Order Errors)
- [ ] Per-plan ledger + Timeline ([30](30-subscriptions.md))
- [ ] All four states · EN+HE keys

### Post-purchase upsell {#post-purchase-upsell}
- [ ] Flow Builder (trigger → offer → accept/decline) with empty-canvas + invalid-node states ([40](40-post-purchase-offers.md))
- [ ] Thank-you widget render with headline/CTA i18n + **consent disclosure** ([60](60-customer-portal.md))
- [ ] One-click accept idempotent; double-click cannot double-charge — UX processing lock ([60](60-customer-portal.md))
- [ ] Performance funnel (impressions / conversion / **charge-success** / revenue / AOV uplift) ([40](40-post-purchase-offers.md))
- [ ] Activity feed (`upsell_offer_events`) ([40](40-post-purchase-offers.md))
- [ ] Default order-strategy setting (child order via draft-completed-as-paid) ([40](40-post-purchase-offers.md), [50](50-settings.md))
- [ ] All four states · EN+HE keys

### Platform {#platform}
- [ ] Home KPI dashboard with loading skeletons + first-run empty ([10](10-home-dashboard.md))
- [ ] Customer Details: KPIs + per-address subscription cards + upcoming/recent orders + Timeline + right sidebar ([20](20-customers.md))
- [ ] Order Errors cockpit (failed-charge queue, retry / resolve / open plan) — *screen tracked in INDEX, spec pending*
- [ ] Segments builder — *tracked, gated*
- [ ] Settings: PayPlus Connection + "Test connection", Merchant Billing, Mail, Plan & Billing gate states ([50](50-settings.md))
- [ ] Customer Portal via signed magic link, both `plan_kind`s, history, allowed pause/cancel, expired-link state ([60](60-customer-portal.md))
- [ ] Per-plan AND per-customer Timeline showing every actor/action, emails previewable inline, **no `invoice_url`** ([20](20-customers.md), [30](30-subscriptions.md))
- [ ] Observability dashboard surface (charge success/fail rate, queue depth, scheduler heartbeat — metrics from `railway-infra`) — *tracked, Phase 8*
- [ ] Plan-gate locked states across gated surfaces ([01](01-navigation.md) + each screen)
- [ ] Full EN+HE catalog + RTL notes (this doc)
- [ ] Nav shell: active state, shop switcher (tenancy), language switch (RTL flip), plan badge + upgrade ([01](01-navigation.md))

---

## 6. Spec status board (handoff to admin-design-system + laravel-backend)

| Screen | Spec file | Status | Blocking TODO-DATA |
|---|---|---|---|
| Design system + components | [00-design-system.md](00-design-system.md) | **ready** | none (tokens are the contract) |
| Navigation shell | [01-navigation.md](01-navigation.md) | data-pending | shop-membership, order-errors count, gate map |
| Home dashboard | [10-home-dashboard.md](10-home-dashboard.md) | data-pending | KPI aggregate contract + MRR rule |
| Customers (list + detail) | [20-customers.md](20-customers.md) | data-pending | active-subs counter, payment-status derivation, address grouping, comms prefs |
| Subscriptions (both kinds) | [30-subscriptions.md](30-subscriptions.md) | data-pending | recurring plan fields, per-plan ledger contract |
| Post-Purchase Offers + Flow Builder | [40-post-purchase-offers.md](40-post-purchase-offers.md) | data-pending | `upsell_*` tables + events aggregate, base AOV |
| Settings | [50-settings.md](50-settings.md) | data-pending | `MerchantBillingSettings` shape, connection-status field |
| Customer Portal + thank-you | [60-customer-portal.md](60-customer-portal.md) | data-pending | payment-method-update CAPABILITY flag, receipt link |
| i18n + DoF | [99-i18n-conventions.md](99-i18n-conventions.md) | **ready** | none |

> **Tracked-but-not-yet-authored (next pass):** Orders (Processed/Upcoming/Gift), Order Errors cockpit, Segments
> builder, Products, Discounts, Credits, Cross-Sell & Upsell hub summary, Observability dashboard. These appear in
> the DoF above and the nav; their full page specs are the next deliverable.

---

## 7. Consolidated open decisions for Aviad (rolled up from every screen)

| # | Decision | Default proposed | Screen |
|---|---|---|---|
| Q1 | Recurring **cancel** default: immediate vs end-of-period | end-of-billing-period | [30](30-subscriptions.md) D1, [60](60-customer-portal.md) D2 |
| Q2 | Can merchants **pause/cancel installments** (not just recurring)? | cancel yes (with refund scope); pause no | [30](30-subscriptions.md) D2 |
| Q3 | Portal **payment-method update** supported by the PayPlus flow? (CAPABILITY) | hidden unless flag true | [60](60-customer-portal.md) D1 |
| Q4 | Which **4 Home KPIs**: brief's subscriber cards vs MRR-centric? | brief's 4 cards (MRR etc. in the table) | [10](10-home-dashboard.md) D1 |
| Q5 | **Partial-paid-order** upsell handling | Allow (independent child order) | [40](40-post-purchase-offers.md) D2 |
| Q6 | "**Streak**" definition on Customer Details | consecutive active-subscription months | [20](20-customers.md) D1 |
| Q7 | Reveal PayPlus **secrets** after save? | never re-display; "Replace" only | [50](50-settings.md) D1 |
| Q8 | **DocumentPolicy** UI granularity | per-context grid with defaults | [50](50-settings.md) D2 |
| Q9 | Nav: **placeholder sections** (Loyalty/Churn/SMS/Tools) hide vs show-as-coming-soon | show as locked "coming soon" | [01](01-navigation.md) D2 |
| Q10 | **Gift orders** in scope for Orders → Gift tab v1? | out of scope v1 (tab hidden) | Orders (tracked) |
