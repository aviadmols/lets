# Code review log — Phase 4 (append-only)

## 2026-06-15 — Phase 4 (Shopify public-app integration) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Verified safe (no blockers): webhook HMAC on raw body + timing-safe + fail-closed (503 prod /
  401 non-prod on empty secret, 401 bad/absent); per-shop resolution by X-Shopify-Shop-Domain with
  HMAC-before-resolution; ProcessShopifyWebhookJob carries shop_id explicitly, binds via TenantContext
  (clears in finally), asserts event.shop_id === shopId before any work; WebhookEvent intentionally
  un-scoped + documented, read by PK; firstOrCreate backed by a real (shop_id,source,webhook_id,topic)
  unique index; OAuth verifies query HMAC before code exchange, single-use Cache::pull state nonce,
  encrypted offline token, redirect target derived from validated shop+config (no open-redirect);
  SessionTokenVerifier pins HS256, validates aud/exp/nbf/iss==dest, timing-safe sig; ShopifyAdminClient
  holds token as constructor state via ShopifyClientFactory::for(Shop), never config/never logged; API
  version pinned (2025-10); GDPR (customers/redact, shop/redact, customers/data_request) registered in
  toml + handled with tenant bound; app/uninstalled marks uninstalled + nulls token (scheduler gates on
  status); order-strategy seam: ChargeOrchestrator depends only on ?ShopifyOrderStrategy, materialize
  runs after succeeded ledger inside try/catch (Shopify failure cannot unwind money); upsell deferred
  to Phase 6; parent-order-no-transactions scar preserved on the port.
Blocking: none.
Suggestions: #1 materializeShopify runs inside the charge DB::transaction / on the charge path —
  move to post-commit sync job (laravel-backend, priority follow-up); #2 OAuth abort messages not
  wrapped in __() (only line 44 is) (shopify-integration).
Nits: #3 SessionTokenVerifier dest host not re-validated against ShopifyDomain::isValid();
  #4 webhook_events dedupe unique relies on non-null webhook_id (Postgres null non-collision).
Gate: PASS-WITH-SUGGESTIONS. Phase 4 advances. Re-review not required to advance.
