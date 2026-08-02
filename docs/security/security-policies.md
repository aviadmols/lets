# LETS — Security Policies

> The written policies behind the answers LETS gives on Shopify's Protected
> Customer Data security questionnaire. Each section names the concrete
> mechanism in this repository or in the hosting platform that makes the claim
> true — a policy that points at nothing is marketing, not policy.
>
> Owner: Aviad. Review on every App Store submission and at least twice a year.

## 1. Backup encryption

Production runs on **Railway managed PostgreSQL**. Railway volumes — including
database storage and its snapshots/backups — are **encrypted at rest** by the
platform. No plaintext database dumps are exported to laptops or third-party
storage; ad-hoc dumps for debugging are prohibited by this policy.

On top of at-rest encryption, the *most sensitive columns are encrypted at the
application layer* (Laravel `encrypted` casts, key = `APP_KEY`): Shopify
access/refresh tokens, per-shop PayPlus credentials, WooCommerce REST
credentials, and invoicing (Green Invoice / Morning) credentials — see
`app/Models/Shop.php`. A stolen backup without `APP_KEY` yields no usable
gateway or store credentials.

## 2. Test / production data separation

- Development and CI run against **local databases** (per-machine Postgres /
  SQLite); the test suite uses `RefreshDatabase` on a local connection. No test
  or staging environment ever points at production data.
- **`App\Support\DestructiveCommandGuard`** refuses `migrate:fresh`,
  `migrate:refresh`, `migrate:reset` and `db:wipe` against any connection that
  does not resolve to a local database — judged **after** URL parsing, so a
  local `.env` pointing at production is still blocked. Override requires the
  deliberate `ALLOW_DESTRUCTIVE_DB=true` environment variable, never a CLI flag.
- Production data is never copied down for testing. Test fixtures are synthetic.

## 3. Data-loss prevention strategy

Layered:

1. **Railway automated backups** of the managed Postgres (platform-side,
   encrypted — §1).
2. **Append-only money records**: `payment_ledger` rows and `activity_events`
   are written once and never deleted by product code; refunds and corrections
   are new rows, not edits.
3. **The destructive-command guard** (§2) — the historical incident it encodes
   (a `--database=sqlite` flag that dropped a live Postgres) cannot recur.
4. **Tenant-scoped deletes only**: no product code path deletes across shops;
   GDPR redaction jobs (`app/Jobs/Privacy/*`) target one shop/customer and
   anonymise rather than drop billing records the merchant is legally required
   to keep.

## 4. Staff password policy

Enforced in code, not by convention — `AppServiceProvider::boot()` sets the
application-wide `Password::defaults()`: **minimum 12 characters, letters,
mixed case, numbers, and rejection of passwords found in known breach corpora**
(`uncompromised()`, k-anonymity range query). Every password set through the
app — merchant accounts claimed via the reset flow, platform admins, profile
changes — validates against this rule. Accounts are provisioned with random
40-character placeholders and claimed via the reset flow; no password is ever
generated for, printed to, or emailed to a human.

## 5. Limiting and logging access to personal data

**Limiting.** Every tenant-owned row carries `shop_id` with a global scope
(`BelongsToShop`) that fails closed — one merchant can never read another's
customers (release-blocker rule, continuously tested). Platform admins see
personal data only after explicitly ENTERING a shop; every action they take is
attributed on the Timeline as `platform_admin:{id}` (see
`Support\Timeline`). Customer personal data is deliberately **not warehoused**:
contact details are read live from the store and written straight back, so the
app holds the minimum copy (checkout-captured name/email/phone on the plan).

**Logging.** Reads of a customer's contact details and order history emit a
structured `privacy.personal_data_accessed` log line (shop, customer reference,
surface, acting user) — `CustomerContactReader` / `CustomerOrdersReader`.
Writes are Timeline events (`customer_details_updated`) with actor attribution.
GDPR webhook handling (`customers/redact`, `shop/redact`,
`customers/data_request`) is recorded as its own ActivityEvent kinds.

## 6. Security incident response policy

**Scope**: any suspected unauthorized access, data leak, credential exposure or
integrity failure affecting merchant or shopper data.

1. **Detect & triage (immediately).** Structured logs (`privacy.*`,
   `*.mutation_rejected`, gateway/webhook failures) and Railway alerts are the
   entry points. The on-call owner (Aviad) assesses scope: which shops, which
   data classes, ongoing or contained.
2. **Contain (same day).** Rotate the affected secret(s): `APP_KEY` re-encrypt
   cycle, per-shop PayPlus/Woo credentials (re-issued by the merchant), Shopify
   tokens (reinstall/re-auth). Revoke platform-admin sessions. If a code path
   is the vector, disable the surface (feature flag / deploy) before fixing it.
3. **Assess & record.** Reconstruct the window and the accessed data from the
   append-only logs (§5). Every step of the response is written down as it
   happens.
4. **Notify (within 72 hours).** Affected merchants are notified with what was
   accessed and what was done; Shopify Partner Support is notified for any
   incident touching Shopify customer data (per the Protected Customer Data
   requirements); GDPR's 72-hour supervisory-authority window applies to EU
   data subjects.
5. **Post-mortem (within 2 weeks).** Root cause, the guard that will make the
   class of incident impossible (in the spirit of `DestructiveCommandGuard`),
   and a test that fails without it.

## 7. Audits and certifications

None yet (answer that question honestly: leave it empty or state "none to
date"). Planned: a third-party review before App Store submission of the
public app.
