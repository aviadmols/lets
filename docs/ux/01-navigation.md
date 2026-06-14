# 01 — Navigation Shell (Fixed Left Sidebar)

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system` (Filament panel shell).
> **Tokens:** [00-design-system.md](00-design-system.md). **i18n:** `lang/en/nav.php` (already exists) + `lang/he/nav.php`.
> **Pillars served:** platform (chrome for all three).

---

## Purpose

The persistent app chrome: a fixed left sidebar (right in RTL) that groups every admin surface,
shows the active section in `--rc-blue`, exposes the shop switcher + language switch + plan badge,
and gates locked sections by tier. This is the IA backbone — every other screen mounts inside it.

## Entry points / nav

Always present after OAuth + plan selection. The sidebar is the only top-level navigation;
breadcrumbs inside detail pages are secondary.

---

## Layout (regions)

```
┌────────────────────────┐
│ [app mark]  PayPlus     │  ← brand row
│ ─────────────────────── │
│  Home                   │  ← top-level item
│  Analytics              │
│ ─────────────────────── │
│  Customers          ▸   │  ← group header (expand/collapse)
│    Customers            │  ← children, indented
│    Subscriptions        │
│    Orders               │
│    Order Errors    (3)  │  ← count badge when >0
│    Segments             │
│    Credits              │
│  Products           ▸   │
│    Products             │
│    Discounts            │
│  Cross-Sell & Upsell    │  ← single item (hub) OR group
│    Post-Purchase Offers │
│  Loyalty           🔒   │  ← plan-gated (lock + Upgrade)
│  Churn tools       🔒   │
│  SMS               🔒   │
│  Email                  │  (Mail Settings)
│  Storefront             │
│  Tools & apps           │
│ ─────────────────────── │
│  Settings               │
│ ─────────────────────── │
│  [store widget]         │  ← bottom: shop name + plan + switcher
│  [language EN|HE]       │
│  [Chat support]         │
└────────────────────────┘
```

### Region 1 — Brand row
- App mark + product short-name. Clicking returns to **Home**.

### Region 2 — Navigation tree
Two depth levels: **group headers** (collapsible) and **leaf items**. Leaf items route to a screen.
Group headers expand/collapse and persist their open/closed state per user (local, not server).

**Canonical nav model** (keys map to `lang/*/nav.php`; existing keys reused, new keys flagged):

| Group | Leaf item | nav key | Route / screen | Pillar | Plan gate |
|---|---|---|---|---|---|
| — | Home | `nav.home` ✓ | [10-home-dashboard.md](10-home-dashboard.md) | platform | none |
| — | Analytics | `nav.analytics` ✓ | Analytics (Home tabs deep-link; full screen Phase 8) | platform | Growth+ (TODO-GATE) |
| **Customers** | Customers | `nav.customers` ✓ | [20-customers.md](20-customers.md) (list) | platform | none |
| | Subscriptions | `nav.subscriptions` ✓ | [30-subscriptions.md](30-subscriptions.md) (list) | installments + recurring | none |
| | Orders | `nav.orders` ✓ | Orders (Processed/Upcoming/Gift tabs) | installments + recurring + upsell | none |
| | Order Errors | `nav.order_errors` ✓ | Order Errors cockpit | all | none |
| | Segments | `nav.segments` ✗ NEW | Segments builder | platform | Growth+ (TODO-GATE) |
| | Credits | `nav.credits` ✗ NEW | Store-credit / gift-card ledger (engine `InstallmentStoreCredit`) | platform | none |
| **Products** | Products | `nav.products` ✓ | Products (eligibility + rules) | all | none |
| | Discounts | `nav.discounts` ✓ | Discounts | installments + recurring | none |
| — | Cross-Sell & Upsell | `nav.cross_sell_upsell` ✓ | Upsell hub → Post-Purchase Offers | upsell | Pro (TODO-GATE) |
| | Post-Purchase Offers | `nav.post_purchase_offers` ✓ | [40-post-purchase-offers.md](40-post-purchase-offers.md) | upsell | Pro (TODO-GATE) |
| — | Loyalty | `nav.loyalty` ✗ NEW | placeholder (future) | platform | gated/coming-soon |
| — | Churn tools | `nav.churn_tools` ✗ NEW | placeholder (future) | platform | gated/coming-soon |
| — | SMS | `nav.sms` ✗ NEW | placeholder (future) | platform | gated/coming-soon |
| — | Email | `nav.email` ✗ NEW | Mail Settings ([50-settings.md](50-settings.md) → Mail) | platform | none |
| — | Storefront | `nav.storefront` ✓ | Storefront / portal config + theme extension | all | none |
| — | Tools & apps | `nav.tools_apps` ✗ NEW | integrations placeholder (future) | platform | gated/coming-soon |
| — | Settings | `nav.settings` ✓ | [50-settings.md](50-settings.md) | platform | none |

✓ = key exists in `lang/en/nav.php` today · ✗ NEW = key to be added (see i18n keys below).

> **Note on "Cross-Sell & Upsell" vs "Post-Purchase Offers":** the brief lists both. Treat
> **Cross-Sell & Upsell** as the pillar hub (summary KPIs + entry) and **Post-Purchase Offers** as the
> working surface (4 tabs + Flow Builder). They may be a group+leaf or a single item that opens the hub —
> see **D1** below.

### Region 3 — Store widget (bottom)
- **Shop name + shop domain** (the current tenant; Filament native tenancy → `Shop`).
- **Plan badge** (Starter / Growth / Pro) + **"Upgrade"** affordance when a higher tier unlocks more.
- **Shop switcher** — for users with multiple shops, a popover list to switch tenant
  (`saas-multitenancy-billing` owns who can see which shops).
- **Language switch** — `EN | HE` toggle (wired via `bezhansalleh/filament-language-switch`).
  Flips the whole shell to RTL when HE is selected.
- **Chat support** — opens the support contact (merchant's support channel / app support).

---

## Data fields (source: which backend contract)

| Field | Source | Notes |
|---|---|---|
| Current shop name + domain | `Shop` model (tenant context) | from `laravel-backend` tenancy |
| Available shops for switcher | `saas-multitenancy-billing` (user↔shop membership) | TODO-DATA: membership model |
| Current plan tier | `Shop.plan` / billing | from `saas-multitenancy-billing` |
| Order-errors count badge | aggregate: unresolved order errors for shop | TODO-DATA: count query/contract |
| Gated-section map | plan-gate config per tier | from `saas-multitenancy-billing` |
| Active section | router | client-side |

---

## States

- **Loading** — sidebar renders the static nav immediately (no skeleton needed); the **count badge**
  (Order Errors) and **plan badge** load async → show a tiny skeleton dot until resolved.
- **Empty** — n/a (nav is always populated). A brand-new shop still sees every item; gated items show locked.
- **Error** — if the order-errors count fails to load, the badge is hidden (never block nav on it).
  If the shop context fails to resolve, that is a platform-level error screen owned by `saas-multitenancy-billing`,
  not the nav.
- **Partial** — gated items render with a **lock icon + muted label**; clicking opens the upgrade
  state (`states.gate.locked` → CTA to Plan & Billing). Implemented with the locked-state pattern,
  owned jointly with `saas-multitenancy-billing`.

---

## Actions

| Action | Trigger | Confirmation/consent | Side-effect |
|---|---|---|---|
| Navigate | click leaf item | none | route change |
| Expand/collapse group | click group header | none | persists open state (local) |
| Switch shop | store widget → switcher | none (read-only switch) | re-scopes tenant context |
| Switch language | EN/HE toggle | none | sets locale + `dir`; persists per user |
| Upgrade | plan badge / locked item | none here (opens Plan & Billing) | deep-links to Settings → Plan & Billing |

---

## i18n keys (en + he)

Existing `lang/en/nav.php` keys are reused as-is. **New keys to add** (EN authoritative, HE mirror):

| Key | EN | HE |
|---|---|---|
| `nav.segments` | Segments | פלחים |
| `nav.credits` | Credits | זיכויים |
| `nav.loyalty` | Loyalty | מועדון לקוחות |
| `nav.churn_tools` | Churn tools | כלי שימור |
| `nav.sms` | SMS | SMS |
| `nav.email` | Email | אימייל |
| `nav.tools_apps` | Tools & apps | כלים ואפליקציות |
| `nav.group.customers` | Customers | לקוחות |
| `nav.group.products` | Products | מוצרים |
| `nav.store_widget.upgrade` | Upgrade | שדרוג |
| `nav.store_widget.switch_shop` | Switch store | החלפת חנות |
| `nav.support.chat` | Chat support | צ'אט תמיכה |
| `states.gate.locked` | Available on a higher plan. Upgrade to unlock. | זמין בתוכנית גבוהה יותר. שדרגו כדי לפתוח. |

> Group-header labels reuse the leaf keys where natural (`nav.customers`, `nav.products`) or use the
> `nav.group.*` keys above to disambiguate header from leaf. `admin-design-system` picks one and stays consistent.

---

## RTL notes

- The **entire sidebar moves to the right edge** in HE; content area shifts left.
- Group-expand chevrons flip direction (point left when collapsed in HE).
- The active-item left accent rail becomes a **right** accent rail.
- Count badge position mirrors to the start-side.
- Language toggle order stays `EN | HE` visually but the active state still reads correctly RTL.

---

## Plan-gate behavior

- Gated leaf items (Segments, Analytics full screen, Cross-Sell & Upsell / Post-Purchase Offers per tier,
  Loyalty/Churn/SMS/Tools placeholders) render with a **lock glyph** and muted label.
- Clicking a locked item routes to **Settings → Plan & Billing** with the relevant feature highlighted,
  showing `states.gate.locked` copy. **Exact tier→feature gate map is owned by `saas-multitenancy-billing`** —
  marked `TODO-GATE` above until confirmed.

## Definition of Done

Platform DoF ([99-i18n-conventions.md](99-i18n-conventions.md#platform)): nav shell present with active state,
shop switcher (tenancy), language switch (RTL flip), plan badge + upgrade affordance, gated locked states,
full EN+HE keys.

---

## Open decisions for Aviad

- **D1 — Cross-Sell & Upsell as group vs single item?** The brief lists "Cross-Sell & Upsell" and the engine
  surface is "Post-Purchase Offers". Recommend: one nav item **Cross-Sell & Upsell** opening the hub, with
  **Post-Purchase Offers** as its sole child for now. Confirm.
- **D2 — Which placeholder sections ship in v1 vs hidden?** Loyalty / Churn tools / SMS / Tools & apps are in
  the brief's IA but not in the three pillars. Recommend showing them as **"Coming soon" locked items** (sets
  roadmap expectation) rather than hiding. Confirm hide-vs-show.
