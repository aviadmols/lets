# Code review log — Work Package W2 (append-only)

## 2026-06-16 — W2 (user roles: platform admin vs site manager) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Blocking: NONE. No path found for a merchant to see another shop's data, escalate to platform admin,
  or a platform-mode admin to see merged rows.
Adversarial verification (all 10 held): is_platform_admin mass-assignment-guarded; a merchant who forges
  platform.entered_shop_id is IGNORED (the entered-shop branch is reachable ONLY inside isPlatformAdmin();
  the merchant branch never reads the session key); entering shop A binds exactly A via the same global
  scope and never exposes B; Tenant cleared in finally; stale/deleted entered id -> platform mode (not 403);
  platform mode (no selection) = zero rows (headline guarantee preserved); the enter-uninstalled-shop
  relaxation is scoped to the platform-admin branch only (merchant stays fail-closed on isLive); ShopScopedScreen
  gates per-shop screens on a bound tenant (merchant unaffected; platform-mode admin sees no empty per-shop
  screens; ShopResource is NOT trait-gated, shown unbound to the platform admin); NO new acrossAllTenants/
  withoutGlobalScope in W2; Shop::query() returning all shops is legitimate (Shop is the tenant) and the
  resource is gated; acting-as actor resolves to platform_admin:{id} ONLY when actually entered, explicit
  actor wins; DevAutoLogin prod-impossible; CreatePlatformAdmin uses forceFill + random pw + idempotent,
  console-only; banner/platform.css zero inline CSS; en/he mirror exact (platform 36/36, nav 29/29, common 19/19).
Reviewer-run tests: tests/Feature/Tenancy/ = 20 passed (67 assertions); full suite 118 passed.
Suggestions (non-blocking): #1 ViewShop enter action could add an inline PanelAccess::canSeePlatform() re-check
  for parity (page already framework-gated). Nit #2 DevAutoLogin lowest-id fallback could pick the demo platform
  admin on a partial LOCAL seed (prod-impossible) — restrict to is_platform_admin=false.
Out-of-scope note: pre-existing withoutGlobalScopes() at DevPreviewUpsellController.php:40 (not in W2) — future review.
Gate: W2 may commit. Re-review not required.
