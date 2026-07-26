# Shopify-Payments subscriptions — stage-1 runbook (single service, custom app)

> **Superseded decision (2026-07-26):** the original plan here was a second
> Partner app **plus a second Railway service + database**. The owner decided
> otherwise: **one codebase, one Railway service, one database.** What remains
> separate is only the Partner-app *record* (a custom-distribution app — the
> only way to install on a real store without App Store review, and the thing
> that protects the public LETS app's irreversible distribution choice).

## The model

- **Two Partner apps, one deployment.** `shopify.app.toml` = the public LETS
  app (untouched until App Store submission). `shopify.app.subscriptions.toml`
  = the custom stage-1 app real test stores install through. Both point at
  `https://app.lets.co.il`.
- **Per-shop identity.** `shops.shopify_app_key` records which app installed
  the shop; `App\Services\Shopify\ShopifyApps` resolves the right credentials
  for OAuth, token exchange, session tokens (`aud`), webhook HMAC and App
  Bridge. Custom-app creds live in the same env: `SHOPIFY_CUSTOM_API_KEY` /
  `SHOPIFY_CUSTOM_API_SECRET`.
- **Per-shop engine choice.** `shops.subscription_rail` — the merchant picks in
  **Settings → Billing** whether recurring subscriptions bill through
  **PayPlus** (we hold the token, we charge) or **Shopify Payments** (Shopify
  vaults the card; `shopify-subscriptions:dispatch-due` +
  `BillingAttemptJob` drive `subscriptionBillingAttemptCreate` each cycle).
  Installments + upsells stay PayPlus regardless.
- **Install links.** Public app: `/shopify/install?shop=…`. Custom app:
  `/shopify/install?shop=…&app=custom`.

## Owner checklist (the only manual steps)

1. **Create the custom Partner app** — Partner Dashboard → Apps → Create app →
   name e.g. `LETS Subscriptions`, distribution **Custom**
   (⚠️ irreversible *for that app record* — correct here, and exactly why it is
   not the public LETS app `382947852289`).
2. **Request the protected scopes the same day** (approval up to 7 business
   days, everything Shopify-Payments-rail is blocked on it). App → API access →
   protected customer data (App functionality):

   **Admin API**
   ```
   read_own_subscription_contracts, write_own_subscription_contracts,
   read_customer_payment_methods,
   read_products, write_products,
   read_orders, read_all_orders,
   read_checkout_external_data,
   read_customer_email, read_customer_name, read_customer_phone,
   read_customer_address, read_customer_personal_data
   ```

   **Customer Account API**
   ```
   customer_read_own_subscription_contracts,
   customer_write_own_subscription_contracts
   ```
3. **Hand over the client_id + secret** → client_id goes into
   `shopify.app.subscriptions.toml`; key + secret go into the Railway env as
   `SHOPIFY_CUSTOM_API_KEY` / `SHOPIFY_CUSTOM_API_SECRET`. No new service, no
   new DB — the existing deployment picks the identity up per shop.
4. **Keep other subscription apps off the test stores.**
   `write_own_subscription_contracts` sees only contracts OUR app created;
   another subscription app's contracts are invisible to us and vice versa.

## The one fact that still governs the rail

Shopify does **not** auto-bill subscription contracts. The app schedules and
calls `subscriptionBillingAttemptCreate`; Shopify processes the payment and
creates the order. Our scheduler only does this for shops whose merchant chose
the Shopify-Payments engine in Settings → Billing (`shops.subscription_rail`).

## Testing without moving money

Shopify Payments has a **test mode** on development stores: real contracts,
real billing attempts, test cards. Rehearse the full flow on a development
store first — selling plans → checkout → contract mirror → due-cycle scan →
billing attempt → webhook resolution → personal area.

## Deploy notes

- Migrations for the rail (`subscription_contracts`, `shops.shopify_app_key`,
  `shops.subscription_rail`) now ship with the main branch — merging to `main`
  deploys them to the one production service, deliberately.
- Extensions (`lets-subscriptions-account`) deploy with
  `shopify app deploy --config subscriptions` (they belong to the custom app).
