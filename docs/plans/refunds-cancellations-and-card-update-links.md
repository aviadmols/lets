# Refunds, cancellations & card-update links — implementation plan

> Status: **R1–R3 + C1–C2 SHIPPED** (2026-09-09) — see
> [docs/reviews/phase-refunds.md](../reviews/phase-refunds.md). R4, R5 and C3 remain.
> Originally proposed 2026-09-09. Owner: `recharge-orchestrator`. Specialists:
> `laravel-backend` (money / documents / orchestration), `shopify-integration` +
> `woocommerce-integration` (store legs), `product-ux-architect` (drawer spec),
> `admin-design-system` (drawer UI), `shopify-app-release` (scopes / webhooks),
> `code-review-gatekeeper` (every gate). The locked laws in [CLAUDE.md](../../CLAUDE.md)
> and [ARCHITECTURE.md](../../ARCHITECTURE.md) apply unchanged.

## 0. TL;DR

Two merchant asks, one plan:

1. **Refund or cancel an order / a subscription from the LETS admin** — full or
   partial, choose "cancel the whole order" vs "refund some money", choose whether
   the goods go back to stock — and have the **money**, the **store order**
   (WooCommerce *and* Shopify) and the **credit note** (per the merchant's invoicing
   policy) all follow from one click.
2. **Send a customer a link to update their card**, from the admin: generate it,
   copy it, and later send it by **email** or **SMS**.

Most of the money and document machinery exists. What is missing is the
**store leg** (refund / cancel / restock on the platform), the **orchestration**
that ties the three legs together behind one audit row, a **choice-rich admin
drawer**, and a **durable, revocable card-update link** the merchant can hand out.

---

## 1. What already exists (reuse, don't reinvent)

| Piece | Where | State |
|---|---|---|
| PayPlus refund, **partial supported**, idempotent key, ledger `succeeded → refunded`, Timeline, credit note queued | [RefundService.php](../../app/Domain/Lifecycle/RefundService.php) | built |
| "Refund the whole order" = every succeeded charge on one order (checkout + upsell) | [OrderRefundService.php](../../app/Domain/Lifecycle/OrderRefundService.php) | built |
| Credit note (Green Invoice 330) through the **central DocumentPolicy**; contexts `REFUND` / `CANCELLATION`; keyed by amount (partials OK); linked to the sale document by ledger id, falling back to the order id | [DocumentIssuer.php](../../app/Domain/Invoicing/DocumentIssuer.php) `issueForLedger()` / `linkedDocumentIdFor()`, [DefaultDocumentPolicy.php](../../app/Domain/Billing/DefaultDocumentPolicy.php), per-context type map in [MerchantInvoicingSettings.php](../../app/Models/MerchantInvoicingSettings.php) | built |
| Refund idempotency key `refund(shop, ledger, alreadyRefunded, amount)` | [IdempotencyKey.php](../../app/Domain/Billing/IdempotencyKey.php) | built |
| Admin refund buttons — full amount only, no store sync, no restock choice | [ViewPayment.php](../../app/Filament/Resources/PaymentLedgerResource/Pages/ViewPayment.php), [PaymentLedgerResource.php](../../app/Filament/Resources/PaymentLedgerResource.php) | built — **replaced by the drawer** |
| Plan cancel (guarded transition, clock stopped, cancellation email). **Does not refund, does not touch the store order** | [SubscriptionLifecycleService.php](../../app/Domain/Lifecycle/SubscriptionLifecycleService.php) `cancel()` | built |
| Shopify `orders/cancelled` webhook cancels the plan (inbound) | [OrderCancelledHandler.php](../../app/Services/Shopify/Webhooks/OrderCancelledHandler.php) | built |
| Shopify client: REST `cancelOrder`, `graphql()`, order tags / metafields | [ShopifyAdminClient.php](../../app/Services/Shopify/ShopifyAdminClient.php) | built — **no `refundCreate`** |
| Woo client: `fetchOrder`, `updateOrder`, `addOrderNote` | [WooCommerceClient.php](../../app/Services/WooCommerce/WooCommerceClient.php) | built — **no `createRefund`** |
| Woo gateway sales get a `gateway` ledger row carrying the PayPlus transaction uid (refundable through PayPlus) | [WooGatewayFinalizer.php](../../app/Services/WooCommerce/Orders/WooGatewayFinalizer.php) | built |
| WP plugin gateway | [class-lets-gateway.php](../../plugins/lets-payplus-woocommerce/includes/class-lets-gateway.php) | built — **no `supports('refunds')` / `process_refund`** |
| Card update on the PayPlus rail: mint a PayPlus hosted **re-vault** page; the callback vaults the token and re-points the plan + siblings | [CardUpdateService.php](../../app/Domain/Installments/CardUpdateService.php), [WooCardUpdateCallbackController.php](../../app/Http/Controllers/WooCommerce/Storefront/WooCardUpdateCallbackController.php) | built — **Woo-only** (`wc_shop_token` callback route), customer-area only |
| Shopify-Payments contracts: native "send card update email" | [ContractActionService.php](../../app/Domain/ShopifySubscriptions/ContractActionService.php) `sendCardUpdateEmail()` | built |
| Durable, hashed, revocable, windowed customer link — the pattern to copy | [CustomerLoginToken.php](../../app/Domain/Campaigns/Email/Models/CustomerLoginToken.php) | built |
| SMS channel (019), default-off per shop | [SmsSenderFactory.php](../../app/Services/Sms/SmsSenderFactory.php), [MerchantSmsSettings.php](../../app/Models/MerchantSmsSettings.php) | built |
| Per-shop mail ladder + strtr templates | `CampaignMailer`, `TemplateRenderer`, `DefaultEmailTemplates` | built |

The gaps, one line each: no store-side refund / cancel / restock; no
"cancel the whole thing" verb spanning plan + money + store + paperwork; no
partial-amount UI; no audit row for a refund *request* (only per-ledger outcomes);
no non-PayPlus money rail (Shopify Payments, other Woo gateways); no inbound
refund reconciliation; the card-update link is not durable, not shareable, not
sendable, and Woo-only.

---

## 2. Product scope — what the merchant sees

### 2.1 The Refund / Cancel drawer

One drawer, opened from three places, always about **one order**:

- **Payments → payment** ([ViewPayment](../../app/Filament/Resources/PaymentLedgerResource/Pages/ViewPayment.php)) — the primary home; replaces the two existing buttons.
- **Subscriptions → plan** (ViewSubscription) — "Refund a charge…" lists the plan's succeeded charges (deposit / installment / cycle) to pick one, then opens the same drawer; "Cancel & refund" preselects *cancel the order*.
- **Customer detail** — the customer's orders, same drawer per row.

Drawer contents (spec by `product-ux-architect` in `docs/ux/35-refunds.md`):

1. **What to do** (radio):
   - **Cancel the order** — refund everything still refundable, cancel the store
     order, stop the plan (if any), restock by default. Document context `CANCELLATION`.
   - **Refund** — the order stays. **Full** (everything still refundable) or
     **Partial** (an amount, or per-line quantities where the platform knows the lines).
     Document context `REFUND`.
2. **Return items to stock** (toggle; default ON for *cancel*, OFF for a
   money-only partial; per line when lines are chosen).
3. **Reason** (short text → the store order note, the Timeline, the credit note's
   remarks) + **Notify the customer** (the store's own email on Shopify / Woo, plus
   the existing `PlanCancelledMail` on cancel).
4. **Summary before confirm**: how much money moves and **through which rail**
   (PayPlus / Shopify Payments / the store's gateway / none), what the store will
   show, which document will be issued (type from the merchant's per-context map,
   or "none — invoicing off"), and what happens to the subscription.
5. **Result**: one line per leg with ✓ / ✗, and a **Retry store sync** button
   when the store leg failed after the money moved.

All strings i18n (`lang/{en,he}/refunds.php`), RTL-aware, token-only CSS.

### 2.2 The card-update link

On **ViewSubscription** (PayPlus-rail plans, both platforms) and **Customer
detail**: **"Send a card-update link"** →

- **Generate & copy** — a durable LETS link (`https://app.lets.co.il/c/{token}`),
  valid 7 days by default (merchant picks 1–30), revealed once with a copy button.
- **Email** — a merchant-editable template (`{card_update_url}`, `{customer_name}`,
  `{business_name}`, `{card_last_four}`, `{expires_at}`).
- **SMS** — same text through the shop's 019 account; disabled with a hint when
  `MerchantSmsSettings` is off.
- Status line: *sent 2h ago · opened · card updated ✓* (from the link row +
  `KIND_CARD_UPDATED`), and **Revoke**.

Shopify-Payments contracts keep the native Shopify email (already built); the
new action is hidden there.

---

## 3. Domain design — refunds

### 3.1 The request is the unit — `refund_requests`

A refund is several legs that fail independently; the merchant needs ONE row that
says what was asked and how far it got. New tenant-owned model
`App\Domain\Refunds\Models\RefundRequest` (`BelongsToShop`):

```
id, shop_id, platform, external_order_id, plan_id?, requested_by (user id | system | store)
mode            : cancel_order | refund_full | refund_partial
amount, currency: the requested amount
lines           : json? [{line_id, quantity, amount}] — when the merchant chose items
restock         : bool
reason          : string?
notify          : bool
status          : pending → money_done → store_done → completed | failed | needs_attention
money_rail      : payplus | shopify_native | woo_gateway | external | none
money_result    : json (per-ledger outcomes, refund transaction uids)
store_result    : json (platform refund id / cancel result / restock / error)
doc_result      : json (issued_documents ids, or "off")
idempotency_key : unique (shop_id, key) — sha256 of (order, mode, amount, lines, restock)
created_at, completed_at
```

Rules: one row per click, written **before** any leg runs; an identical second
click returns the existing row (the key). `status` is guarded and moves only
through the orchestrator. Every leg writes a Timeline event: new
`KIND_REFUND_REQUESTED`, existing `KIND_REFUNDED`, new
`KIND_ORDER_CANCELLED_BY_MERCHANT`, `KIND_STORE_REFUND_SYNCED`,
`KIND_STORE_REFUND_SYNC_FAILED`, `KIND_RESTOCKED`.

### 3.2 Leg order — money, then store, then paperwork

`App\Domain\Refunds\RefundOrchestrator::run(RefundRequest)`:

1. **Resolve** the order's charges and rail (`RefundTargetResolver`, §3.3).
2. **Money** — the point of no return, run *outside* any DB transaction exactly
   as `RefundService` does today. Per-charge outcomes go on the row. Partial
   failure is recorded, never hidden, never rolled back.
3. **Store** (`StoreRefunder` per platform, §3.4): record the refund on the order,
   restock, cancel when `cancel_order`. A store failure **after** the money moved
   → `needs_attention` + **Retry store sync** (idempotent: the store leg checks
   its own marker before writing again).
4. **Plan** (`cancel_order` only): `SubscriptionLifecycleService::cancel()` and
   release the fulfillment hold ([FulfillmentLockService](../../app/Services/Shopify/Orders/FulfillmentLockService.php))
   on deposit plans. A deposit plan whose deposit was fully refunded is cancelled
   even under `refund_full` — a plan with no money in it is not a plan.
5. **Documents** — already queued per charge by `RefundService` (`REFUND`). For
   `cancel_order` the orchestrator passes `CANCELLATION` (a new optional argument
   on `RefundService::refund()`); `DocumentPolicy` still decides the type. For
   **ledger-less** orders (rails 2–3) a new
   `DocumentIssuer::issueCreditForOrder(shopId, orderId, amount, context)` keys on
   `(order, amount)` and links through `documentIdForOrder()` — same wall: a row
   before the call; an unknown outcome becomes `unresolved`.

The run is **queued** (`RunRefundRequestJob`, `shop_id` explicit, `ShouldBeUnique`
on the request id) so the drawer never holds an HTTP thread across three external
APIs; the result panel polls the row (`wire:poll`).

### 3.3 Money rails — who actually gives the money back

`RefundTargetResolver` answers per order:

| Rail | How we know | Money leg | Document leg |
|---|---|---|---|
| **1. PayPlus** — every LETS charge: deposit, installment, recurring cycle, upsell, account offer, Woo gateway sale | `payment_ledger` rows with `payplus_transaction_uid` for the order (`OrderRefundService::chargesFor`) | existing `RefundService` per charge; a partial amount is spread **newest charge first** (the cycle the customer is unhappy about) unless the merchant picked a specific charge | existing, per charge |
| **2. Shopify-native** — Shopify-Payments contracts' cycle orders; a Shopify order the app never charged | no ledger row; the order has Shopify transactions | Shopify `refundCreate` **with** `transactions[]` → Shopify refunds through its gateway | only if a LETS document exists for that order (none today for contracts) — else "no document to credit", said honestly |
| **3. Woo, other gateway** — `all_orders`-scope orders paid by PayPal / other | no ledger row; `payment_method != lets_payplus` | WC `POST /orders/{id}/refunds` with `api_refund: true` → WooCommerce asks *its* gateway | `issueCreditForOrder()` linked to the platform-order document |
| **4. None** — `cancel_order` on an order with nothing paid (awaiting first payment) | no succeeded charges | skip | skip |

Mixed orders (a LETS plan line + other lines paid elsewhere) get amount-based
partial only in v1; the drawer says why.

### 3.4 Store legs — `StoreRefunder` (a platform seam, like `PlatformOrderStrategy`)

```php
interface StoreRefunder {
    /** Record the refund on the order and restock what was chosen. */
    public function refund(Shop $shop, RefundRequest $request, StoreRefundPlan $plan): StoreRefundResult;
    /** Cancel the order (after the refund record) and restock. */
    public function cancel(Shop $shop, RefundRequest $request, StoreRefundPlan $plan): StoreRefundResult;
    /** The idempotency marker: has this request already been applied on the store? */
    public function alreadyApplied(Shop $shop, RefundRequest $request): bool;
}
```

**Shopify** — `ShopifyStoreRefunder` (GraphQL Admin):
- `refundCreate(input: { orderId, note, notify, refundLineItems: [{lineItemId, quantity, restockType: RETURN | CANCEL | NO_RESTOCK, locationId}], transactions: [{orderId, gateway, kind: REFUND, amount, parentId}] })`.
  - Rail-1 orders were marked paid with the **manual** gateway
    ([ShopifyOrderCreator](../../app/Services/Shopify/Orders/ShopifyOrderCreator.php),
    `markOrderAsPaid`): pass a manual REFUND transaction so the order reads
    `refunded` / `partially_refunded` **without** Shopify trying to move money —
    PayPlus already did.
  - Rail 2: pass the real sale transaction as `parentId` → Shopify Payments refunds.
- `cancel_order`: `orderCancel(orderId, reason, refund: <rail 2 only>, restock, notifyCustomer, staffNote)`
  (GraphQL 2024-04+; the existing REST `cancelOrder` stays as the fallback).
- Restock needs a `locationId`: the order's fulfillment-order location, else the
  shop's primary location (`locations(first: 1)`), cached per shop.
- Idempotency marker: order metafield `lets.refund_request_{id} = done`
  (`upsertOrderMetafield` exists).
- Scopes: `write_orders` covers refund + cancel. Whether restocking through
  `refundCreate` needs `write_inventory` **must be verified on a dev store** —
  add it only if refused (`shopify-app-release` updates the toml + the scope↔feature table).

**WooCommerce** — `WooStoreRefunder` (WC REST v3 through `WooCommerceClient`):
- `POST /orders/{id}/refunds` `{ amount, reason, line_items: [{id, quantity, refund_total}], api_refund: <rail 3 only>, restock_items: <restock> }`
  — WooCommerce writes the refund, restocks, and flips the order to `refunded`
  when the total is reached.
- `cancel_order`: `PUT /orders/{id}` `{status: 'cancelled'}` (WC restocks on
  cancel by itself when stock was reduced) **after** the refund record.
- New client methods `createRefund()`, `fetchRefunds()`; marker: order meta
  `_lets_refund_request_{id}` via `updateOrder(meta_data)`.
- **WP-admin parity** (phase R5): the plugin gateway declares
  `$this->supports[] = 'refunds'` and implements `process_refund($order_id, $amount, $reason)`
  → `POST /api/woocommerce/orders/{id}/refund` (HMAC-signed like every plugin
  call) → the **same orchestrator**, with `store_result` pre-marked because
  WooCommerce is doing its own store leg. One money path, two admins.

### 3.5 Inbound reconciliation — the store did it first

- **Shopify** `refunds/create` (in the toml, commented out pending
  protected-data approval): `RefundWebhookHandler` → if the refund names a rail-1
  order whose ledger still says `succeeded`, **do not move money again** —
  Shopify only recorded a manual refund. The handler opens a
  `RefundRequest(mode: refund_partial, requested_by: store, money_rail: external)`
  and runs only the **document** leg + the ledger's `refunded_amount` bookkeeping.
- **WooCommerce**: plugin hooks `woocommerce_order_refunded` /
  `woocommerce_order_status_cancelled` → `POST /api/woocommerce/orders/{id}/refunded`
  → the same mirror path.
- Both are `WebhookEvent`-backed (replay-safe) and never re-refund.

### 3.6 State machines — additions only (ARCHITECTURE §3.3 stays canonical)

- `PaymentLedger`: unchanged (`succeeded → refunded`; partials via `refunded_amount`).
- `InstallmentPlan`: unchanged; `cancel_order` uses the existing `→ cancelled`.
- `RefundRequest`: `pending → money_done → store_done → completed`;
  `pending | money_done → needs_attention` (store failure after money) `→ store_done`
  by retry; `pending → failed` (the money leg refused everything). Pinned by test.
- `IssuedDocument`: unchanged.

### 3.7 Accounting edge — decide with the merchant's accountant (open)

A plan mid-stream has only **receipts** (type 400), no tax invoice yet. Green
Invoice refuses a credit note (330) that links to a receipt. The right document
is a **negative receipt** (קבלה על החזר). Proposal: `DefaultDocumentPolicy`
gains `CONTEXT_REFUND_OF_RECEIPT` → default type 400 with a negative amount, chosen
automatically when the linked document is a receipt. Needs one live Green
Invoice test. Until decided, the issuer records `unresolved` with the reason
rather than failing the refund.

---

## 4. Card-update links

### 4.1 Model — `card_update_links` (`App\Domain\Installments\Models\CardUpdateLink`)

```
id, shop_id, plan_id, customer_ref?, token_hash (sha256, unique), channel: copy | email | sms,
sent_to?, expires_at, clicked_at?, completed_at?, revoked_at?, created_by, created_at
```

Same law as `CustomerLoginToken`: **the row holds the hash, never the token**; the
raw token exists only in the URL / email / SMS. TTL default 7 days, max 30. Prunable.

### 4.2 Flow

1. Admin action → `CardUpdateLinks::mint($shop, $plan, $ttl, $channel)` → row +
   raw URL `route('cardupdate.landing', $token)`.
2. **Landing** `GET /c/{token}` — platform-neutral, no session, no tenant from
   the host: looks the hash up **across all tenants by hash only** (an audited
   seam), binds the shop, refuses expired / revoked / completed with a plain page,
   stamps `clicked_at`, shows shop name + card last-4 + one button. The button is
   a **POST** (mail scanners follow GETs) that calls the existing
   `CardUpdateService::mintPage()` **at that moment** and redirects to PayPlus —
   so the PayPlus page's own short expiry never matters.
3. PayPlus callback → existing `CardUpdateService::applyCallback()` → on success
   stamp `completed_at` on the link. Attribution: `more_info` becomes
   `cardupd:{public_id}:{link_id}` (the prefix rule stays; the callback ignores an
   absent link id).
4. Return page: the existing `WooCardUpdateReturnController`, generalised (§4.3).

### 4.3 Make the PayPlus card-update rail platform-neutral

Today the callback / return routes live under `/woocommerce/cardupdate/*/{wc_shop_token}`
and `CardUpdateService::availableFor()` demands `wc_shop_token`, so a Shopify shop
on the PayPlus rail cannot use it. Add `shops.callback_token` (minted for every
shop, backfilled from `wc_shop_token` by a command), new routes
`/payplus/cardupdate/{callback,return}/{callback_token}`, keep the Woo routes as
aliases, and let `availableFor()` ask for `callback_token`. Nothing else in
`CardUpdateService` changes.

### 4.4 Channels

- **Email** — `CardUpdateLinkMail` (strtr; merchant-editable under *Settings →
  Email* through `DefaultEmailTemplates`; placeholders `{customer_name}
  {business_name} {card_last_four} {card_update_url} {expires_at}`), sent through
  `CampaignMailer::for($shop)`. Transactional, not marketing: no "פרסומת" tag, no
  unsubscribe.
- **SMS** — `SmsSenderFactory::for($shop)` (019); merchant-editable text, same
  placeholders, capped at two segments; the action is disabled with a link to
  *Settings → SMS* while the channel is off. Phone from `plan->customer_phone`
  through `PhoneNumber`.
- **Copy** — the URL is revealed once in the modal (like the Woo connection
  token) with a copy button.
- Every send writes `KIND_CARD_UPDATE_LINK_SENT {channel, link_id}`; clicks and
  completions feed the status line.

### 4.5 Security

Hash-only storage; TTL; single completion; per-link revoke + "revoke every open
link for this plan"; the landing is rate-limited per token and per IP and reveals
only the shop name and last-4; a link mints a page **only** for the plan it names;
the POST carries a CSRF token; tokens are 48 chars from `Str::random`. Documented
in `docs/security/security-policies.md` §5.2.

### 4.6 Later — not in this plan's DoD

Automatic dunning: on a card-declined charge failure, send the link automatically
(merchant switch + channel preference). The model and channels above make this a
scheduler rule, not a new feature.

---

## 5. Data model changes (additive migrations only)

1. `refund_requests` (§3.1) — indexes `(shop_id, status)`, `(shop_id, external_order_id)`; unique `(shop_id, idempotency_key)`.
2. `payment_ledger.refund_request_id` nullable FK — which request refunded the row (`refunded_amount` already exists).
3. `card_update_links` (§4.1) — unique `token_hash`; index `(shop_id, plan_id)`.
4. `shops.callback_token` (§4.3) — unique, nullable until backfilled.
5. `issued_documents.refund_request_id` nullable — the credit note names its request.

Every tenant-owned table carries `shop_id` + `BelongsToShop`. Nothing destructive.

---

## 6. Admin UX spec — to be authored

`docs/ux/35-refunds.md` and `docs/ux/36-card-update-links.md` must define: the
drawer layout (RTL first), the four "what will happen" summary rows, empty / error /
partial states, the result panel, the `needs_attention` badge + filter on the
Payments list, the customer-detail order list, the card-update modal (channel
chips, TTL select, reveal-once URL), the status line, and **every i18n key** in
`lang/{en,he}/refunds.php` and `lang/{en,he}/card_update.php`. Design tokens only;
`rc-*` component classes from the kit.

---

## 7. Phased roadmap

Each phase: specialist builds → `code-review-gatekeeper` reviews → append-only
review in `docs/reviews/phase-refunds-*.md` → `docs/ux/INDEX.md` row flipped.

| Phase | Deliverable | Agents | Definition of done (the tests) |
|---|---|---|---|
| ~~R0 — Spec~~ **(folded into R1)** | `docs/ux/35-refunds.md`, `36-card-update-links.md`, the i18n key list, the accountant's answer to §3.7 | product-ux-architect | Spec approved by the user |
| **R1 — Request + orchestrator + partial UI (PayPlus rail)** ✅ | `RefundRequest` model + migration, `RefundOrchestrator`, `RunRefundRequestJob`, the `context` argument on `RefundService::refund()`, the drawer on ViewPayment with *Refund full / partial amount* (store leg = `skipped` for now) | laravel-backend, admin-design-system | A partial refund of ₪X on a cycle → PayPlus refunded X, ledger `refunded_amount` = X, one credit note for X linked to the cycle's document, Timeline; an identical second click returns the same request row; `MoneyPathConventionsTest` still green |
| **R2 — Store legs** ✅ | The `StoreRefunder` seam, `ShopifyStoreRefunder` (refundCreate + orderCancel + restock + marker), `WooStoreRefunder` (createRefund + cancel + restock + marker), **Retry store sync**, the `needs_attention` badge + filter | shopify-integration, woocommerce-integration, admin-design-system | Fake clients pin the exact payloads (a manual REFUND transaction on rail-1 Shopify orders; `api_refund: false` on Woo LETS-gateway orders); a store failure after money → `needs_attention`; retry is idempotent through the marker |
| **R3 — Cancel the order** ✅ | `cancel_order` mode: plan cancel + fulfillment-hold release + `CANCELLATION` context + store cancel + restock default ON; "Cancel & refund" on ViewSubscription; the deposit-plan rule | laravel-backend | Cancelling a deposit plan with two paid installments → two credit notes (`CANCELLATION`), plan `cancelled`, hold released, the Shopify / Woo order cancelled + restocked, the customer emailed once |
| **R4 — Other money rails + ledger-less credit notes** | Rail 2 (Shopify-native through `refundCreate` transactions), rail 3 (Woo `api_refund: true`), `DocumentIssuer::issueCreditForOrder()`, the §3.7 policy | laravel-backend, shopify-integration | A refund on a Shopify-Payments contract order goes through Shopify only — no PayPlus call, no ledger write; a Woo PayPal order → WC refund with `api_refund: true` + a credit note linked to the platform-order document |
| **R5 — Inbound reconciliation + WP-admin parity** | Shopify `refunds/create` handler (toml topic restored when approval lands), plugin hooks → `/api/woocommerce/orders/{id}/refunded`, plugin gateway `process_refund` → `/api/woocommerce/orders/{id}/refund` | shopify-integration, woocommerce-integration, shopify-app-release | A refund made in WP admin on a LETS-gateway order moves money on PayPlus exactly once and issues one credit note; a refund made in Shopify admin on a rail-1 order issues the credit note and never calls PayPlus |
| **C1 — Card-update link + landing + neutral rail** ✅ | `CardUpdateLink` model, mint / landing / POST-redirect, `shops.callback_token` + neutral routes + backfill command, "Generate & copy" on ViewSubscription + Customer detail, revoke, status line | laravel-backend, admin-design-system | A link opens on day 6 and refuses on day 8; a revoked link refuses; completion stamps the right link; a Shopify shop on the PayPlus rail completes end-to-end on a dev store; the Woo routes still work |
| **C2 — Email + SMS channels** ✅ | `CardUpdateLinkMail` + default template + a *Settings → Email* row, SMS text + *Settings → SMS*, channel chips, Timeline kinds | laravel-backend, admin-design-system | The email renders through strtr with the sample bag; SMS is refused with a hint while the channel is off; a send writes `KIND_CARD_UPDATE_LINK_SENT` |
| **C3 — Live verification** | One real partial refund + one real cancel per platform on the pilot stores; one real card update on a Shopify PayPlus-rail shop; the Green Invoice negative-receipt test | user + orchestrator | The production Timeline shows all three legs ✓; memory notes updated |

R1–R3 are the core the merchant asked for; R4–R5 complete "works with WordPress
and Shopify from either side"; C1–C2 are independent of R and can run in parallel
from R1 on.

---

## 8. Risks & open questions — verify before the phase that depends on each

1. **Shopify restock scope** — does restocking through `refundCreate` / `orderCancel` need `write_inventory`? Verify on a dev store (R2). App Store review flags unused scopes, so add it only if refused.
2. **Manual-gateway refunds on Shopify** — confirm `refundCreate` accepts a `gateway: "manual"` REFUND transaction on an order marked paid through `markOrderAsPaid` (R2).
3. **PayPlus partial-refund limits** — some terminals cap the number of partial refunds per transaction or refuse refunds older than N days; show the gateway's message verbatim in the result panel (R1).
4. **Negative receipt for mid-stream plans** (§3.7) — the accountant's decision + one live Green Invoice call (R4).
5. **Woo `api_refund: true`** depends on the order's gateway implementing `process_refund`; when it does not, WooCommerce records the refund without moving money — the drawer must say "recorded on the store; return the money from the gateway's own panel" (R4).
6. **Double restock on Woo** (cancel after a refund with `restock_items`) — WC guards per item with `_reduced_stock`; test the double path (R3).
7. **Card-update verify-only vaulting** is still unverified on a live PayPlus terminal (memory: `account-area-polish-shipped`); C3 closes it or flips `CONFIG_CHARGE_METHOD`.
8. **Callback-token backfill** on Shopify shops — a shop that never had `wc_shop_token` gets one from the command; the Woo routes keep accepting the old value (C1).
9. **SMS cost / spam** — one link per plan per channel per 24h unless the merchant confirms "send again" (C2).
10. **A recurring plan's ledger rows all share the plan's ORIGINAL checkout order** (found in R3). An order-scoped refund opened from the subscription screen would therefore sweep every cycle the customer ever paid — which is why that screen LINKS to the payment page instead of hosting its own drawer. Any future entry point must scope to a charge, or narrow the order query by charge context.
11. **Credit notes keyed on `(ledger, amount)` alone collapsed two equal partial refunds into one document** (found and fixed in R1). The key now carries the starting point; the legacy shape is still consulted for the FIRST slice so a re-queued job cannot mint a second real tax document. Any change to that key needs the same care.

---

## 9. Definition of done — the whole plan

- From the LETS admin, on a Woo *and* a Shopify order, the merchant can refund
  partially or fully, or cancel entirely, choosing restock — and the money, the
  store order and the credit note follow, every leg visible and retryable.
- Refunds made in WP admin or Shopify admin are mirrored, never doubled.
- No charge, refund or document without its ledger / issued-documents row; every
  external call idempotent; `MoneyPathConventionsTest` and the tenant-safety suite
  green; the new state machine pinned by test.
- The merchant can generate, copy, email or SMS a card-update link from the
  admin for any PayPlus-rail plan on either platform, see whether it was used,
  and revoke it.
- EN + HE strings complete; RTL screenshots in `docs/screenshots/`; INDEX rows
  flipped; reviews appended.
