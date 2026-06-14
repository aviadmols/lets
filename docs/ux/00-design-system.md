# 00 — Design System (Single Source of Truth)

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system`.
> This file is the canonical token table + component inventory for the Recharge-style
> PayPlus admin. Nothing in the UI may use a raw hex, radius, shadow, or spacing value
> that is not declared here.
>
> **Cross-refs:** [ARCHITECTURE.md](../../ARCHITECTURE.md) (state machines, charge contexts),
> [99-i18n-conventions.md](99-i18n-conventions.md) (string keys + Definition of Done).

---

## 0. The three hard rules (non-negotiable, from CLAUDE.md §10)

1. **Tokens → CSS custom properties → component classes.** The flow is one-way:
   this table defines a token name + value + intent → `admin-design-system` binds it
   to a `--rc-*` CSS custom property in `resources/css/filament/admin/theme.css` →
   components reference the variable through a class. **A screen spec never names a hex;
   it names a token.**
2. **No inline CSS in admin/storefront UI.** No `style="…"`, no Tailwind arbitrary
   token values (`bg-[#3B5BDB]`). **One exception:** email-template HTML (`resources/views/emails/*`
   and merchant-edited bodies) — email clients strip `<style>`, so inline CSS there is
   required and expected.
3. **CONST-at-top.** Every implemented file opens with a constants/token-reference block
   (this is `admin-design-system`'s concern; called out so specs are written to support it —
   colors, route names, status→badge maps, and KPI keys are all *named*, never inlined).

If a screen needs a color/spacing/component that is not in this file, **add it here first**,
then use it. A color in a screen spec that is absent from this table is a bug in the spec.

---

## 1. Color tokens

### 1.1 Brand + surface

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Primary blue | `--rc-blue` | `#3B5BDB` | Primary CTAs, active nav item, links, focus ring, primary KPI accent, active tab underline. |
| Primary blue (hover/active) | `--rc-blue-600` | `#2F4BC4` | CTA hover + pressed; active-nav left rail. |
| Primary blue (tint) | `--rc-blue-bg` | `#EEF2FE` | Active-nav background wash, selected table row, info chips. |
| Canvas / app background | `--rc-bg` | `#F8F8F5` | The off-white behind all cards and panels. |
| Card surface | `--rc-card` | `#FFFFFF` | Every card, table, panel, sidebar, modal. (alias `--rc-surface`) |
| Border / hairline | `--rc-border` | `#E6E6E1` | Card borders, table row dividers, input borders, accordion separators. |
| Border (strong) | `--rc-border-strong` | `#D4D4CE` | Input focus border (non-error), table header underline. |

### 1.2 Text

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Text primary | `--rc-ink` | `#1A1A1A` | Headings, KPI values, primary table cells. |
| Text secondary | `--rc-ink-muted` | `#6B7280` | Labels, captions, helper text, secondary table cells. |
| Text on-primary | `--rc-ink-on-primary` | `#FFFFFF` | Text on a blue/green/red filled button. |
| Link | `--rc-link` | `#3B5BDB` | Inline links (= `--rc-blue`); underline on hover. |

### 1.3 Status / semantic (drive the badge map in §4.2)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Success / active green | `--rc-green` | `#1E9E6A` | "Active"/"Completed"/"Succeeded" badge text, positive deltas, healthy dots. |
| Active badge bg | `--rc-green-bg` | `#E6F4EE` | Pill background for active/success. |
| Neutral / inactive gray | `--rc-gray` | `#6B7280` | "Paused"/"Cancelled"/"Pending"/"Refunded" badge text, inactive dots. |
| Inactive badge bg | `--rc-gray-bg` | `#F0F0EE` | Pill background for neutral/inactive. |
| Info / NEW teal | `--rc-teal` | `#0FA3A3` | "NEW" badge, "Live" upsell flows, beta/info chips. |
| NEW badge bg | `--rc-teal-bg` | `#E2F5F5` | Pill background for NEW/info. |
| Danger / error red | `--rc-red` | `#D64545` | Failed charges, order-error badges, destructive CTAs, negative deltas, error dots. |
| Error badge bg | `--rc-red-bg` | `#FBE9E9` | Pill background for failed/error. |
| Warning amber | `--rc-amber` | `#C77700` | Retry-scheduled, awaiting-payment, dunning warnings, grace-period notices. |
| Warning badge bg | `--rc-amber-bg` | `#FBF1E0` | Pill background for warning. |

> **Payment-status dot** (Customers list): green dot = healthy / last charge succeeded;
> amber dot = retry-scheduled or awaiting; red dot = failed/error; gray dot = no active plan / paused.
> Uses `--rc-green` / `--rc-amber` / `--rc-red` / `--rc-gray` as the dot fill.

---

## 2. Typography scale

Family token: `--rc-font` = Inter / system stack (EN) with **Heebo** as the Hebrew face;
both RTL-safe. Headings **bold**, body **medium-weight gray-ink**, labels/tags **UPPERCASE small**.

| Role | CSS var | Size / weight | Transform | Color token | Used for |
|---|---|---|---|---|---|
| Page title | `--rc-type-title` | `22px / 700` | none | `--rc-ink` | Top-of-page H1. |
| KPI value | `--rc-type-kpi` | `28px / 600` | none | `--rc-ink` | The big number in a KPI card. |
| Section / card heading | `--rc-type-h` | `18px / 600` | none | `--rc-ink` | Card titles, accordion headers. |
| Subheading | `--rc-type-subh` | `15px / 600` | none | `--rc-ink` | Sub-card / sidebar-panel titles. |
| Body | `--rc-type-body` | `14px / 450` | none | `--rc-ink-muted` | Default text, table cells. |
| Body strong | `--rc-type-body-strong` | `14px / 600` | none | `--rc-ink` | Emphasized cell (customer name, amount). |
| Label / tag | `--rc-type-label` | `12px / 600` | **UPPERCASE**, `+0.04em` tracking | `--rc-ink-muted` | Field labels, table column headers, badge text, KPI captions. |
| Caption / helper | `--rc-type-caption` | `12px / 450` | none | `--rc-ink-muted` | Helper text under fields, timestamps. |

**RTL note:** Hebrew never uppercases (no case in Hebrew script); the `--rc-type-label`
transform is a no-op in HE but the tracking is removed (Hebrew letter-spacing looks broken).
`admin-design-system` handles this via a `[dir="rtl"]` override — it is not a per-screen concern.

---

## 3. Spacing, radius, shadow, layout tokens

### 3.1 Spacing scale (never use a raw px gap/padding)

| Token | CSS var | Value |
|---|---|---|
| space-1 | `--rc-space-1` | `4px` |
| space-2 | `--rc-space-2` | `8px` |
| space-3 | `--rc-space-3` | `12px` |
| space-4 | `--rc-space-4` | `16px` |
| space-5 | `--rc-space-5` | `24px` |
| space-6 | `--rc-space-6` | `32px` |
| space-7 | `--rc-space-7` | `48px` |
| space-8 | `--rc-space-8` | `64px` |

### 3.2 Radius

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Card radius | `--rc-radius-card` | `12px` | KPI cards, panels, modals. |
| Control radius | `--rc-radius-control` | `8px` | Buttons, inputs, filter chips, select. |
| Pill radius | `--rc-radius-pill` | `999px` | Status badges, toggles, segment chips. |

### 3.3 Shadow / elevation

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Card resting | `--rc-shadow-card` | `0 1px 2px rgba(16,24,40,.06)` | Default card elevation. |
| Card hover | `--rc-shadow-hover` | `0 4px 12px rgba(16,24,40,.10)` | Interactive card / row hover. |
| Popover / dropdown | `--rc-shadow-pop` | `0 8px 24px rgba(16,24,40,.12)` | Filter dropdowns, menus, the language switch. |
| Focus ring | `--rc-ring` | `0 0 0 3px rgba(59,91,219,.30)` | Keyboard focus on any control (uses `--rc-blue` at 30%). |

### 3.4 Layout

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Sidebar width | `--rc-sidebar-w` | `248px` | Fixed left nav (right in RTL). |
| Content max width | `--rc-content-max` | `1200px` | Centered content column. |
| Detail main/side split | `--rc-split` | `70% / 30%` | Customer & Subscription detail two-column layout. |
| Page gutter | `--rc-gutter` | `--rc-space-6` (`32px`) | Padding between sidebar and content / page edges. |

---

## 4. Reusable component inventory

Each entry = **anatomy (slots)** + **variants** + **states** + **tokens consumed** + **i18n keys**.
No code, no hex — only token names. If a screen needs a component not listed here, add it here first.

### 4.1 KPI card

- **Anatomy:** caption label (top, `--rc-type-label`) · value (`--rc-type-kpi`) · optional delta
  badge (▲/▼ + `%`) · optional sub-line (period or comparison) · optional sparkline slot.
- **Variants:** `value-only` · `value+delta` · `value+sparkline` · `value+delta+sub`.
- **States:**
  - **loading** — skeleton bar in place of value (shimmer); caption still shown.
  - **empty / no-data** — value renders `—`; delta hidden; sub-line shows `states.kpi.no_data`.
  - **error** — value renders `!` in `--rc-ink-muted`; small `states.kpi.error` caption + a retry icon-button.
  - **delta sign** — positive uses `--rc-green` with ▲; negative uses `--rc-red` with ▼; zero uses `--rc-ink-muted`.
    **Direction is metric-aware:** for *Churned Subscribers* a rising number is **bad**, so the delta color
    is inverted (up = red). The metric declares its `good_direction` (see Home dashboard data contract).
- **Tokens:** `--rc-card`, `--rc-radius-card`, `--rc-shadow-card`, `--rc-border`, `--rc-type-kpi`,
  `--rc-type-label`, `--rc-green`/`--rc-red` for delta.
- **i18n:** caption keys live in the owning screen domain (e.g. `dashboard.kpi.processed_revenue`);
  shared state copy in `states.kpi.*`.

### 4.2 Status badge (pill)

- **Anatomy:** pill container + label text (`--rc-type-label`, not uppercased in HE) + optional leading dot.
- **Variants (by semantic token):** `green` · `gray` · `teal` (+ a `NEW` sub-variant) · `red` · `amber`.
- **States:** static (no interaction). Tooltip optional on truncation.
- **Canonical status → badge map** (drives every list/detail; statuses are from
  [ARCHITECTURE.md §3.3](../../ARCHITECTURE.md), never invented):

  | Domain status | Badge | Label key |
  |---|---|---|
  | `draft` | gray | `billing.status.draft` |
  | `awaiting_first_payment` | amber | `billing.status.awaiting_first_payment` |
  | `active` | green | `billing.status.active` |
  | `paused` | gray | `billing.status.paused` |
  | `completed` | green | `billing.status.completed` |
  | `cancelled` | gray | `billing.status.cancelled` |
  | `failed` | red | `billing.status.failed` |
  | ledger `pending` | gray | `billing.ledger_status.pending` |
  | ledger `succeeded` | green | `billing.ledger_status.succeeded` |
  | ledger `failed` | red | `billing.ledger_status.failed` |
  | ledger `retry_scheduled` | amber | `billing.ledger_status.retry_scheduled` |
  | ledger `refunded` | gray | `billing.ledger_status.refunded` |
  | ledger `cancelled` | gray | `billing.ledger_status.cancelled` |
  | Order error (unresolved) | red | `order_errors.status.unresolved` |
  | Order error (resolved) | green | `order_errors.status.resolved` |
  | Upsell flow `live` | teal `NEW`/green | `upsell.flow_status.live` |
  | Upsell flow `paused` | gray | `upsell.flow_status.paused` |
  | Upsell flow `draft` | gray | `upsell.flow_status.draft` |

- **Tokens:** `--rc-radius-pill`, the semantic color + `*-bg` pair.

### 4.3 Data table row (with checkbox + hover)

- **Anatomy:** leading checkbox slot (optional) · cells · optional trailing row-actions (⋯ menu).
- **Variants:** `default` · `with-checkbox` (bulk-select) · `with-status` (a badge cell) · `with-row-actions`.
- **States:**
  - **hover** — row background → `--rc-blue-bg` faintly; cursor pointer if row is a link.
  - **selected** — checkbox checked + row background `--rc-blue-bg`; bulk-action bar appears above the table.
  - **loading** — 5–8 skeleton rows (shimmer cells).
  - **empty** — table body replaced by the Empty-state block (§4.13).
- **Tokens:** `--rc-card`, `--rc-border` (row dividers), `--rc-blue-bg` (hover/select), `--rc-type-label`
  (header), `--rc-type-body` (cells).
- **RTL note:** column order mirrors; the checkbox + row-actions swap sides; numeric/currency cells stay
  LTR-formatted inside an RTL row.

### 4.4 "+ Add filter" button + filter chip

- **Anatomy:** dashed/outline chip with a leading `+` and `common.add_filter` label → opens a dropdown
  of filterable fields → applied filters render as removable chips (field + operator + value + ✕).
- **Variants:** `add` (the trigger) · `applied` (removable result chip).
- **States:** `default` · `open` (dropdown visible, `--rc-shadow-pop`) · `applied` · `applied-hover` (✕ emphasized).
- **Tokens:** `--rc-radius-control`, `--rc-border`, `--rc-ink-muted`, `--rc-shadow-pop` (dropdown).
- **i18n:** trigger `common.add_filter`; each filter field label lives in its screen domain
  (e.g. `subscriptions.filter.plan_kind`); operator labels in `common.filter_op.*`
  (`is`, `is_not`, `greater_than`, `between`, `contains`).

### 4.5 Primary CTA (blue-filled)

- **Anatomy:** label (+ optional leading icon).
- **States:** `default` · `hover` (`--rc-blue-600`) · `pressed` · `loading` (inline spinner, label dimmed,
  control disabled) · `disabled` (reduced opacity, no shadow). Focus shows `--rc-ring`.
- **Tokens:** `--rc-blue`, `--rc-blue-600`, `--rc-ink-on-primary`, `--rc-radius-control`, `--rc-ring`.

### 4.6 Secondary CTA (white + border)

- **Anatomy:** label (+ optional icon).
- **States:** `default` (`--rc-card` bg, `--rc-border`, `--rc-ink` text) · `hover` (border → `--rc-border-strong`,
  bg faint) · `disabled` · focus `--rc-ring`.
- **Tokens:** `--rc-card`, `--rc-border`, `--rc-ink`, `--rc-radius-control`.

### 4.7 Destructive CTA

- **Anatomy:** red label/outline → on click, a confirm step (button text changes to the confirm copy or a
  modal opens) → red-filled on confirm.
- **Variants:** `inline` (two-step in place) · `modal` (opens a confirmation dialog — required for
  cancel/refund, see §4.12).
- **States:** `default` (red outline) · `confirm` (red filled, shows `actions.*.confirm` copy) ·
  `loading` · `disabled`.
- **Tokens:** `--rc-red`, `--rc-red-bg`, `--rc-ink-on-primary`, `--rc-radius-control`.

### 4.8 Tabs (Recharge underline)

- **Anatomy:** horizontal tab row; active tab has a 2px `--rc-blue` underline.
- **States:** `active` (ink text + blue underline) · `default` (muted ink, no underline) · `hover`
  (ink darkens) · `disabled` (gated tab — shows a small lock + tooltip `states.gate.locked`).
- **Tokens:** `--rc-blue` (underline), `--rc-ink` / `--rc-ink-muted`, `--rc-border` (row baseline).
- **RTL note:** tab order mirrors; active underline alignment follows the text.

### 4.9 Accordion

- **Anatomy:** header row (title + optional count badge + chevron) + collapsible body.
- **Variants:** `single` (one open at a time) · `multi-open`.
- **States:** `collapsed` (chevron points to start-side) · `expanded` (chevron rotated, body shown) ·
  `loading` (body skeleton) · `empty` (body shows a one-line empty message).
- **Tokens:** `--rc-border` (separators), `--rc-card`, `--rc-type-h` (header).
- **RTL note:** chevron direction flips; collapsed chevron points left in HE.

### 4.10 Right-sidebar panel

- **Anatomy:** panel title (`--rc-type-subh`) + body (key/value rows, list, or actions) + optional "Edit" link.
- **Variants:** `info` (key/value) · `list` (tags, segments) · `actions` · `timeline`.
- **States:** `loading` (skeleton rows) · `empty` (panel-specific empty line) · `error` (inline retry).
- **Tokens:** `--rc-card`, `--rc-border`, `--rc-radius-card`, `--rc-shadow-card`.

### 4.11 Subscription / address card

- **Anatomy:** header (plan-kind label + status badge §4.2) · primary line (recurring: frequency +
  next charge; installments: paid/total + remaining balance + next charge) · progress slot
  (installments only — paid-of-total bar) · line-items summary · row actions (link to detail).
- **Variants:** `installments` · `recurring`.
- **States:** mirrors the plan status (`active`/`paused`/`failed`/`awaiting_first_payment`/`completed`/`cancelled`)
  via the badge; `failed` card gets a thin `--rc-red` left rule + a "View error" link; `awaiting_first_payment`
  gets an amber rule; `loading` skeleton.
- **Tokens:** badge map, `--rc-card`, `--rc-border`, `--rc-green`/`--rc-red`/`--rc-amber` left-rule.
- **RTL note:** the installments progress bar fills from the start-side (right in HE); currency stays LTR.

### 4.12 Confirmation dialog (money-moving actions)

- **Anatomy:** title · body (explains the side-effect in plain language) · optional amount echo ·
  optional sub-choice (e.g. cancel *immediately* vs *at period end*) · destructive confirm + cancel.
- **Used by:** cancel · pause/resume · charge now · refund · send payment link · resolve order error · accept upsell.
- **States:** `default` · `loading` (confirm shows spinner, both buttons disabled) · `error`
  (inline error banner inside the dialog, retry allowed).
- **Tokens:** `--rc-card`, `--rc-radius-card`, `--rc-shadow-pop`, `--rc-red` (destructive confirm).
- **i18n:** every dialog title/body/confirm is an `actions.*` key (see each screen + 99-i18n-conventions).

### 4.13 Empty-state block

- **Anatomy:** illustration slot · headline · supporting line · optional primary CTA.
- **Variants (distinct copy — never share):**
  - **first-run** — "nothing exists yet" (e.g. no customers because the store has no orders yet).
  - **filtered-no-results** — "your filter/search matched nothing" (offers "Clear filters").
  - **error** — "we couldn't load this" + retry.
- **Tokens:** `--rc-ink-muted` (copy), illustration slot, `--rc-blue` (CTA).
- **i18n:** `states.empty.*`, `states.error.*` shared; screen-specific first-run copy in the screen domain.

### 4.14 Timeline event row (reused from the engine, re-skinned)

- **Anatomy:** time/date · actor chip (system / merchant-admin / customer / webhook) · `kind` label
  (humanized via `PlanEventPresenter::humanizeKind`) · success/failure marker · detail summary
  (`summarizeDetails()`) · for the 5 email kinds, a **"Preview" affordance** (opens isolated `iframe srcdoc`).
- **Variants (by `kind` taxonomy):** `charge` · `refund` · `email` (previewable) · `webhook` · `admin-action`
  · `state-change` · `document` (invoice issued/failed) · `shopify-api`.
- **States:** `success` (green marker) · `failure` (red marker) · `info` (gray) · `previewable`
  (email rows show the preview button).
- **Hard rules (engine convention — CLAUDE.md / ARCHITECTURE.md §6.6):**
  - **Never render `invoice_url` or any raw PayPlus `document_url` in the Timeline UI.** It exists in the
    raw log only. Spec'd lists must show the human label, never the URL.
  - Preview is rendered in an **isolated `iframe srcdoc` with `htmlspecialchars`** — the merchant email body
    is never `Blade::render()`'d (RCE prevention).
- **Tokens:** `--rc-border`, `--rc-green`/`--rc-red`/`--rc-gray` markers, `--rc-blue` (preview link).
- **i18n:** kind labels come from the presenter (humanized) + an `emails.timeline.*` label set for the
  previewable kinds; actor labels in `common.actor.*`.

### 4.15 Flow Builder node (Post-Purchase Offers canvas)

- **Anatomy:** node card on a canvas · type label · summary · connection ports · validation marker.
- **Variants:** `trigger` · `offer` · `branch-accept` · `branch-decline`.
- **States:** `default` · `selected` (blue ring) · `invalid` (red ring + inline reason) · `linked`
  (has an outgoing connection).
- **Tokens:** `--rc-card`, `--rc-blue` (selected), `--rc-red` (invalid), canvas bg uses `--rc-bg`.
- **Detail:** see [40-post-purchase-offers.md](40-post-purchase-offers.md).

### 4.16 Consent disclosure block (saved-token charges)

- **Anatomy:** plain-language statement of **what** is charged, **how much**, **when**, **how to cancel/contact**.
- **Variants:** `installments` · `recurring` · `upsell`.
- **Hard rule (ARCHITECTURE.md §4.3):** any UI that triggers a charge against a saved PayPlus token for a
  **future or additional** charge must show this block and the system must persist a `customer_consents` row.
- **Tokens:** `--rc-card`, `--rc-border`, `--rc-amber-bg` (subtle emphasis).
- **i18n:** `upsell.consent.disclosure` etc. with `:amount`, `:frequency`, `:cancel_url` placeholders.

---

## 5. Component → screen usage matrix (quick reference)

| Component | Home | Customers | Subscriptions | Orders/Errors | Upsell | Settings | Portal |
|---|---|---|---|---|---|---|---|
| KPI card | ● | ● (header) | — | ● (errors count) | ● | — | ● (next charge) |
| Status badge | — | ● | ● | ● | ● | ● (connection) | ● |
| Table row +checkbox | ● | ● | ● | ● | ● (flows) | — | ● (history) |
| + Add filter | — | ● | ● | ● | ● (activity) | — | — |
| Tabs | ● | — | — | ● | ● | ● (sections) | — |
| Accordion | — | ● | ● | — | — | ● | ● |
| Subscription/address card | — | ● | — | — | — | — | ● |
| Timeline row | — | ● | ● | — | — | — | ● (read-only) |
| Confirmation dialog | — | ● | ● | ● | ● | ● | ● |
| Consent disclosure | — | — | ● (create) | — | ● (thank-you) | — | ● |
| Flow Builder node | — | — | — | — | ● | — | — |
| Empty-state block | ● | ● | ● | ● | ● | — | ● |

---

## 6. Open decisions for Aviad (design-system level)

- **D1 — Sparklines on Home KPI cards?** Recharge shows trend sparklines; they need a time-series
  contract from `laravel-backend`. Default: ship value+delta first, add sparkline in Phase 8.
- **D2 — Card border vs. border + shadow.** Recharge uses a faint border *and* a soft shadow.
  Spec'd as both (`--rc-border` + `--rc-shadow-card`); confirm this is the desired weight, not border-only.
