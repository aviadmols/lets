# Review log — two-factor sign-in, security headers, admin polish

## 2026-09-27 — 2FA + security headers + admin polish — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope: 2FA (Login, TwoFactorChallenge, TwoFactorSecurity, TwoFactorAuthenticator, PendingTwoFactorLogin, RequireTwoFactorEnrollment, ResetTwoFactor, Horizon gate, migration, lang, views, css), AddSecurityHeaders, ManageLoyalty KPI strip, sidebar-shop, CSS
Blocking: none
Suggestions: #1 per-user challenge throttle, #2 TOTP/recovery race, #3 rate-limit Security page, #4 freshRecoveryCodes in snapshot, #5 sanitize woocommerce_domain in CSP, #6 per-shop Shopify frame-ancestors, #7 enrol admins at deploy, #8 https-only return origin
Nits: command-name comment, raw #FFFFFF, clubStats per render, raw px
Re-review: not required

### Applied (same day)
- #1 per-user lock: 5 wrong codes (any IP) drop the pending login for 15 min — tested.
- #2 compare-and-set on the stored step and on the recovery-code list.
- #3 Security page code checks throttled (5/min).
- #4 fresh recovery codes cleared from page state on the next action.
- #5 + #8 hosts matched against HOST_PATTERN; return origin https only.
- Nits: command name, QR background token.
- Found by the full suite: the global Referrer-Policy overwrote the passwordless
  login landing's `no-referrer` — now a default only, never a loosening.

### Deferred
- #6 per-shop Shopify frame-ancestors: `*.myshopify.com` kept so a first load
  before the tenant is bound is never refused a frame; tighten once verified live.
- #7 operational: enrol every platform admin right after deploy.

## 2026-09-27 — security-fix merges 0627074..26e4b9d — VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Blocking: #1 migrations-before-workers, #2 IPN shape/list paths + gateway own page id, #3 X-Forwarded-Host vs trustHosts, #4 guest shoppers get no Shopify upsell (needs sign-off)
Suggestions: post-purchase iss/iat live check, stale refund claim in remainingOn, plugin key wall on verify, IDN store hosts, base_url fallback logging, card-update own page id, toml compliance deploy
Re-review: required

## 2026-09-27 — re-review (914d8b7) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Clears: #1 bounded fail-closed wait in predeploy for worker/scheduler; #2 own-page binding (WooGatewayPageRegistry) + normaliseIpn; #3 trustHosts reverted, no dangling refs; #4 user chose to keep guests off the Shopify thank-you offer
Suggestions (applied same day): nested data[0].transaction folded; several pages kept per order (older tab pays); an unsigned decline recorded only when PayPlus's own record carries a transaction code
Verified separately: Shopify's post-purchase JWT spec states iss is always the literal "shopify"
Re-review: not required
