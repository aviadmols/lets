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
