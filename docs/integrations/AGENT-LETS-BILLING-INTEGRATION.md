# agent.lets.co.il × LETS billing: integration spec

> **For:** the engineer or AI agent working on the **agent.lets.co.il** codebase.
> **Goal:** agent.lets.co.il keeps owning users and accounts. **LETS** (the payments
> platform at `app.lets.co.il`, selling through the WooCommerce store at
> `lets.co.il`) owns checkout and recurring billing. Your job is to:
> 1. send users to the LETS checkout carrying a reference to their account, and
> 2. receive LETS's signed webhooks and mirror each subscription's status onto the
>    account (grant, suspend or revoke access).
>
> Do **not** charge cards, call PayPlus, or store card data in agent.lets.co.il.
> LETS does all of that.

---

## 1. The flow

```
agent.lets.co.il                    lets.co.il (WooCommerce + LETS)                 LETS platform
────────────────                    ───────────────────────────────                 ─────────────
User clicks "Upgrade to Pro"
  → build checkout URL with
    lets_ref=<signed account ref> ─► checkout page (PayPlus card form)
                                     user pays ─────────────────────────────────►  subscription created
                                                                                   (client_reference
                                                                                    = your lets_ref)
                                                                                         │
POST /webhooks/lets  ◄──────────────── signed webhook (subscription.activated) ─────────┘
  verify signature
  dedupe by event_id
  verify lets_ref → account
  grant plan access
                                                                    every month: recurring charge
POST /webhooks/lets  ◄──────────────── recurring_charge.succeeded / .failed ────────────┘
POST /webhooks/lets  ◄──────────────── subscription.status_changed (paused/cancelled…) ─┘
```

User creation and login stay entirely in agent.lets.co.il. LETS never creates
users in your app. It only tells you about payments and subscription status.

---

## 2. One-time setup (done in the LETS admin, not in code)

1. **Products.** Each paid plan in agent.lets.co.il needs a WooCommerce product on
   `lets.co.il` with an **active** (not draft) LETS subscription template (LETS
   admin → Products → the product → subscription plan). You get one
   **WooCommerce product ID per plan**. Keep that mapping in config (see §3.1).
2. **Webhook endpoint.** In LETS admin (the `Lets` store) → **Settings → Webhooks →
   Add endpoint**:
   - URL: `https://agent.lets.co.il/webhooks/lets` (must be public **https** on
     port 443; private, internal and IP-literal addresses are refused).
   - Topics: tick **subscription.activated**, **subscription.status_changed**,
     **recurring_charge.succeeded**, **recurring_charge.failed**. `order.paid` is
     optional.
   - Copy the signing secret (`whsec_…`) into agent.lets.co.il's environment as
     `LETS_WEBHOOK_SECRET`. It can be rotated from the same screen; support two
     secrets during a rotation if you need zero downtime.
3. The same screen has a **"Send test"** button and a delivery log, showing per
   request whether your endpoint returned 2xx.

The store must run LETS WordPress plugin **0.66.0 or later**, which carries
`lets_ref` through checkout.

---

## 3. Sending the user to checkout

### 3.1 Config

```env
LETS_STORE_URL=https://lets.co.il
LETS_WEBHOOK_SECRET=whsec_...           # from LETS → Settings → Webhooks
LETS_REF_SECRET=<random 32+ bytes>      # yours only; never shared with LETS
LETS_PRODUCT_STARTER=<woo product id>
LETS_PRODUCT_PRO=<woo product id>
```

### 3.2 The reference (`lets_ref`)

`lets_ref` is **your** identifier for the account, passed through LETS untouched
and handed back in every webhook as `data.subscription.client_reference`.

- **Allowed characters:** `A–Z a–z 0–9 . _ : ~ -`. **Max length:** 200. Anything
  else is silently dropped by LETS (the subscription then has no reference).
- **Anyone can type a URL, so sign it**, and verify the signature when it comes
  back. Recommended format:

```
lets_ref = <account_id> "." <base64url( HMAC-SHA256(key=LETS_REF_SECRET, msg=account_id) )[0:32]>
example:   acc_8f3k2.Q2x8nV0mZk1aT3pYb2xLc0hZ
```

The `=` padding must not appear; base64url without padding uses only allowed
characters. If you need it, add a version prefix (`v1:acc_8f3k2.sig`; `:` is
allowed).

### 3.3 The URL

Link straight to checkout so the page is not served from a page cache:

```
{LETS_STORE_URL}/checkout/?add-to-cart={product_id}&lets_ref={lets_ref}
```

- The store keeps `lets_ref` in a first-party cookie (up to 7 days, cleared once it
  is written on an order), writes it on the WooCommerce order (`_lets_client_ref`),
  and sends it with the payment.
- Ask the user to pay with the **same email** as their account. Email is your
  fallback match (§5).
- After payment, WooCommerce shows its thank-you page on `lets.co.il`. To bring
  the user back, put a "Back to Agent" link there, or poll your own backend for the
  activation webhook from an "almost done" page.

---

## 4. Receiving webhooks

### 4.1 The request

`POST` to your URL, `Content-Type: application/json`, body = the event (§4.4).

| Header | Meaning |
|---|---|
| `X-Lets-Signature` | `t=<unix seconds>,v1=<hex HMAC-SHA256>` |
| `X-Lets-Topic` | e.g. `subscription.activated` |
| `X-Lets-Event-Id` | stable id of the event (same on every retry), e.g. `evt_3f…` |
| `X-Lets-Delivery-Id` | LETS's delivery row id (informational) |
| `X-Lets-Test` | `true` for events sent with the admin "Send test" button |
| `User-Agent` | `LETS-Webhooks/1.0` |

### 4.2 Verify the signature (required)

```
signed_payload = t + "." + RAW_REQUEST_BODY        // the exact bytes, before JSON parsing
expected       = hex( HMAC-SHA256(key = LETS_WEBHOOK_SECRET, msg = signed_payload) )
accept if  constant_time_equals(expected, v1)  AND  |now - t| <= 300 seconds
```

Reject with `401` if verification fails. Never parse or act on an unverified body.

Node/Express sketch (use the raw body):

```js
app.post('/webhooks/lets', express.raw({ type: 'application/json' }), (req, res) => {
  const header = req.get('X-Lets-Signature') || '';
  const parts = Object.fromEntries(header.split(',').map(p => p.split('=')));
  const expected = crypto.createHmac('sha256', process.env.LETS_WEBHOOK_SECRET)
    .update(`${parts.t}.${req.body}`).digest('hex');
  const fresh = Math.abs(Date.now() / 1000 - Number(parts.t)) <= 300;
  const a = Buffer.from(expected), b = Buffer.from(parts.v1 || '');
  // timingSafeEqual throws on different lengths: check first, so a malformed header is a 401, not a 500.
  if (!fresh || a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return res.sendStatus(401);
  }
  const event = JSON.parse(req.body.toString('utf8'));
  enqueue(event);          // process async
  res.sendStatus(200);     // answer fast
});
```

PHP/Laravel sketch:

```php
$raw = $request->getContent();
parse_str(str_replace(',', '&', $request->header('X-Lets-Signature', '')), $sig);
$expected = hash_hmac('sha256', ($sig['t'] ?? '').'.'.$raw, config('services.lets.webhook_secret'));
abort_unless(isset($sig['v1']) && hash_equals($expected, $sig['v1'])
    && abs(time() - (int) $sig['t']) <= 300, 401);
$event = json_decode($raw, true);
```

### 4.3 Delivery rules

- **Answer 2xx within 10 seconds**, or the attempt counts as failed. Queue the
  work and return `200` straight away.
- Redirects are **not** followed. Answer the URL directly.
- A failed attempt (non-2xx, timeout, unreachable) is retried after **1 min, 5 min,
  30 min, 2 h and 6 h** (6 attempts, about 8.5 hours in total). After that LETS stops,
  and the delivery shows as failed in the LETS log. The merchant can **resend** a
  delivery from that log, but don't count on someone noticing: keep the endpoint up.
- **The body is built once and frozen.** On the first attempt LETS builds the
  event and stores it. Every retry, and a manual resend, repeats **exactly the same
  body** with the same `event_id`; only the signature's `t` changes. So
  `created_at` is when the event was first built, and `data.subscription` is the
  subscription **as it was at that moment**, not as it is when a late retry
  reaches you.
- **Idempotency:** the same event can arrive more than once. Store `event_id` with
  a unique index and skip events you have already processed.
- **Ordering is not guaranteed, and the `last_event_at` check is REQUIRED.** A
  retried event built hours ago can arrive after a newer one. Without the check,
  an old `paused` would roll back a newer `active`. Keep `last_event_at` (the
  newest envelope `created_at` you applied) per subscription, and never let an
  event with an older `created_at` change the stored status (§5.4).
- `test: true` events (the "Send test" button) carry example or real-looking
  data. Verify and log them, but **never change a real account** because of one.

### 4.4 The event body

Every topic has the **same shape**. `data` always has the same five keys, which
are `null` when they don't apply.

```json
{
  "event_id": "evt_6a1f0c9b2d3e4f5a6b7c8d9e0f1a2b3c",
  "topic": "subscription.activated",
  "api_version": "2026-10-14",
  "created_at": "2026-11-19T13:25:07+00:00",
  "test": false,
  "shop": { "id": 4, "name": "Lets", "domain": "lets.co.il", "platform": "woocommerce" },
  "data": {
    "charge": null,
    "order": { "id": "722", "currency": "USD", "...": "other order keys, may be null" },
    "customer": {
      "name": "Dana Levi",
      "email": "dana@example.com",
      "phone": "0501234567",
      "external_id": "0"
    },
    "subscription": {
      "id": 1471,
      "public_id": "01M75HK4CH343AAGHZYBZFGB4V",
      "kind": "recurring",
      "status": "active",
      "product_title": "Starter plan",
      "product_id": "2675",
      "amount": 99.0,
      "frequency": "monthly",
      "interval_count": 1,
      "next_charge_at": "2026-12-19T00:00:00+00:00",
      "started_at": "2026-11-19T13:20:00+00:00",
      "total_amount": 0.0,
      "total_charged": 99.0,
      "remaining_amount": null,
      "client_reference": "acc_8f3k2.Q2x8nV0mZk1aT3pYb2xLc0hZ"
    },
    "change": {
      "previous_status": "awaiting_first_payment",
      "status": "active",
      "action": null,
      "changed_at": "2026-11-19T13:25:07+00:00"
    }
  }
}
```

**`data.subscription.id`** is LETS's stable subscription id. Use it as your
foreign key (`lets_subscription_id`, unique). `public_id` is also stable.

#### Topics

| Topic | When | `charge` | `change` |
|---|---|---|---|
| `subscription.activated` | The subscription became active **for the first time**: usually its first payment landed, but also an activation by the merchant, or the new subscription a **plan switch** creates (§5.3). Sent **once per subscription id, ever**. | `null` | the move into `active` |
| `subscription.status_changed` | **Every** status move, including the first activation (so you also get a `status_changed` alongside `activated`). | `null` | `previous_status`, `status`, optional `action` (e.g. `cancelled`) |
| `recurring_charge.succeeded` | A renewal was charged. | filled | `null` |
| `recurring_charge.failed` | A renewal was declined. `charge.will_retry` and `charge.next_retry_at` say whether and when LETS will try again. | filled | `null` |
| `order.paid` | Any paid order on the store (optional; includes non-subscription sales). | filled | `null` |

`charge` block (on charge topics):

```json
{
  "id": 2198, "status": "succeeded", "context": "recurring", "amount": 99.0, "currency": "USD",
  "gateway": "payplus", "transaction_uid": "…", "idempotency_key": "…",
  "attempt": null, "failure_code": null, "failure_message": null,
  "will_retry": false, "next_retry_at": null, "charged_at": "2026-12-19T00:00:05+00:00"
}
```

On `recurring_charge.failed`: `status` is `failed`, `failure_message` holds the
gateway's text (often Hebrew), and `will_retry`/`next_retry_at` are set.

---

## 5. What to do with each event

### 5.1 Find the account

In this order:
1. `data.subscription.client_reference` → split at the last `.`, recompute the
   HMAC with `LETS_REF_SECRET`, and compare in constant time. If it matches, use
   that account.
2. If it's missing or invalid, match `data.customer.email` (case-insensitive) to
   **exactly one** account.
3. Otherwise store the event in an **unmatched** queue for a human, and still
   answer `200` (a non-2xx would only make LETS retry the same unmatched event).

Once matched, store `lets_subscription_id ↔ account_id`. Later events for that
subscription match by `subscription.id` directly.

**Cross-check the email.** A valid reference proves YOU minted the link, not who
paid: a link can be forwarded, and a shared computer can carry an earlier
visitor's reference. If the referenced account's email differs from
`data.customer.email`, grant nothing silently. Put it in the review queue, or ask
the account owner to confirm.

### 5.2 Status → access

| `subscription.status` | Meaning in LETS | Recommended access |
|---|---|---|
| `awaiting_first_payment` | created at checkout, not paid yet | none |
| `active` | paid and renewing | **grant** the plan for `product_id` |
| `paused` | paused by the customer or merchant, **or** held after card failures | suspend (keep data) |
| `failed` | collection stopped after the retry ladder; owes a cycle | suspend; show "update your card" |
| `cancelled` | ended | revoke (optionally at end of the paid period, i.e. the last `next_charge_at` you stored before the cancel) |
| `completed` | a fixed-length plan finished (rare for recurring) | revoke |
| `awaiting_activation`, `draft` | not live | none |

On `recurring_charge.failed` with `will_retry: true`, **don't revoke**: show a
banner ("payment failed, we'll retry on {next_retry_at}"). Act on the status only
when a `subscription.status_changed` arrives (to `paused`/`failed`/`cancelled`).

On `recurring_charge.succeeded`, extend the paid-until date to
`subscription.next_charge_at` and clear any payment-failed banner.

Plan changes: if `subscription.product_id` differs from what you stored, switch
the account's plan to the one mapped from that product ID.

### 5.3 Compute access per ACCOUNT, not per subscription

When a customer **switches plan** in their LETS account area, LETS creates a **new
subscription** (a new `subscription.id`, which sends `subscription.activated`
without a new payment) and moves the old one to `cancelled`. The new one carries
the same `client_reference`. The two events can arrive in either order. So the
account's access is "the best plan among its subscriptions that are `active`", recomputed
after every event, never "the status of the last event received". A cancelled
old subscription must not revoke access the new active one grants.

### 5.4 Suggested tables

```
lets_webhook_events(
  event_id PK, topic, received_at, processed_at NULL, test BOOL,
  payload JSON, error TEXT NULL)

billing_subscriptions(
  id PK, account_id FK NULL, lets_subscription_id UNIQUE, lets_public_id,
  product_id, plan_key, status, amount, currency, frequency,
  next_charge_at NULL, started_at NULL, client_reference NULL,
  last_event_at,               -- envelope created_at of the newest event applied
  payment_failed_at NULL, next_retry_at NULL)
```

Applying an event: take a row lock on the `billing_subscriptions` row. If the
event's `created_at` is older than `last_event_at`, record it but don't change
status. Otherwise upsert all `subscription.*` fields and recompute access. All of
this goes in one transaction with marking the event processed.

---

## 6. Card updates, cancellation, receipts

These all live on the **LETS customer account area** on `lets.co.il` (the "My
account" page, LETS section), where the customer can update the card, pause or
cancel, depending on the store's settings. From agent.lets.co.il's billing page,
link to `https://lets.co.il/my-account/`. Any change made there comes back to
you as webhooks.

LETS sends its own customer emails (welcome, renewal reminder, failed charge,
cancellation), as configured in the LETS admin. Don't duplicate them unless you
want to.

---

## 7. Acceptance checklist

- [ ] Signature verified on the raw body with a 300-second tolerance. A wrong
      secret gets 401.
- [ ] `event_id` unique; a replayed event changes nothing.
- [ ] "Send test" from LETS → 200, logged, no account changed.
- [ ] Real purchase via `/checkout/?add-to-cart=…&lets_ref=…`:
      `subscription.activated` arrives with your `client_reference`, the
      reference verifies, and the plan is granted.
- [ ] A reference with a wrong signature is **not** trusted (falls back to email
      or the unmatched queue).
- [ ] `recurring_charge.failed` (`will_retry: true`) shows a banner and access
      stays.
- [ ] `subscription.status_changed` → `cancelled` revokes access as designed.
- [ ] An older event arriving after a newer one does not roll the status back.
- [ ] The endpoint answers in under 10 seconds even when processing is slow
      (queued).

---

## 8. Known limits

- Only **LETS PayPlus subscriptions** (products with an active LETS template)
  produce `subscription.*` events. A product sold through any other subscription
  plugin on the store produces at most `order.paid`.
- No pull API yet: webhooks are the only channel, and delivery stops after about
  8.5 hours of failures. Ask the LETS side for a read endpoint if you need
  periodic reconciliation.
- `client_reference` is attached when the checkout **starts a LETS subscription**
  (and is carried to the new subscription of a plan switch). Subscriptions created by other means
  (imports, admin-created) have none, so match them by email.
- A subscription **created already active** (imported, or added by hand in the
  LETS admin) sends no `activated`/`status_changed` for its creation. You first
  hear of it at its first renewal (`recurring_charge.*`) or its first status
  move. Treat an unknown `subscription.id` on any topic as new.
