# Code review log — Customer-area passwordless sign-in (append-only)

The shopper's way into the personal area: one-time codes by email or SMS (019),
and "Sign in with Google". The SaaS issues and verifies; WordPress decides who the
destination belongs to and grants the session.

## 2026-08-11 — Customer-area passwordless sign-in (SMS 019 + Google) — VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Scope: app/Support/PhoneNumber.php (new), app/Models/CustomerLoginCode.php,
  app/Domain/Account/LoginCodeService.php, app/Services/Sms/Sms019Sender.php,
  app/Models/MerchantPortalAppearance.php, app/Filament/Pages/ManageCustomerArea.php,
  plugins/lets-payplus-woocommerce/includes/class-lets-account.php,
  plugins/lets-payplus-woocommerce/includes/class-lets-admin.php,
  lang/en/account.php, lang/he/account.php, tests/Feature/Account/*, pint.json
Blocking: #1 raw withoutGlobalScopes() in CustomerLoginCode::prunable (use acrossAllTenants);
  #2 model:prune never scheduled — the Prunable trait is inert and the bypass unjustified;
  #3 checkout enqueue makes the checkout render block on a 20s signed_post to LETS
Suggestions: #4 verify unthrottled; #5 count_total on 3 get_users; #6 'sent' reported on a
  spent IP budget (REMOTE_ADDR behind CDN); #7 'rejected' reported on a successful sign-in
  with an empty redirect; #8 unguarded wc_get_page_permalink; #9 canonicalDestination
  fallback diverges from the plugin's folding; #10 index/unindexed duplicate ambiguity;
  #11 offset cursor drift in the backfill; #12 settings_changed unreachable from Filament;
  #13 codes still issued to privileged accounts
Nits: #14 cap list completeness; #15 invalidateLive not filtered by channel
Clean: CONST-at-top, no inline CSS, en/he mirror, no Blade::render, no secret logged,
  all hashDestination call sites on the 3-arg form
Re-review: required (laravel-backend #1 #2; WooCommerce plugin owner #3)

## 2026-08-11 — Customer-area passwordless sign-in (SMS 019 + Google) — RE-REVIEW — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Clears: 2026-08-11 — Customer-area passwordless sign-in — VERDICT: BLOCKED
Scope: as above, plus app/Domain/Account/AccountServiceProvider.php (new),
  bootstrap/providers.php
Blocking cleared: #1 prunable() now uses acrossAllTenants() (sweep clean — no raw
  withoutGlobalScopes in app/); #2 model:prune scheduled 20 3 * * * via
  AccountServiceProvider, confirmed present in `artisan schedule:list`, and proven
  cross-tenant by test_the_prune_reaches_every_shops_rows (clears the tenant first);
  #3 checkout reads the shell config from the transient only and renders no panel on
  a miss — no synchronous LETS call on the money page
Suggestions applied: #4 verify spends a budget; #5 count_total=>false ×3; #6 bucket keyed
  IP+destination with a fixed (non-sliding) window; #7 panel branches on ok and reloads on
  an empty redirect; #8 wc_get_page_permalink guarded; #9 PhoneNumber::digits() shared as
  the fallback; #11 cursor replaced by a self-consuming NOT EXISTS set + $mark_empty;
  #12 docblock corrected; #13 request path refuses privileged users; #14 caps extended;
  #15 invalidateLive filters on channel
New this round: N1 per-destination bucketing removed the per-IP volume ceiling on the
  find_user path (SUGGEST); N2 orphaned docblock above shell_config_cached (NIT);
  N3 shortcode-rendered My Account loads the stylesheet in the footer (NIT)
Accepted as non-blocking: #10 index/unindexed duplicate-handset ambiguity — requires
  possession of the handset, and self-closes when the backfill completes
Verification: `artisan schedule:list` read by the reviewer; `artisan test tests/Feature/Account`
  run by the reviewer — 102 passed / 281 assertions, including both new prune tests
Re-review: not required

### Post-review follow-up (implementer, same day)
All three new findings closed rather than deferred:
- N1 — `lets_payplus_account_spend_budget()` now spends TWO fixed windows: the fine one
  (IP + destination, 8/hour) that protects the shopper, and a coarse one
  (`LETS_ACCOUNT_IP_CODE_LIMIT`, 60/hour, IP only) that protects the database from
  destination rotation. Verified with a stubbed-WordPress harness: one shopper is capped
  at 8, a second shopper behind the same CDN address is still served, case/whitespace
  variants share a bucket, the window does not slide, and 200 rotated destinations yield
  exactly 60 lookups.
- N2 — the `@return array{appearance…, login…}` docblock moved back onto `shell_config()`.
- N3 — `shell_assets()` now also fires where `[woocommerce_my_account]` is rendered on a
  page of the theme's choosing (`wc_post_content_has_shortcode`).

Implementer verification beyond the reviewer's: full suite 1080 passed / 3180 assertions;
the plugin's `lets_payplus_account_digits()` and `App\Support\PhoneNumber::digits()` proven
to agree on 17 spellings (including `972123456`, the case that motivated #9) by loading the
real plugin file behind WordPress stubs; the panel's rewritten script driven against a fake
DOM to confirm a 403 reads as "unreachable" (never "sent"), `ok:true` with an empty redirect
reloads instead of reporting a wrong code, Enter does not submit WooCommerce's login form,
and both panels on a page are wired.
