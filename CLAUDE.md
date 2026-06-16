# CLAUDE.md — PayPlus Subscriptions, Installments & Post-Purchase Upsells (SaaS)

> Project memory for this repo. Read this first. The full plan lives in
> `C:\Users\user\.claude\plans\iridescent-tickling-octopus.md`; the locked
> architecture lives in [ARCHITECTURE.md](ARCHITECTURE.md).

## What this is

A **multi-tenant SaaS Shopify app** for Israeli merchants on the **PayPlus**
gateway. Recharge-style, but broader. Ships on the App Store as **LETS** at
**https://app.lets.co.il** (Partner org 128972608, app id 382947852289). Three
monetization pillars — **none may be dropped**:

1. **Deposit + installments until fully paid** → release fulfillment only after
   full payment, then issue the final document.
2. **Open-ended recurring subscriptions** (replenishment, bills until cancelled).
3. **PayPlus-token-based post-purchase / thank-you-page upsells** (one-click,
   charged on the already-saved token, no re-entry).

Sold on the Shopify App Store, **flat monthly tiers**, scaling to **hundreds of
shops × thousands of orders each**.

## The reference engine (reuse, don't reinvent)

A production single-tenant version already exists and implements ~90% of the core:
`C:\Users\user\Desktop\Projects\פייפלוס חשבונית` → module
`app/Modules/PayPlusShopifyInstallments/`. We **port and multi-tenant-refactor**
it. Treat that project as a read-only **reference oracle**. Key classes to reuse
are listed in §9 of the plan and in [ARCHITECTURE.md](ARCHITECTURE.md).

## The agent team (`.claude/agents/`)

Work is driven by 9 expert agents. Invoke `recharge-orchestrator` first; it
enforces the handoff order and phase gates.

`recharge-orchestrator` → `railway-infra` → `laravel-backend` →
`shopify-integration` → `saas-multitenancy-billing` →
`product-ux-architect` (parallel from start) → `admin-design-system`.

`shopify-app-release` is the connect/release specialist: it owns the LETS app
config (`shopify.app.toml`), OAuth scopes↔features, webhooks, App Bridge/session
tokens, the post-purchase + thank-you-page extensions (`extensions/`), billing
confirmation, and the App Store submission runbook (`docs/SHOPIFY-APP-SETUP.md`).

`code-review-gatekeeper` is the quality supervisor: it reviews **every** unit of
code the specialists produce and runs at every phase gate. It only reports
findings (BLOCKING / SUGGESTION) — the implementing agent applies the fix. A
BLOCKING finding stops a phase from advancing. Append-only reviews live in
`docs/reviews/`.

## Local toolchain (this machine)

PHP 8.4 (Herd): `C:\Users\user\.config\herd\bin\php84\php.exe`
Composer: `<php84> C:\Users\user\.config\herd\bin\composer.phar`
(PHP/Composer are NOT on PATH — use these absolute paths in Bash.)

## Non-negotiable conventions

- **CONST-at-top.** Every file opens with its constants block (PHP: a
  `// === CONSTANTS ===` block of `const`; Blade/CSS: a token-reference block).
- **No inline CSS in admin/storefront UI.** Tokens → CSS custom properties →
  component classes only. No `style="…"`, no Tailwind arbitrary token values.
  **Exception:** email-template HTML *requires* inline CSS (clients strip
  `<style>`) — inline styles in `resources/views/emails/*` and merchant-edited
  email bodies are allowed.
- **Email-template safety.** Merchant-edited email HTML is substituted with
  **`strtr()`, NEVER `Blade::render()`** on merchant input (RCE prevention).
  Preview only via isolated `iframe srcdoc` + `htmlspecialchars`.
- **Tenant-safety is a RELEASE BLOCKER.** Every tenant-owned model has `shop_id`
  + the `BelongsToShop` trait (global scope). No `withoutGlobalScopes()` in
  product code (only audited platform-admin services). **Every queued job
  receives `shop_id` explicitly** — never infer the shop from global state,
  session, domain, or config.
- **Money safety.** No charge without a `payment_ledger` row. Every charge has a
  deterministic idempotency key (§3.2 of the plan). Documents go through the
  central `DocumentPolicy` service — never hardcode document types in the
  orchestrator. Future charges require a stored `customer_consents` row. Every
  refund/cancel/pause writes a ledger event + calls `DocumentPolicy` + updates
  Shopify.
- **State transitions.** Only the canonical transitions (§3.3 of the plan /
  ARCHITECTURE.md) are legal; every move writes a ledger + Timeline event.
- **i18n.** English is the default; all user-facing strings go through `__()`
  with keys in `lang/en/*.php`; `lang/he/*.php` mirrors them; build RTL-aware.
- **Modular & short.** Small single-responsibility classes. Reuse the engine.

## Module map (target)

- `app/Models/Shop.php` — the tenant. Per-shop encrypted PayPlus + Shopify creds.
- `app/Support/Tenant.php` + `app/Models/Concerns/BelongsToShop.php` — tenancy.
- `app/Modules/PayPlusShopifyInstallments/` — the ported shared billing engine
  (gateway factory, `ChargeOrchestrator`, jobs, scheduler, mail, Timeline,
  portal, refunds).
- `app/Domain/Billing/` — `payment_ledger`, idempotency, `DocumentPolicy`,
  state machines.
- `app/Domain/Upsell/` — flows, triggers, offers, branches, events.
- Admin = Filament 3 panel re-skinned to the Recharge spec.

## Status

Scaffold pass complete (agents + skeleton). Engine port + screens are executed
by the agents across the phased roadmap (§7 of the plan).
