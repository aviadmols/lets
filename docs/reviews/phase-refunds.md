# Code review log — Refunds, cancellations & card-update links (append-only)

Plan: [docs/plans/refunds-cancellations-and-card-update-links.md](../plans/refunds-cancellations-and-card-update-links.md)

---

## 2026-09-09 — R1 (the request row, the orchestrator, the partial-refund drawer)

**Scope.** `refund_requests` + its guarded machine, `RefundOrchestrator`,
`RefundTargetResolver`, `RunRefundRequestJob`, the `StoreRefunder` seam, the
shared `RefundDrawer`, the payment page + payments list, `lang/{en,he}/refunds.php`.

**Money-safety findings fixed inside the unit** (both would have been shipped
defects, not new risks introduced by it):

1. **Credit notes were keyed on `(ledger, amount)` alone.** Refunding ₪50 twice
   against one sale therefore produced ONE document: the customer had ₪100 back
   and the books declared ₪50 — a VAT under-report an accountant discovers, not
   us. `DocumentIssuer::keyForRefund()` now carries the STARTING POINT as well
   (the same shape `IdempotencyKey::refund()` has always used at the gateway),
   with a legacy-key lookup restricted to the FIRST slice, where the two shapes
   describe the same event and therefore cannot be confused. Without that
   fallback, the first re-queue of an already-issued credit note would have
   minted a second real tax document.
2. **A partial refund was applied in ledger order.** The allocation was
   newest-first but the EXECUTION was not, so a gateway decline halfway through
   could reverse the oldest charge and refuse the newest — the opposite of what
   the merchant meant. The allocation is now an ordered list the money leg
   follows exactly.

**Verified.** Double-clicked drawer → one request; a genuine second slice of the
same size → its own request (the key carries what had already gone back); a
declined charge does not roll back the ones that reversed and is reported rather
than hidden; refunding more than remains is refused before the gateway; a
request scoped to one charge never touches its neighbour; a settled request
re-run moves no more money.

**Tenant safety.** `RefundRequest` carries `shop_id` + `BelongsToShop`; the job
carries `shop_id` explicitly under `TenantContext`; `RefundOrchestrator` binds
`Tenant::run` around every leg (the resolver's re-reads fail closed unbound).
One `acrossAllTenants()` added in this whole body of work — the card-update
landing's hash lookup (C1), which is the audited "a stranger arrives with only a
token" seam, identical in shape to `CampaignLoginLinks::find()`.

**Tests.** `tests/Feature/Refunds/` 21 · full suite 1801 green.

---

## 2026-09-09 — R2 (the store legs)

**Scope.** `WooStoreRefunder`, `ShopifyStoreRefunder`, `WooCommerceClient::createRefund/fetchRefunds`.

**The one thing that matters.** Both platforms take a flag deciding WHOSE money
moves — WooCommerce's `api_refund`, Shopify's refund-transaction `gateway`. Every
order this app charged was paid on the PayPlus page and PayPlus has already
returned the money by the time the store leg runs, so both are set to RECORD, and
Shopify's refund transaction is a MANUAL one with no `parentId`. Either flag the
other way is a second real refund of the same money with nothing in either system
saying so. Both are pinned by a test that reads the exact payload.

**Ordering.** Cancellation records the refund BEFORE it cancels, on both
platforms, because both restock on the transition to cancelled and the refund may
restock too. Shopify's `orderCancel` is called with `refund: false` and
`restock: false` for the same reason.

**Idempotency lives on the ORDER** (a WooCommerce order meta, a Shopify order
metafield naming the request) — not in our tables, because the failure a retry
must survive is a crash between the store call and our write. `alreadyApplied()`
answers FALSE when it cannot read the order: "unknown" is not "already done", and
answering otherwise would skip the store leg of a refund that moved real money.

**Deliberate limitation.** Restocking on Shopify needs a location (order
fulfillment location, else the shop's first). If neither can be read the refund
still goes through WITHOUT the restock: money back with an unadjusted shelf count
is a count to fix; a failed store leg over one is a merchant chasing a refund
that already happened. An amount-only refund restocks nothing — Shopify has no
"return proportionally", and inventing quantities would put back stock nobody
said to return.

**Open, to verify on a dev store before this is trusted in production:** whether
restocking through `refundCreate` requires `write_inventory` beyond the
`write_orders` we hold. Add the scope only if refused — App Store review flags
unused scopes.

**Tests.** Woo 6 · Shopify 7 · full slice (Refunds/Billing/WooCommerce/Shopify) 349 green.

---

## 2026-09-09 — R3 (cancel the order)

**Scope.** `RefundPlanCanceller`, the orchestrator's plan leg, the drawer's
`cancel_order` mode, the subscription screen's link through to the payment page.

**The failure this closes** is the quiet one: a merchant refunds an order,
believes the arrangement is over, and the scheduler bills the same customer again
next month. Every LIVE plan the ORDER gave rise to is cancelled, not only the one
the payment row names — a cart with two subscription lines is one order and two
plans.

**Ordering.** The plan leg runs AFTER the store leg, so a refund stuck in
`needs_attention` does not also stop billing before the merchant has sorted the
store out; the retry finishes both.

**One rule survives a plain refund:** a plan with nothing left paid into it is
over whatever the merchant picked. Billing the remaining instalments of a
purchase that no longer exists is not a policy question.

**Deliberate design note.** The subscription screen reaches the drawer by LINK
rather than hosting a second one. A recurring plan's ledger rows all carry the
plan's ORIGINAL checkout order (the DocumentIssuer comment records why), so an
order-scoped refund opened from that table would sweep every cycle the customer
ever paid. This is a genuine hazard, not a UI preference.

**Tests.** `CancelOrderTest` 9 · full suite 1823 green.

---

## 2026-09-09 — C1 + C2 (the card-update link and its channels)

**Scope.** `card_update_links`, `CardUpdateLinks`, `CardUpdateLinkSender`,
`CardUpdateLandingController` + its two views, `shops.callback_token` and the
platform-neutral `/payplus/cardupdate/*` routes, `CardUpdateLinkMail` (the ninth
merchant-editable template) and the 019 SMS channel.

**Why the link exists at all.** A PayPlus re-vault page is minted for a moment
and expires on their side, so emailing one sends a dead page. The LETS link lasts
days and mints the PayPlus page at click time.

**Credential discipline** (mirrors the campaign sign-in link, deliberately):
sha256-only storage; GET mints nothing (mail scanners follow every link before a
person does); the POST is a real button, not an auto-submit, because this one
leads to a page asking for card details; one uniform 410 for missing, malformed,
expired, revoked and already-used; the page reveals the shop name and the card's
last four digits and nothing else; per-token AND per-IP rate limits.

**Completion is stamped by the gateway callback**, not by the page being opened,
and the link id rides in `more_info` beside the plan id so a merchant who sent two
reminders closes the right one.

**The rail was Woo-only by plumbing, not by nature.** `wc_shop_token` on
`/woocommerce/` routes locked out every Shopify shop charging through the same
PayPlus gateway. `shops.callback_token` is that token renamed and backfilled; both
controllers accept EITHER column and the Woo paths stay mounted, because PayPlus
can be holding a page URL minted days ago. Pinned by `CardUpdateTest` (still
posting to the old paths) plus a new test that a Shopify shop can now mint one.

**Send ordering.** The row is written BEFORE anything is sent, so a failed
transport leaves the merchant holding a link to pass on rather than a credential
that exists only in a customer's inbox. Refusals are named individually
("no email on file" / "SMS is not set up for this shop" / "the transport broke")
because they are three different things to go and fix.

**Tests.** `CardUpdateLinkTest` 17 · full suite 1840 green.

---

## Still open (tracked in the plan, §7 R4–R5 and §8)

- **R4** — refunds on money rails other than PayPlus: Shopify Payments contract
  orders, and WooCommerce orders paid by another gateway (`api_refund: true`).
  Today the drawer reports "nothing to refund" for these rather than pretending.
- **R5** — the reverse direction: a refund made in WP admin or Shopify admin is
  not yet mirrored into a ledger adjustment + credit note.
- The §3.7 accounting question (a credit note cannot link to a RECEIPT, which is
  all a mid-stream plan has) is unanswered and needs the merchant's accountant.
- The Shopify `write_inventory` question above.
