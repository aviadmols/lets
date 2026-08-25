# Reviews — Shopify parity (customer area · loyalty · referrals · offers · view-as)

> Append-only. Newest at the bottom.

## 2026-08-21 — Shopify parity (guest-referral · account API · OfferOrderWriter seam · contracts · view-as · referrals) — VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Scope: 44 modified + 32 new files; sweeps clean (withoutGlobalScope, Blade::render, config('payplus' secrets,
       inline style=, nondeterministic keys, raw status writes); lang parity 30/30; 492 affected tests green.
Blocking: #1 account-offer draft order sends a variant GID to the REST draft-order endpoint
          (ShopifyDraftOrderService.php:165 + ShopifyAccountOfferOrderWriter.php:105-113) — charged sale
          plausibly recorded as order_not_created on every buy-now; test asserts against a fake.
Suggestions: #2 referral attributed with no identity at all (self-referral wall cannot run);
             #3 draft carries no customer block; #4 unguarded empty draft id; #5 CONST-at-top on two new
             controllers; #6 ARCHITECTURE.md edited inside a feature unit.
Nits: #7 stale routes docblock; #8 unused factory fake seam; #9 stray login-panel-preview.png; #10 missing
      sibling docblock on recordFailure.
Re-review: required (shopify-integration; laravel-backend for the writer)

## 2026-08-21 — Fix pass for the findings above — applied
Owner: main session (implementing agent)
- #1 FIXED: `ShopifyDraftOrderService::restVariantId()` — the REST draft channel now always receives the
  NUMERIC variant id (GID or bare, either spelling normalised; non-numeric → title-only line). Applied to
  BOTH `createAccountOfferOrder()` and the pre-existing `createUpsellChildOrderForCustomer()` twin.
  `ShopifyAccountOfferOrderWriter` no longer builds a GID at all. Test now asserts the numeric value.
- #2 FIXED: `ReferralService::attribute()` refuses when buyerRef AND buyerEmail are both absent (the
  self-referral wall cannot run) + new listener test (order with a code but no identity → no referral).
- #3 FIXED: the account-offer draft carries `customer: {id}` when the plan's shopify_customer_id is a real
  numeric id (`restCustomerId()`); an imported UUID ref sends none (asserted).
- #4 FIXED: `createAccountOfferOrder()` throws `shopify.draft_order_created_without_id` on an id-less create,
  so the writer's catch logs a precise reconcile reason on the post-charge path.
- #5 FIXED: CONST blocks added — `ShopifyAccountController::REASON_NO_TENANT/REASON_NO_CUSTOMER`,
  `AdminCustomerAccountViewController::SURFACE`.
- #7 FIXED: routes/subscriptions.php group docblock now tells the one-transport story.
- #8 FIXED: `OfferOrderWriterFactory::fake()` exercised by
  `ShopifyAccountOfferPurchaseTest::test_the_purchase_resolves_its_writer_through_the_factory_seam`.
- #10 FIXED: `ShopifyAccountController::recordFailure()` carries the sibling's full docblock.
- #6 OPEN (deliberate): the ARCHITECTURE.md store-credit clause correction stands — it documents what the
  code has actually done since 2026-08-03; flagged for recharge-orchestrator to ratify at the next phase gate.
- #9 OPEN: `login-panel-preview.png` at the repo root predates this unit (untracked before it); left for the
  repo owner to delete or move — not this unit's file to remove.
Verification: affected suites (Account/Offers, Loyalty guest-referral, Upsell, ViewAs, ShopifyAccountEndpoint)
204 passed; full suite re-run pending below.
