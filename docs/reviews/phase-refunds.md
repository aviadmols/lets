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

---

## 2026-09-09 — R4 (the money rails this app never charged)

**Scope.** `RefundTargetResolver::fromStore()`, `RefundRequest::DELEGATED_RAILS`,
`StoreRefunder::refundableTotal()`, both refunders' delegated paths,
`DocumentIssuer::issueCreditForOrder()`, `ShopifyAdminClient::fetchOrderTransactions()`.

**The legs invert, and the state machine caught it.** On a delegated rail the
store call is not a record of a refund that already happened — it IS the refund.
The first cut moved the request to `money_done` before that call, and a store
failure then tried `money_done → failed`, which the machine (rightly) refuses:
money that has moved cannot un-move. The request stayed stuck reading "money
returned" for a refund that never happened. The delegated money leg now STAYS
`pending` and the store leg walks it through `money_done → store_done` on
success, or straight to `failed` on refusal — one hop per thing that actually
happened, in the order it happened.

**Both flags pinned, in both directions.** `api_refund: true` and a
`parentId`-bearing Shopify transaction ONLY on the delegated rail; `false` and a
manual, parent-less transaction on ours. A test asserts each, including that a
PayPlus-charged Shopify order still gets the parent-less form — that assertion is
what stands between a merchant and refunding the same shopper twice.

**Ceilings come from the store, never invented.** WooCommerce: order total minus
its recorded refunds. Shopify: settled sale/capture transactions minus settled
refund transactions — the transactions, not the order total, because the total is
what was ordered and a refund can only return what was paid. A failed attempt
does not count. A store that cannot answer yields "nothing", and the drawer says
so rather than offering a number that would be refused.

**Credit notes.** `issueCreditForOrder()` keys on `(order, alreadyRefunded,
amount)` — the same two-part shape as the ledger path, for the same reason. It
REFUSES to issue when no sale document exists for the order: Green Invoice
rejects a credit note with no linked document, and a dangling declaration is
worse than a missing one. The refund still completes and says so.

**A test-harness lesson worth keeping.** `Http::fake` patterns match the query
string, so `orders/*/refunds` matched the POST but not the `?per_page=100` GET —
"already refunded" silently read as zero and a ceiling test passed for the wrong
reason. Trailing `*` on any stub whose real call carries a query.

**Tests.** `DelegatedRailTest` 8 · `tests/Feature/Refunds/` 51 · full suite 1848 green.

**Still unverified live** (unchanged from the plan's §8): whether a merchant's
Woo gateway actually implements `process_refund` (when it does not, WooCommerce
records the refund without moving money and the drawer will report success —
§8.5), and the Shopify `write_inventory` question.

---

## 2026-09-09 — R5, WooCommerce half (refunds pressed inside the store)

**Scope.** `RefundMirrorController` + two signed routes,
`RefundOrchestrator::mirrorExternal()` / `knownRefunded()`, the plugin's
`class-lets-refunds.php`, and the gateway's `supports('refunds')` +
`process_refund()`.

**Two endpoints, not one with a flag**, because the difference between them is
who moves the money and getting it wrong is a double refund. `/orders/{id}/refund`
is the LETS gateway's own `process_refund` asking us to move it — it runs INLINE
(WooCommerce blocks on the answer and writes its refund record from it, so a
queued "we will get to it" is not something it can render). `/orders/{id}/refunded`
mirrors a refund WooCommerce already made and never calls PayPlus.

**The double-count wall is structural, not a guard.** `woocommerce_order_refunded`
fires for the gateway's own refunds too, and a retried request fires it again. So
the mirror takes the order's RUNNING TOTAL and records only the delta against
what LETS already knows — every duplicate collapses to zero by construction. The
plugin also skips the hook for `lets_payplus` orders, which is the cheaper of the
two walls but not the only one; a test pins the SaaS side holding on its own.

**Where "what we already know" comes from:** the ledger when the order has rows
(it is the money truth and every path writes to it), else the mirrored requests
themselves. `mirrorExternal` writes the delta onto the ledger rows OLDEST FIRST —
unlike our own partial refunds, because this is bookkeeping catching up with
something that already happened and there is no "which cycle is the customer
unhappy about" to honour, only a total to account for. It writes no transaction
uid: there is no transaction of ours, and pretending otherwise would make an
external refund indistinguishable from one we made.

**Store leg stands down.** A request opened from the store carries
`store_result = applied_by_store`, and `runStore()` returns without calling —
WooCommerce is writing its own refund record as the return value of the very call
we are answering, and a second one would appear on the order.

**Tests.** `StoreInitiatedRefundTest` 10 · `tests/Feature/Refunds/` 61 · full suite 1858 green.

**NOT done in this unit:** the Shopify half. `refunds/create` is declared in the
toml but commented out pending protected-customer-data approval, so a refund made
in Shopify admin is still not mirrored. The SaaS-side machinery it needs
(`mirrorExternal`, `knownRefunded`, `issueCreditForOrder`) now exists and is
tested; what is missing is the webhook handler and the approval.

---

## 2026-09-09 — R5b (the refund box on the WooCommerce order screen)

**Scope.** `RefundMirrorController::state()` + `/orders/{order}/refund-state`,
the plugin's `class-lets-order-refund.php` (metabox + admin-post handler), plugin
0.47.0.

**Why a box when WooCommerce has its own Refund button.** For a LETS-gateway
order that button now works (R5 gave the gateway `supports('refunds')`). But a
DEPOSIT or INSTALLMENTS order is paid on the PayPlus page, so WooCommerce records
a payment method it has no refund handler for, greys out the API-refund path and
offers only "refund manually" — a bookkeeping entry that returns nobody's money.
Those orders' money IS in the ledger and IS refundable; WooCommerce simply had no
way to ask.

**The step order is the safety property.** Money first (the SaaS: PayPlus, the
ledger, the credit note), and WooCommerce's own refund record only after that
succeeded. A store record written first is a store telling a merchant their
customer was refunded when nobody was. When the money moves and
`wc_create_refund()` then fails, the box says so and writes an order note rather
than reporting success — a refund the store does not know it made is worse than
a visible gap.

**The store record is written LOCALLY, not by the SaaS.** The SaaS knows how to
write it (that is exactly what it does when the refund starts in the LETS admin),
but calling back into WooCommerce from inside a request WooCommerce is already
blocking on is a WP → SaaS → WP round trip: on a small host with few PHP workers
that deadlocks until it times out. `wc_create_refund()` costs nothing here and
cannot deadlock — and it is the canonical API, so restock, totals and hooks are
WooCommerce's own.

**`refund_payment: false`** on that call, for the same reason `api_refund` is
false everywhere on our rail: PayPlus already sent the money.

**The box's figure comes from the same resolver the money leg uses** — not a
second opinion — so what a merchant reads and what they authorise cannot
disagree. Cached 60s (an order screen re-renders constantly) and failing CLOSED:
a SaaS hiccup shows "not connected" rather than a stale ceiling.

**Tests.** `StoreInitiatedRefundTest` 14 (4 new on the state endpoint) · full
suite 1862 green.

**Unverified live:** the whole box, like the rest of R5 — it has never run inside
a real WooCommerce admin.

---

## 2026-09-09 — R5c (the box actually works: no nested form, plus cancel)

**The bug.** The first version of the order-screen box rendered a `<form>`. The
WooCommerce order screen IS a form, browsers silently drop a nested one, and the
merchant's own HTML showed exactly that: the hidden inputs present, the `<form>`
tag gone. The submit button was therefore submitting WooCommerce's order-save,
so pressing it did nothing at all — no error, no request, no clue.

**The fix is structural, not a workaround.** The controls carry ids and NO `name`
attributes, so WooCommerce's own "Update" can never post them by accident, and
the button builds a top-level form in JS at click time and submits that. There is
no markup answer available here; the form has to be created outside the one the
screen already is.

**Cancel.** A checkbox, and a `cancel` flag on `/orders/{order}/refund`. It is a
flag rather than a fourth endpoint because the three-endpoint split is about WHO
MOVES THE MONEY, and this changes WHAT THE REQUEST MEANS — which is exactly what
`mode` is for. Ticking it hides the amount field (cancelling means everything
still refundable; a number the run would ignore is a promise the box cannot
keep), and the box is offered even when nothing is refundable, because cancelling
an unpaid order is a real thing to want.

`ok` had to change meaning per mode: for a refund it stays "did the money go
back" (WooCommerce writes its refund record from it), for a cancellation it is
"did the instruction go through", since cancelling an unpaid order moves nothing
and is still a success.

**Order of operations in the handler:** money → WooCommerce's refund record →
cancel the order. The cancel goes last for the same reason it does on the SaaS
side: WooCommerce restocks on the move to `cancelled` too, and the refund above
has already returned whatever the merchant asked for.

**"LETS" removed** from every merchant-facing string in this flow — the box
title, its copy, the notices, and the order notes the gateway's own refund path
writes. PayPlus stays: it is the actual gateway and it means something to a
merchant reading their order.

**Tests.** `StoreInitiatedRefundTest` 17 (3 new on cancel) · full suite 1865 green.

**Still unverified live:** the box has now been rendered in a real WooCommerce
admin (the nested-form bug was found that way), but no refund has been put
through it end to end.
