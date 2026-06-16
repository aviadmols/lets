# Code review log — LETS app wiring (append-only)

## 2026-06-16 — phase-lets-app (App-Proxy-signed upsell offer/accept seam) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Adversarial verification:
  - App Proxy signature: CORRECT (sorted key=value, NO separator, hex HMAC-SHA256, app secret; array values
    joined by ","), timing-safe hash_equals, fail-closed (503 empty secret in prod / 401 bad+absent). Validated
    with a live PHP harness: signed=true, forged=false, absent=false, empty-secret=false, array-param=true. Shop
    read only post-verification, domain-validated, tenant bound via Tenant::run + auto-cleared. PASS.
  - Tenant isolation: UpsellResolver under BelongsToShop scope + explicit Tenant::id() guard; ProxyOfferController
    re-asserts tenant==shop; no withoutGlobalScope in new files. Cross-tenant test (shop A never sees shop B) green.
  - Money-safety on accept-api: faithful JSON twin of AcceptUpsellController; same UpsellChargeService::accept, same
    signed-link auth, deterministic IdempotencyKey::upsell, server-recomputed amount, per-shop gateway factory,
    masked response, compensating child-order action. NO new charge path. PASS.
  - No charge-engine change: UpsellChargeService/ChargeOrchestrator/Ledger/IdempotencyKey absent from diff. PASS.
  - CSRF exemption: scoped to shopify/webhooks(*) + upsell/accept-api; signature-authed not session. PASS.
  - Secrets/rebrand: client_id placeholder; API key/secret blank in .env.example; no real secret committed;
    .gitignore excludes extension build artefacts; api_version 2026-04 consistent; OAuth scope SET identical.
  - Tests: ProxyOfferEndpointTest 5/5 green; suite 123 passed (481 assertions).
Blocking: none.
Suggestions: #1 native post-purchase extension POSTs /proxy with a Bearer JWT, but /proxy is App-Proxy-signature
  only — that path fails CLOSED (401) and ships DISABLED (CAPABILITY.nativeChangeset=false, documented Phase-6.x
  TODO). Implement the post-purchase token (aud==api_key, SHOPIFY_API_SECRET) verifier + tenant bind, or keep the
  native extension out of `shopify app deploy`, before enabling it.
Nits: #2 AcceptUpsellApiController lacks a CONSTANTS block (mirrors its HTML twin; status from UpsellChargeResult::
  RESULT_*); #3 OAuth scope strings differ in ORDER only between shopify.app.toml and config/shopify.php.
Gate: PASS-WITH-SUGGESTIONS — may advance. Re-review required only when SUGGEST #1 (native post-purchase auth) lands.
