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
- **Never wipe a database you cannot prove is local.** `migrate:fresh`,
  `migrate:refresh`, `migrate:reset` and `db:wipe` are refused by
  `App\Support\DestructiveCommandGuard` unless the resolved connection is local.
  A connection NAME is not a target: a `url` in the config can override the
  driver, which is how `--database=sqlite` once dropped a live Railway Postgres.
  The guard judges the connection *after* url parsing. Override deliberately via
  `ALLOW_DESTRUCTIVE_DB=true`, never with a CLI flag.
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
- **Document safety.** No accounting document without an `issued_documents` row,
  written BEFORE the provider call. External invoicing APIs have no idempotency
  key of their own, so an attempt whose outcome is unknown (worker died
  mid-flight, id-less 2xx, transport error) is NEVER re-posted — it becomes
  `unresolved` for a human. A missing document is a button-click to fix; a
  duplicate tax document is a VAT correction with the authority.
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
- `app/Domain/Invoicing/` — accounting documents (Green Invoice / "Morning").
  `DocumentIssuer` is the ONE entry point; `issued_documents` is its ledger
  (no document without a row, unique `(shop_id, idempotency_key)`).
  `InvoiceProviderFactory::for($shop)` is per-shop + returns null when the
  merchant hasn't opted in, so every hook is a clean no-op by default. Per-shop
  policy in `merchant_invoicing_settings` — including `scope`
  (`plans_only` vs `all_orders`, the WooCommerce "invoice every site order"
  switch) and a per-context document-type map.
- `app/Domain/Upsell/` — flows, triggers, offers, branches, events.
- `app/Domain/Campaigns/Studio/` + `app/Domain/Ai/` + `app/Domain/Brand/` — the
  AI Newsletter Studio. A campaign in `editor_mode='studio'` has a JSON block
  document as its ONE source of truth; every save compiles it into `body_html`
  (+ `body_text`) through `DocumentService` — the send pipeline never changed.
  The model NEVER returns raw HTML: it answers through a forced tool as patch
  ops from the `PatchOp::OPS` whitelist (no send/schedule/delete verb — pinned
  by test), dry-run applied, diffed, and only a human approval writes. AI calls
  go only through `AiGateway` (platform kill switch → key → daily token budget
  → provider; usage recorded win or lose); prompts/models are platform-editable
  (`ai_prompts` → `PlatformAiSettings` overrides → `config/ai.php` defaults).
  Brand capture fetches the merchant's site through `SafeSiteFetcher` — the
  platform's ONE merchant-steered outbound request; its SSRF walls (resolved
  addresses judged, every redirect re-walled, metadata/localhost/private/CGNAT
  refused) are pinned by test and must never come down. Scraped content reaches
  the model only as delimited UNTRUSTED data, and every model answer is
  re-guarded into studio vocabulary before it may touch a row.
- `app/Domain/Campaigns/Email/` — merchant email campaigns. The audience bag is
  the account-offer shape widened to every customer LETS knows (subscribers on
  both rails, deposit/instalment buyers, club members), deduped to one email per
  person. `{account_login_url}` is a **passwordless credential**: minted per
  recipient at send time, stored as sha256, signed in by a POST the landing
  page auto-submits (a GET spends nothing — mail scanners click first),
  reusable within a TTL window anchored at the FIRST click (phone now, laptop
  later), revocable per token and per campaign mid-window,
  and it never mints a WordPress session itself (WooCommerce gets a 120s
  `ImpersonationTicket` in `customer` mode; Shopify gets our hosted account
  page, because Shopify mints no storefront session for an app). Rules in
  `docs/security/security-policies.md` §5.1. Marketing mail must carry
  `{unsubscribe_url}` + `List-Unsubscribe`, and is subject-tagged "פרסומת".
- `app/Domain/Mail/` — **who sends a shop's mail**. `MailTransport` owns the ONE
  ladder (the merchant's own SMTP → the PLATFORM's SendGrid account → the .env
  mailer); `MailSettingsConfigurator` (request-time) and `CampaignMailer`
  (queue-time) both read it, so the two paths cannot disagree. The SendGrid key
  lives on `platform_mail_settings` (one row, no tenant, encrypted, managed on
  **Platform → Email delivery**, platform-admins only) with `SENDGRID_API_KEY`
  as the FALLBACK — a saved key wins, so a fresh environment can still come up
  from variables alone. A shop authenticates its OWN domain on that
  one account (`SenderDomains` + `shop_sender_domains`): the merchant publishes
  the CNAMEs the provider issues, and the screen resolves each record itself so
  a failure names the missing host instead of saying "not verified". An
  **unverified domain is never a From** — the provider refuses it, and an
  unsigned message would spend the merchant's own domain reputation.
- Admin = Filament 3 panel re-skinned to the Recharge spec.

## Status

Scaffold pass complete (agents + skeleton). Engine port + screens are executed
by the agents across the phased roadmap (§7 of the plan).
