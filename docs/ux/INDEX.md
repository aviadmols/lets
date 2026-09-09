# UX / Surface Status Index — the product-manager's source of truth

> **Owner:** `product-ux-architect`. **Rule:** EVERY unit of work updates the relevant row here BEFORE it is
> considered "done" (CLAUDE.md / plan W11). This is the single place that answers *"what is built, on which
> platform, and does it regress anything?"* — read it first, update it last.
>
> **Status legend:** `built` (shipped + tested) · `partial` (some pieces shipped) · `planned` (in the roadmap,
> not started) · `n/a` (not applicable to that platform). **Platforms:** Shopify (live) · WooCommerce (W11).

## How to use this index
- Before starting a unit: find its row, confirm the platform column you're touching is `planned`/`partial`.
- After finishing: flip the cell to `partial`/`built`, link the phase review (`docs/reviews/phase-*.md`), and
  confirm **no Shopify cell changed** unless the work was an explicit, gatekeeper-approved Phase-0 seam edit.
- Shopify is the regression baseline: a WooCommerce unit must never flip a Shopify cell from `built`.

## Admin surfaces (the SAME Filament dashboard for both platforms)

| Surface | Spec | Shopify | WooCommerce | Notes |
|---|---|---|---|---|
| Home / KPI dashboard | [10-home-dashboard.md](10-home-dashboard.md) | built | built | Platform-agnostic; aggregates per bound shop. |
| Customers | [20-customers.md](20-customers.md) | built | built | Same model; WC customers arrive via sync/activation. |
| Subscriptions / Installment plans | [30-subscriptions.md](30-subscriptions.md) | built | planned (W11 P2–P3) | Same `InstallmentPlan`; WC plans show alongside Shopify. |
| Subscription lifecycle actions (Pause/Resume/Cancel) | [30-subscriptions.md](30-subscriptions.md) | built (Wave 1a) | inherits | `SubscriptionLifecycleService` + guarded state machine + cancellation email; buttons on ViewSubscription. |
| **Refund / cancel drawer** (full, partial, cancel-the-order, restock) | [refunds plan](../plans/refunds-cancellations-and-card-update-links.md) | built | built | `RefundRequest` + `RefundOrchestrator`: money (PayPlus) → store (`StoreRefunder` per platform) → credit note (`DocumentContext::REFUND`/`CANCELLATION`). Opened from the payment page + payments list; the plan's ledger table links through. A store leg that fails after money moved = `needs_attention` + retry. Orders this app never charged (Shopify Payments, a Woo order paid on another gateway) refund through the STORE — there the store call IS the refund, so its failure is `failed`, not `needs_attention`. Refunds pressed inside **WooCommerce** are mirrored back (the gateway's `process_refund` runs the money leg; other refunds record a delta against a running total, so a duplicate hook counts nothing). Shopify's `refunds/create` mirror awaits protected-data approval. |
| **Card-update link** (copy / email / SMS, revoke, sent→opened→updated) | [refunds plan](../plans/refunds-cancellations-and-card-update-links.md) | built | built | Durable LETS link (`/c/card/{token}`, hash-only, 1–30 days) that mints the PayPlus re-vault page at click time. The rail is platform-neutral now (`shops.callback_token`; the `/woocommerce/cardupdate/*` paths stay as aliases). Shopify-Payments contracts keep Shopify's own update email. |
| Post-Purchase Offers / Flow Builder | [40-post-purchase-offers.md](40-post-purchase-offers.md) | built | planned (W11 P4) | Same flows/triggers/offers; WC thank-you surface differs. |
| Settings — PayPlus Connection | [50-settings.md](50-settings.md) | built | built | Per-shop PayPlus creds; platform-agnostic. |
| Settings — Shopify Connection | [50-settings.md](50-settings.md) | built | n/a | Shopify-only. |
| Admin — **Add WooCommerce store** (Shops list action) | [70-woocommerce-platform.md](70-woocommerce-platform.md) | n/a | built (admin side) | Header action on `/admin/shops`: domain → mint connection token (only sha256 hash + encrypted secret stored) → reveal-once modal (copy + plugin-download link). Plugin connect endpoint next. |
| Settings — Mail notifications | [50-settings.md](50-settings.md) | built | built | Per-shop; platform-agnostic. |
| Customer Portal (signed magic link) | [60-customer-portal.md](60-customer-portal.md) | built | planned (W11 P2+) | Same portal; WC plans included. |
| Platform admin — Shops list | [01-navigation.md](01-navigation.md) | built | built | Shows WC shops once installed (platform badge). |

## Storefront surfaces (platform-specific implementations of the same experience)

| Experience | Shopify | WooCommerce | Notes |
|---|---|---|---|
| Deposit + installments button + calculator | built (`extensions/lets-installments`) | planned (W11 P2) | WC = WP-plugin product widget → `/wc/installments/*` → PayPlus page. |
| Subscription (recurring) start | built | planned (W11 P3) | WC = widget "subscribe" mode → recurring `start`. |
| Post-purchase / thank-you upsell | built (`extensions/lets-thank-you`) | planned (W11 P4) | WC = `woocommerce_thankyou` block → `/wc/upsell/*`. |
| PayPlus hosted page ("דף סליקה") | built | planned (W11 P2) | Shared `generateLink()`; WC redirect/return handled by the plugin. |
| Full PayPlus checkout gateway | n/a | planned (W11 P5) | WC = `WC_Payment_Gateway` mode B. |

## Backend platform seam (Phase 0 — shared)

| Seam | Status | Notes |
|---|---|---|
| `Shop.platform` discriminator | built | shopify / woocommerce constants exist. |
| `ProductSourceFactory` (per-platform product source) | built | `WooCommerceProductSource` IMPLEMENTED (WC REST `/products` + variations → DTOs); `WooClientFactory`/`WooCommerceClient`; plugin mints WC keys; products import on connect. |
| `PlatformOrderStrategyFactory` + `PlatformOrderStrategy` | built (seam; WC impl P2) | `ShopifyOrderStrategy extends` it; orchestrator routes per platform (Shopify byte-identical, suite green 187). `WooCommerceOrderStrategy` added P2. |
| `PlatformInvoiceServiceFactory` + `PlatformInvoiceService` | built (seam; WC impl P2) | `ShopifyDepositInvoiceAdapter` over a per-shop client; `DepositPlanService` resolves via factory + stores neutral keys. WC PayPlus-page impl P2. |
| `PaidOrderPlanResolverFactory` + `PaidOrderPlanResolver` | built (seam; WC impl P2) | `ShopifyPaidOrderPlanResolver` (note-attr/draft, tenant-scoped); `PlanActivationService` delegates the lookup. WC order-meta impl P2. |
| `ChargeOrchestrator` / `DepositPlanService` / `PlanActivationService` — 3 surgical edits | built | All three seams in; Shopify byte-identical; full suite green (195). Fixed a latent deposit-flow DI bug (per-shop client). |
| WooCommerce schema (Shop WC creds + `lets_api_*`, `installment_plans.external_*`, `WebhookEvent::SOURCE_WOOCOMMERCE`) | built | Additive migrations; WC creds + api-secret encrypted at rest; `shopify_domain` now nullable; suite green (198). Phase 0 done. |

## WordPress plugin (`plugins/lets-payplus-woocommerce/`)

| Unit | Status | Phase |
|---|---|---|
| Settings page + API-key handshake | built (connect loop e2e) | W11 P1 — plugin signs HMAC; `VerifyWooCommerceSignature` + `/api/woocommerce/install` verify + store + return webhook secret. Product sync (WC REST) next. |
| Product-page deposit/subscription widget | planned | W11 P2–P3 |
| Thank-you upsell block | planned | W11 P4 |
| Full `WC_Payment_Gateway` mode | planned | W11 P5 |
| Packaging / distribution (zip, readme, i18n) | planned | W11 P6 |
