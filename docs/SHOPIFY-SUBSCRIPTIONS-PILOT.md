# Shopify-Payments subscriptions pilot — Phase 0 runbook

The pilot is one client store (not Israeli, running Shopify Payments) that wants
subscriptions, plus an expanded **customer personal area** to show them. The full
design is in the approved plan; this file is only the part **you** must do, and
which nothing else can start without.

Two of the decisions below are **irreversible**. They are marked.

---

## Why this is a separate app and not a module

Shopify's subscription scopes are requested per app and shown to every merchant at
install. Adding them to the public LETS app would ask Israeli PayPlus merchants —
who will never use them — to grant subscription-contract and payment-method
access, and [shopify.app.toml](../shopify.app.toml) already warns that App Store
reviewers check for unused scopes. So: a second Partner app, and the public
listing is not touched.

---

## Step 1 — pin production to `main` (do this FIRST)

Railway deploys on push to a tracked branch, and this project runs migrations on
deploy. Until production is pinned, a push to any branch Railway watches could
redeploy the live app and apply the pilot's migrations to the production database.

In Railway → the **production service** → Settings → Source:

- **Branch: `main`**, and nothing else.

I have created the `shopify-subscriptions` branch **locally only** and will not
push it until you confirm this is done.

## Step 2 — create Partner app B

Partner Dashboard → Apps → **Create app**.

| Setting | Value | |
| --- | --- | --- |
| Name | e.g. `LETS Subscriptions` | |
| Distribution | **Custom** | ⚠️ **IRREVERSIBLE** — a custom app can never become a public App Store listing. This is correct for a pilot, and it is also why it must not be the public LETS app. |
| Install target | the one client store | Multi-store only within a single Plus organization. |

Do **not** touch app `382947852289` (the public LETS app) at any point.

## Step 3 — request the scopes (this is the schedule)

Approval takes **up to 7 business days** and every later phase is blocked on it,
so request on day one. In app B → API access → request protected customer data,
selecting **App functionality**.

**Admin API**
```
read_own_subscription_contracts, write_own_subscription_contracts,
read_customer_payment_methods,
read_products, write_products,          # selling plan groups
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

---

## The one fact that governs the whole pilot

`write_own_subscription_contracts` means **only contracts your app created**.
Contracts made by any other app — including Shopify's own Subscriptions app — are
invisible to yours, on the Admin API *and* the Customer Account API.

The client's store has **no subscriptions today**, which is exactly why this
works: our app creates the selling plans, therefore owns the contracts, therefore
may read and manage them, therefore the personal-area extension has something to
render.

**If that changes — if anyone installs another subscription app on that store
before we ship — the pilot becomes impossible, not merely harder.** Please make
sure nobody does.

---

## What happens after approval

I take over from here:

1. Second Railway service tracking `shopify-subscriptions`, its own Postgres, its
   own env (`SHOPIFY_API_KEY` = app B). `config/shopify.php` is never edited.
2. `shopify.app.subscriptions.toml` (Shopify CLI `--config`).
3. Selling plans → a real checkout produces a contract we own.
4. Contract mirror + subscription webhooks.
5. Due-cycle scanner + billing attempts. **Shopify does not auto-bill** — the app
   schedules and calls `subscriptionBillingAttemptCreate`; Shopify processes the
   payment and creates the order.
6. The `customer-account.page.render` extension — the demo itself.

## Testing without moving money

Shopify Payments has a **test mode** on development stores: real contracts, real
billing attempts, test cards. Rehearse the whole flow on a development store
first — do not rehearse on the client's live store.

## The gate before any of this is called done

- `git diff main..shopify-subscriptions -- shopify.app.toml config/shopify.php`
  is **empty**.
- The production Railway service shows **no deployment** for the pilot's duration.
- The production database has **no new tables**.
