# Code review log — Work Package W1 (append-only)

## 2026-06-15 — W1 (Products + per-variant plan templates + 2 Flow-Builder fixes) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Blocking: NONE.
Independently verified: zero new withoutGlobalScope in product/UI; all 3 tables shop_id + unique keys
  ((shop_id,source,external_id) + (shop_id,product_id,external_variant_id)); all 3 models BelongsToShop +
  guarded shop_id (+status on template); ImportShopProductsJob carries shop_id + TenantContext (clears in
  finally); ProductUpserter fails closed without a tenant; per-shop Shopify client only via
  ShopifyClientFactory::for (no global token reads); DTOs are the boundary; upserts idempotent; products/delete
  = soft-unlist keeping plans; savePlanConfig sanitizes every value vs CONST allow-lists (interval [1,60],
  percent [0,100], charge-day [1,28], channels ⊆ CHANNELS, frequency via tryFrom), never writes shop_id/status
  from input; ProductPlanTemplateResolver is a read-only seam (NO charge-engine change — ChargeOrchestrator/
  UpsellChargeService/ledger absent from the diff); ProductDetail + FlowBuilder foreign/missing id → redirect
  (no 404/leak); createFlow seeds a real draft flow (no /flow/0); saveTriggerConfig sanitizes match_type +
  nulls stale sub-fields; i18n EN/HE mirror exact (products 106/106, upsell 182/182);
  ProductTenantIsolationTest proves Shop A cannot read Shop B's products/variants/plans. Tests: 104 passed.
Suggestions (non-blocking): #1 WooCommerceProductSource fetchPage comment contradicts its throw (Stage-2 seam);
  #2 ShopifyAdminClient catalog variant `price` — confirm/guard vs pinned-API-version drift (no money path uses
  it yet); Nit #3 ProductDetail::product() findOrFail fallback unreachable post-redirect — add a comment.
Gate: W1 may be committed. Re-review not required.
