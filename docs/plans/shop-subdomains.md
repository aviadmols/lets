# Work plan — one subdomain per shop (`<handle>.app.lets.co.il`)

Status: PROPOSED (2026-10-02). Not started. Owner decisions are listed at the end.

## Goal

Every shop gets a dedicated address for its admin, e.g. `https://tracki-inc-sp.app.lets.co.il`,
so a merchant (or a platform admin) opens one URL and lands in that store. `app.lets.co.il`
stays the platform root: the login chooser, the platform-admin screens, and every machine
endpoint (webhooks, PayPlus callbacks, the plugin API, OAuth, the embedded app).

## What moves and what does not

| Surface | Host after the change | Why |
|---|---|---|
| Admin panel (direct, non-embedded), login, 2FA, password reset | `<handle>.app.lets.co.il` | The whole point: one URL per store. |
| Platform-admin screens (shops list, platform settings, Horizon) | `app.lets.co.il` | They are not a shop's. |
| Shopify embedded admin (App Bridge iframe) | `app.lets.co.il` (unchanged) | `shopify.app.toml` has ONE `application_url`; Shopify loads it inside the iframe. Nothing is gained by moving it. |
| WooCommerce wp-admin embed (`/embed/woocommerce/{token}`) | `app.lets.co.il` (unchanged) | The plugin hard-codes the base; the embed is already a per-shop one-shot link. |
| Shopify OAuth + webhooks, PayPlus callbacks + return pages, plugin API, app proxy, post-purchase/thank-you endpoints, storefront assets | `app.lets.co.il` (unchanged) | Registered with third parties; must not change. |
| Hosted customer account page, loyalty page | `app.lets.co.il` (unchanged) | Optional later phase (5). |

## Design

### 1. The handle
- New column `shops.handle`: lowercase `[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?`, UNIQUE, never null.
- Derived at install, never typed by a shopper: Shopify → the myshopify handle
  (`tracki-inc-sp.myshopify.com` → `tracki-inc-sp`); WooCommerce → the store domain with dots
  turned to dashes (`sellameir.ussl.co` → `sellameir-ussl-co`), the merchant may shorten it in
  onboarding. Collisions get a numeric suffix. Backfill migration for every existing shop.
- Reserved handles refused: `www, app, api, admin, platform, mail, static, assets, cdn, status,
  help, docs, embed, proxy, horizon, lp` and any single/dual-letter.
- A platform admin may change a handle; the old one is kept in `shop_handle_aliases` and
  redirects (301) to the new host for 30 days.

### 2. Resolving the shop from the host
- New middleware `ResolveShopFromHost`, first in the panel's persistent stack: on a host matching
  `*.app.lets.co.il` it looks the handle up (cached per handle, 5 min) and records the
  *requested shop* on the request. Unknown handle → a plain 404 page ("no such store"), never
  the login form. `app.lets.co.il` → no requested shop.
- Tenant binding stays exactly what it is (`BindTenantFromUser`: user → shop). The host adds a
  WALL, not a source of truth:
  - a merchant whose `shop_id` ≠ the requested shop is signed out of that host and sent to
    their own subdomain (never shown the other shop's login as if it were theirs);
  - a platform admin on a shop host is treated as "entered" into that shop for the request
    (replacing the session-stored `PlatformContext` entry — the host IS the entry). Two tabs on
    two shops can no longer share one entered-shop state, which closes a real foot-gun today;
  - a platform admin on `app.lets.co.il` sees the shops list; "Open" links to the subdomain.
- After a successful password/2FA login on `app.lets.co.il`, a merchant is redirected to their
  subdomain; a login on a shop host stays there.
- `trustHosts` comes back, pinned to `app.lets.co.il`, `*.app.lets.co.il`, the Railway hosts —
  WITHOUT trusting `X-Forwarded-Host` (that combination 404'd the App Proxy in review; the host
  check is on the real Host header only).

### 3. Sessions and cookies
- `SESSION_DOMAIN=.app.lets.co.il` so ONE login serves every subdomain (a platform admin signs in
  once; a merchant with two stores too). Isolation does not depend on the cookie: every request
  re-checks user ↔ host. Keep `secure`, `SameSite=None`, `partitioned` as they are (the
  embedded iframes need them; top-level pages are unaffected).
- Regenerate the session on every cross-host redirect after login (fixation).
- The 2FA pending state, remember-me and CSRF tokens work unchanged under the shared domain.
- Alternative (owner decision A): host-only cookies = a separate login per store.

### 4. URLs, links and email
- `APP_URL` stays `https://app.lets.co.il` — it feeds every machine URL (callbacks, webhooks,
  the plugin, the toml). The panel builds its links from the current request host (Laravel's
  default), so nothing in Blade changes.
- Emails that lead to the admin (password reset, team invite, "card update link created",
  campaign test sends, analytics export links) are built on the shop's host when the request
  came from one, else on `app.lets.co.il` + a post-login redirect.
- Onboarding (Shopify install, WooCommerce connect) ends by showing the store's URL.

### 5. Infrastructure
- DNS (lets.co.il zone): `*.app.lets.co.il CNAME qscc7xky.up.railway.app` (DNS-only, like
  `app.lets.co.il` today). Plus whatever `_acme-challenge` record Railway asks for.
- Railway → web service → Settings → Networking → add custom domain `*.app.lets.co.il`.
  Railway issues the wildcard certificate; confirm the plan supports wildcard domains before
  the DNS change (it is a dashboard check, not a code change).
- Verify with `curl -I https://anything.app.lets.co.il/up` → 200 and a valid certificate.
- HSTS stays per host (no `includeSubDomains`, as today — sibling `*.lets.co.il` hosts are not
  ours).

### 6. Security
- Fail closed everywhere: unknown host → 404; user/host mismatch → signed out + redirected;
  no shop id is ever read from the request to pick a tenant.
- CSP `frame-ancestors` per shop already exists; on a shop host it is that shop's.
- Signed URLs already validate relative to the path (`ValidateSignature::relative()`), so a
  link made on one host verifies on another; the download controller still checks the user's
  shop.
- Rate limits stay per IP/user; add the host to the auth logs.
- The code-review gate (tenant safety) runs on every phase; a cross-host tenant test suite is
  the release blocker.

## Phases (estimates for one engineer + agents)

| # | Phase | Deliverables | Size |
|---|---|---|---|
| 0 | Ops | DNS wildcard, Railway wildcard domain + certificate, `curl` proof | 1 h (owner's DNS + Railway access) |
| 1 | Handle | migration + backfill + reserved list + uniqueness; platform-admin edit with aliases; tests | ½ day |
| 2 | Resolution | `ResolveShopFromHost`, user/host wall, platform-admin auto-enter from host, login redirects, shops list "Open" links, shop switcher → subdomain links, `trustHosts`, `SESSION_DOMAIN`; cross-host tenant tests | 1 day |
| 3 | Links & onboarding | emails on the shop host, onboarding shows the URL, docs | ½ day |
| 4 | Hardening & release | review gate, full suite + Postgres run, deploy, smoke test on two real stores, memory/docs | ½ day |
| 5 | Optional | customer account + loyalty pages on the shop host | later |

Local development: `<handle>.app.lets.localhost` resolves to 127.0.0.1 in Chrome/Edge without
hosts-file edits; tests set `HTTP_HOST` per case.

## Risks
- Railway wildcard-domain availability / certificate issuance — checked in phase 0 before any code.
- A changed handle breaks bookmarks — aliases + 301 for 30 days.
- Anything that today reads `PlatformContext::enteredShopId()` from the session must prefer the
  host — grep and migrate every reader in phase 2.
- `SESSION_DOMAIN` change signs everyone out once at deploy (announce it).

## Owner decisions
- **A.** One shared login across all subdomains (recommended) or a separate login per store?
- **B.** WooCommerce handle: derived from the store domain, or chosen by the merchant at connect?
- **C.** Should the customer-facing account and loyalty pages move to the shop host too (phase 5)?
