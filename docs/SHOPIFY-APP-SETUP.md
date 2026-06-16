# LETS — Shopify app setup & go-live runbook

The app ships on the Shopify App Store as **LETS** at **https://app.lets.co.il**.

| Fact | Value |
|---|---|
| App name / handle | **LETS** / `lets` |
| Public URL | `https://app.lets.co.il` |
| OAuth callback | `https://app.lets.co.il/shopify/callback` |
| Webhook endpoint | `https://app.lets.co.il/shopify/webhooks` |
| App Proxy | `https://{shop}/apps/payplus/...` → `https://app.lets.co.il/proxy/...` |
| Partner org | `128972608` |
| App id | `382947852289` |
| Partner dashboard | https://dev.shopify.com/dashboard/128972608/apps/382947852289/settings |
| Pinned API version | `2026-04` (REST + GraphQL + webhooks + extensions) |

---

## The ONE manual step — paste the API key + secret

Secrets are **never** committed. From the Partner dashboard
(https://dev.shopify.com/dashboard/128972608/apps/382947852289/settings) copy two
values into the deploy environment (and your local `.env`):

| Dashboard field | Goes to | Notes |
|---|---|---|
| **API key** (Client ID) | `SHOPIFY_API_KEY` | Also paste into `shopify.app.toml` `client_id` (it is environment-specific, not a secret). |
| **API secret key** | `SHOPIFY_API_SECRET` | Signs OAuth HMAC, webhook HMAC, **and** the App-Proxy `signature`. Empty in production ⇒ webhook + proxy routes return **503** (fail closed). Never commit. |

> `SHOPIFY_WEBHOOK_SECRET` is optional — if unset, config falls back to
> `SHOPIFY_API_SECRET` (app-level webhooks are signed with the app secret).

The rest of the Shopify env (`SHOPIFY_APP_URL`, `SHOPIFY_API_VERSION`,
`SHOPIFY_OAUTH_SCOPES`, `SHOPIFY_APP_HANDLE`) is pre-filled in `.env.example` for
`app.lets.co.il`.

---

## 1. Push the app config

```sh
# Needs Partner login (interactive). Validates shopify.app.toml, registers the
# mandatory privacy webhooks, and sets the URLs/scopes on app 382947852289.
shopify app config push
```

`shopify.app.toml` already points at `app.lets.co.il` with the correct
`access_scopes` (kept in EXACT sync with `SHOPIFY_OAUTH_SCOPES`), the redirect URL,
the webhook subscriptions (incl. the 3 mandatory GDPR topics), and the App Proxy
(`prefix = apps`, `subpath = payplus`).

## 2. Deploy the extensions

The two extensions under `extensions/` are auto-discovered (no `[[extensions]]`
block needed in `shopify.app.toml`):

- `extensions/lets-thank-you` — `ui_extension` (thank-you + order-status block).
  **PRIMARY** PayPlus token widget.
- `extensions/lets-post-purchase` — `checkout_post_purchase` interstitial.
  **SECONDARY** (native changeset gated behind Phase-0.5 / Shopify Payments).

```sh
shopify app deploy   # bundles + versions both extensions (needs Partner login)
```

## 3. Deploy the app to app.lets.co.il

Deploy the Laravel app (Railway: web + worker + scheduler) with the env above.
Ensure `APP_URL` == `SHOPIFY_APP_URL` == `https://app.lets.co.il` so OAuth
callbacks, webhooks, and App-Proxy signatures all resolve to the same host.

## 4. Install on a dev store

Open `https://app.lets.co.il/shopify/install?shop={your-dev-store}.myshopify.com`
(or install from the Partner dashboard). The OAuth flow:

1. validates the `shop` param, redirects to Shopify authorize,
2. on callback verifies HMAC + state, exchanges the code for an **offline** token,
3. stores it **encrypted** on the `shops` row, registers webhooks (idempotent),
   backfills the product cache, and redirects into the embedded admin.

---

## Verify (smoke test)

| Check | How |
|---|---|
| Config valid | `shopify app config push` succeeds; no scope/URL warnings. |
| Routes present | `php artisan route:list | grep -iE 'upsell|shopify|proxy'` shows `shopify/install`, `shopify/callback`, `shopify/webhooks`, `proxy/upsell/offer`, `upsell/accept-api`. |
| OAuth | Install on a dev store → lands in the embedded admin; `shops` row has an encrypted token. |
| Webhooks | A test order fires `orders/paid`; a bad HMAC ⇒ 401, empty secret in prod ⇒ 503. |
| Offer endpoint (extension seam) | A correctly App-Proxy-signed `GET /proxy/upsell/offer` returns the shop's offer (server-computed price) + a signed `accept_api_url`; an unsigned/forged request ⇒ 401. Covered by `tests/Feature/Upsell/ProxyOfferEndpointTest.php`. |
| Thank-you widget | On a dev-store checkout, the thank-you block shows the offer; **Accept** charges the saved PayPlus token (no card re-entry) and creates the linked child order; a double-tap ⇒ one charge. |
| Tests | `php artisan test` green (123 passing). |

---

## Notes & assumptions

- **Israeli PayPlus reality:** the **PRIMARY** post-purchase path is the
  `lets-thank-you` token widget (App-Proxy signed, charges the saved PayPlus
  token). The native post-purchase interstitial (`lets-post-purchase`) needs
  Shopify Payments and is gated behind Phase-0.5 capability verification.
- **Extension → app auth:** the thank-you/order-status widgets authenticate via the
  **App Proxy `signature`** (verified by `App\Http\Middleware\VerifyShopifyAppProxy`,
  fail-closed). The offer endpoint hands back a **signed** accept URL so the charge
  reuses the proven signed-link auth + idempotency. The **native post-purchase**
  extension does NOT get an App-Proxy signature — it carries a Shopify-signed input
  **token** (JWT) that the app must verify; that exact handshake is the documented
  remaining wiring (see `extensions/lets-post-purchase/README.md`).
- **Money safety unchanged:** the upsell amount is always recomputed server-side
  (`UpsellFlowOffer::discountedPrice`); the client never sends an amount. Consent +
  ledger + deterministic idempotency are enforced by the existing
  `UpsellChargeService` (untouched).
- **Quarterly API bump:** Shopify ships a new version each Jan/Apr/Jul/Oct. Bump
  `SHOPIFY_API_VERSION` + `shopify.app.toml [webhooks].api_version` + each
  `extensions/*/shopify.extension.toml` `api_version` in lockstep after reading the
  release notes and running the suite on a sandbox shop.
