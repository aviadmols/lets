# Code review log — Phase 6 (append-only)

## 2026-06-15 — Phase 6 (Post-Purchase Upsell: backend money path + admin UI + storefront widget) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Money-safety: PASS — ledger opened pending before gateway (UpsellChargeService.php:129 < :148); hasSucceeded
  short-circuit (:88) + deterministic IdempotencyKey::upsell (:64) + (shop_id,idempotency_key) unique + PayPlus
  Idempotency-Key header → double-click collapses to ONE charge (UpsellEngineTest proves payplusCalls==1); consent
  fail-closed (:106); per-shop creds via PayPlusGatewayFactory::for (:148); ResponseMasker before ledger (:198);
  uid ?: null (:203); compensating Timeline event + reconcile flag on child-order failure (:318).
Tenant-safety: PASS — signature verified before bind/charge; Tenant::run binds from signed shop id, clears in
  finally; amount recomputed server-side (UpsellFlowOffer::discountedPrice); all upsell models shop_id +
  BelongsToShop; resolver + metrics fail closed on tenant≠context; UpsellTenantIsolationTest covers cross-shop.
UI/conventions: PASS — zero inline CSS (flow-builder.js writes --rc-fb-* custom props); CONST-at-top on all new
  pages/middleware/CSS; en/he upsell mirror 120/120 keys; DevAutoLogin gated isLocal+dev_tenant+guest; FlowBuilder
  activate/pause via guarded transitionTo(); metrics aggregated in UpsellMetrics not Blade.
Tests: 59 passed (246 assertions).
Blocking: NONE
Suggestions: #1 accept/decline are GET routes that can cause a charge (gated, but reachable by non-human GET) —
  consider POST + bot guard at App-Store hardening; #2 ACCEPTED funnel event recorded outside the DB::transaction
  (analytics skew on rollback, not money).
Nits: #3 UpsellChargeService ~410 lines (traceable); #4 synthetic 'free-'+key uid on zero-price offers.
Gate: PASS-WITH-SUGGESTIONS — clear to commit. §7.7 upsell prerequisite (tenant-vault && engine && ledger) satisfied.

## 2026-06-15 — Phase 6 unit: "Configure cross-sell" drawer (Flow Builder) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Self-flagged withoutGlobalScopes() — RULING: ACCEPTED dev-only exception (NOT a tenant hole). Route registered
  ONCE behind `isLocal() && config('app.dev_tenant',false)` (default false; same gate as DevAutoLogin/BindDevTenant)
  → never exists in prod. Bypass selects ONLY ->value('shop_id') (scalar, unrendered); ALL renderable data produced
  inside Tenant::run($shop) where the fail-closed TenantScope re-applies → no cross-tenant leak. ?config/?shot
  deep-links same gate; openOfferConfig/saveOfferConfig load via tenant-scoped offerModel() (no bypass) → foreign id
  is a no-op. No charge: discountedPrice()/UpsellChargeService untouched; saveOfferConfig sanitizes every enum vs
  model CONST allow-lists, clamps %, writes no shop_id/status.
UI/conventions: PASS — zero inline CSS; only new literal --rc-scrim in theme.css; en/he upsell mirror 157/157;
  activate/pause via guarded transitionTo(). Tests: 61 passed (269 assertions).
Blocking: NONE
Suggestions: #2 add a targeted cross-shop no-op test for openOfferConfig/saveOfferConfig; #3 ensure prod build runs
  route:cache so the dev preview route can't be frozen into a shipped cache (railway-infra).
Nit: #4 primary footer Save CTA labeled "Close" — matches the Recharge reference + approved screenshot.
Gate: PASS-WITH-SUGGESTIONS — clear to commit.
