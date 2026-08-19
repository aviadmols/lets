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
- WooCommerce merchants access the dashboard via **direct login** at app.lets.co.il **or** embedded in wp-admin
  (see "The LETS admin inside wp-admin" below — shipped later; the direct login still works unchanged).
- Build **all three pillars together**.

## Access & auth model (vs Shopify)
| Aspect | Shopify | WooCommerce |
|---|---|---|
| Connect | App Store → OAuth | Paste LETS `api_key`/`api_secret` in the plugin Settings |
| Per-request auth | session-token JWT | HMAC-SHA256(timestamp+method+path+body, api_secret), `X-LETS-*` |
| Credential lifespan | offline token (refreshable) | stable API key (merchant rotates in plugin Settings) |
| Dashboard | embedded iframe (App Bridge + session token) | direct login at app.lets.co.il, or embedded in wp-admin via a one-shot cookie-session link (same panel) |
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

## The LETS admin inside wp-admin (embedded dashboard)

A WooCommerce merchant clicks **LETS** in wp-admin and gets the LETS Filament admin in an **iframe, already
signed in**, with a menu the platform owner decided. It supersedes the "direct login at app.lets.co.il" row in
the table above for merchants who never want a second login (the direct login still works and is unchanged).

**The handshake (two steps, one shot).**

| Step | Route | Auth | What happens |
|---|---|---|---|
| 1 | `POST /api/woocommerce/embed/session` (`woocommerce.embed.session`) | plugin HMAC (`VerifyWooCommerceSignature`) | Body `{wp_user_email, wp_user_name, wp_user_id, locale, return_url}` → `{url, expires_in: 60}`. Mints `Str::random(48)` into the cache under `embed:woocommerce:<token>` for **60s** with the SIGNATURE'S shop id. |
| 2 | `GET /embed/woocommerce/{token}` (`woocommerce.embed.login`) | the token itself | `Cache::pull` → **single use**. Signs in a merchant user of that shop, regenerates the session, marks it embedded, sets the locale, redirects to the dashboard. A miss/expiry is a plain **410** page telling the merchant to click LETS again — never a login form. |

- **The shop is never taken from the body.** A `shop_id` in the request is ignored; the tenant is the
  HMAC-verified shop, so a merchant editing their own plugin can only ever open a door into their own store.
- **Who is signed in:** (a) a `User` with that email whose `shop_id` is this shop; else (b) the shop's oldest
  merchant user (the owner); else (c) a new user created for the shop with a random unusable password. A
  **platform admin is excluded from every branch**, and an email already belonging to another shop's user is
  refused (410) rather than borrowed.
- **Cookies:** production already serves the session cookie `SameSite=None; Secure; Partitioned`, which is what
  makes a cookie session work in the WordPress iframe. No config change is part of this feature.
- **Not the Shopify path:** `EmbeddedAuthenticate` / `EnsureEmbeddedSession` (App Bridge, session tokens,
  bouncing) stay Shopify-only. A WooCommerce embed is a plain cookie session.
- Every redemption logs `admin.embedded_login` with `shop_id` + `user_id`.

**Embedded mode in the panel.** While `session('embedded_platform') === 'woocommerce'`:
- The user menu (logout/profile) and the language switch are hidden — WordPress owns that chrome. Implemented as
  a `<meta name="lets-embedded">` at `HEAD_END` plus one rule in `resources/css/filament/admin/components/embedded.css`
  (both controls render as `.fi-user-menu`). Zero inline CSS/JS.
- Navigation is filtered to the areas the platform owner allowed, through ONE seam:
  `PanelAccess::embeddedAllows($screenClass)` consulted by `ShopScopedScreen::shouldRegisterNavigation()` **and**
  `canAccess()` — a hidden area also **403s on its URL**, because hiding is not security.

**Platform-owner control, per shop.** `shops.embedded_menu` (nullable JSON; **null = everything allowed**) is
edited from the platform admin's shop page (`/admin/shops/{id}` → header action **Embedded menu**, WooCommerce
shops only, platform admin only). The area catalogue lives once in `App\Support\Ui\EmbeddedMenu` and is read by
both the checkbox list and the filter:

`home · customers · subscriptions · loyalty · gift_orders · import · products · payments · documents · upsell ·
storefront · analytics · observability · settings_billing · settings_invoicing · settings_mail ·
settings_customer_area · settings_upsell_appearance`

Rules the map encodes: **Home is always allowed** (a menu item that 403s is a bug report); the **PayPlus
connection**, **Team logins** and the **platform Shops list** are never shown inside WordPress whatever the list
says (credentials and logins belong in the full admin); a screen **not** in the map is **allowed** — failing open
so a newly shipped screen does not silently vanish; and ticking every box stores `null`, so next release's screen
appears for that shop too. Labels: `lang/{en,he}/embedded.php`; the action's own copy: `platform.embedded.*`.

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
