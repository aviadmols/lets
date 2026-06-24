# 70 — WooCommerce / WordPress Platform

> **Owner:** `product-ux-architect`. **Implemented by:** `woocommerce-integration` (+ `laravel-backend` for the
> Phase-0 seam, `admin-design-system` for the Filament Settings page chrome).
> **First law:** ADD a platform sibling behind `$shop->platform`; never fork or regress the Shopify path. The
> proof of no-regression is the entire existing Shopify + billing test suite staying green at every commit.
> **Pillars served:** all three (deposit/installments, recurring subscriptions, post-purchase upsell) + a full
> PayPlus checkout gateway. **Plan:** `iridescent-tickling-octopus.md` → W11.

---

## Purpose
Let Israeli **WooCommerce** merchants who run PayPlus use LETS for the same three pillars, managed from the SAME
Filament dashboard and billed on the SAME PayPlus engine, via a **WordPress plugin** that connects through an
**API key + HMAC** handshake (not Shopify OAuth/session tokens) and surfaces the storefront experiences + the
PayPlus hosted payment page ("דף סליקה") inside WooCommerce.

## Locked product decisions (user)
- Target = **WooCommerce** (WC REST API; PayPlus already runs there as a WC gateway).
- **Both** payment modes: (A) deposit/subscription/upsell surfaces ALONGSIDE the store's existing checkout
  (first); (B) LETS as a full `WC_Payment_Gateway` (later).
- WooCommerce merchants access the dashboard via **direct login** at app.lets.co.il (no wp-admin embedding yet).
- Build **all three pillars together**.

## Access & auth model (vs Shopify)
| Aspect | Shopify | WooCommerce |
|---|---|---|
| Connect | App Store → OAuth | Paste LETS `api_key`/`api_secret` in the plugin Settings |
| Per-request auth | session-token JWT | HMAC-SHA256(timestamp+method+path+body, api_secret), `X-LETS-*` |
| Credential lifespan | offline token (refreshable) | stable API key (merchant rotates in plugin Settings) |
| Dashboard | embedded iframe | direct login at app.lets.co.il (same panel) |
| Webhook auth | app-level HMAC (platform secret) | per-shop HMAC (`wc_webhook_secret`) |
| Order creation | Admin API draft-order-completed-as-paid | WC REST `POST /orders` |

## SaaS ↔ plugin HTTP contract (mirror the Shopify App-Proxy contracts one-for-one)

> Each storefront endpoint is server-signed by the plugin (HMAC api-key); the shopper's browser never holds the
> secret (browser→plugin uses a WordPress nonce). Prices are resolved server-side from the synced catalog; the
> quote is recomputed server-side at `start`. Tenant is derived ONLY from the verified key/signature.

| Endpoint | Method · Auth | Shopify analogue | Request → Response | Reuses |
|---|---|---|---|---|
| `/api/woocommerce/install` | POST · HMAC | (OAuth callback) | `{base_url, consumer_key, consumer_secret, versions}` → `{wc_shop_token, wc_webhook_secret, shop_public_id}` | `MerchantUserProvisioner`, `ImportShopProductsJob` |
| `/api/woocommerce/verify-key` | POST · HMAC | (session-token verify) | `{}` → `{ok, shop, plan}` | — |
| `/wc/installments/modal/{productId}/{variantId}` | GET · HMAC | `/installments/modal/...` (App-Proxy) | → calculator HTML/JSON | `ProductPriceResolver`, `InstallmentQuote` |
| `/wc/installments/quote` | POST · HMAC | `/installments/quote` | `{variant_id, deposit_percent, installments, frequency, payment_day, currency}` → `{quote}` | `InstallmentQuote::build` |
| `/wc/installments/start` | POST · HMAC | `/installments/start` | `{product_id, variant_id, knobs, customer_*}` → `{plan_public_id, invoice_url, deposit_amount, currency}` | `DepositPlanService::create` → `WooCommerceDepositInvoiceService` (`generateLink`) |
| `/wc/upsell/offer` | GET · HMAC | `/upsell/offer` (session-token) | `{order_id, customer}` → `{offer, reason?}` | `UpsellResolver` |
| `/wc/upsell/accept` | POST · HMAC | `/upsell/accept-api` (signed) | `{flow, offer, parent_order, customer}` → `{result, charged, transaction_uid, next_offer}` | `UpsellChargeService::accept` |
| `/woocommerce/webhooks/{wc_shop_token}` | POST · WC HMAC | `/shopify/webhooks` | WC webhook body → 202 | `WooWebhookRouter` → `WooOrderPaidHandler` |

## Order strategy per `charge_context` (WooCommerce)
| Context | WC action |
|---|---|
| deposit | parent WC order `on-hold`/`processing`, store `lets_plan_public_id` meta, locked (not completed) |
| installment | update parent order meta (paid/remaining/next_charge_at/status) |
| installment final | parent order `completed` (release), final document via `DocumentPolicy` |
| recurring | new paid WC order per cycle, linked by meta; failed cycle → no order |
| upsell | linked child WC order after `UpsellChargeService` charges the saved token |

## States / copy (per surface — to be filled per phase by product-ux-architect)
- WooCommerce Connection (Settings): not-connected / generating-key / connected (health) / key-rotated / error.
- Deposit widget: idle / calculating / redirecting-to-PayPlus / returned-paid / returned-cancelled / error.
- Thank-you upsell: offer-shown / accepting / charged / declined / already / failed.
- i18n domains: `woocommerce.*`, `settings.*`, `storefront.*`, `validation.*`, `states.*` (EN authoritative, HE mirror, RTL).

## Tenant & money invariants (identical to Shopify)
`BelongsToShop` stamps `shop_id` everywhere · every WC job carries `shop_id` · no `withoutGlobalScopes()` in
product code · ledger-before-charge (deposit collected on the PayPlus page → no ledger row until paid; recurring/
installment open a PENDING ledger row before the gateway call) · idempotency keys carry no platform id (replayed
WC webhook → one charge) · `api_key` hashed, `api_secret`+`woocommerce_credentials` encrypted, never logged ·
the SAME `PayPlusGatewayFactory::for($shop)` charges.

## Definition of Done (per pillar, WooCommerce)
- **Connect:** merchant pastes the key in the plugin → Shop row (`platform=woocommerce`) created → products sync →
  merchant logs into the same dashboard and sees their catalog. Replayed install is idempotent.
- **Deposit/installments:** product widget → PayPlus page → webhook activates the plan once (replay-safe) →
  scheduler charges the remainder → final payment releases fulfillment.
- **Subscriptions:** subscribe mode → token saved → per-cycle WC order → pause/cancel → failed-cycle retry+notify.
- **Upsell:** thank-you offer → one-click accept charges once (idempotent) → linked child WC order → analytics.
- **Gateway mode:** a normal WC checkout pays via the LETS PayPlus gateway; ledger written; other pillars unaffected.
- **No-regression:** the Shopify dev store still works end-to-end; the full Shopify suite is green.
