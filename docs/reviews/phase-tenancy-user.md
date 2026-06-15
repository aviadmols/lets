# Code review log — User↔Shop tenant binding (append-only)

## 2026-06-15 — Phase Tenancy-User (user↔shop binding) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope: app/Http/Middleware/BindTenantFromUser.php, app/Models/User.php,
  app/Providers/Filament/AdminPanelProvider.php, app/Http/Controllers/Shopify/OAuthController.php,
  app/Services/Shopify/MerchantUserProvisioner.php,
  database/migrations/2025_01_03_000001_add_shop_link_to_users_table.php,
  database/factories/UserFactory.php, tests/Feature/Tenancy/CrossUserIsolationTest.php
Blocking: none.
Adversarially verified PASS: fail-closed shopless deny (403 + canAccessPanel); shop/redact nullOnDelete →
  denied; finally-clear (no Octane pkg; static reset each request); platform-admin binds nothing → scope
  returns 0 rows (probed), never all; is_platform_admin not mass-assignable (probed: dropped by fill);
  provisioner idempotent / attach-only-if-unlinked / random pw / no auto-login / per-shop unique email
  (6-domain probe); no new withoutGlobalScope; anti-theater: test drives REAL BindTenantFromUser via routes,
  mutation probe shows scope→0 rows if binding removed; full suite 46 passed / 186 assertions, no regression.
Suggestions:
  #1 BindTenantFromUser bound any EXISTING shop row without isLive() — uninstalled merchant still reached the
     panel, contradicting the docblock + diverging from SessionTokenAuth.  ── RESOLVED 2026-06-15: added
     `|| ! $shop->isLive()` to the deny (mirrors SessionTokenAuth); 46 tests stay green.
  #2 BindTenantFromUser docblock referenced composition after SessionTokenAuth, which is not yet on the panel
     authMiddleware stack (only the shopify.session alias on routes/shopify.php). Tenant::check() short-circuit
     is forward-safe. (Open: shopify-integration to register SessionTokenAuth on the panel when embedded
     binding is wired, or soften the comment.)
Nits:
  #3 User has $fillable allowlist + redundant $guarded(is_platform_admin) — harmless belt-and-suspenders.
Gate: PASS-WITH-SUGGESTIONS — tenant isolation GREEN, clear to ship. #1 resolved; #2/#3 non-gating.
