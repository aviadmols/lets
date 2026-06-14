# 50 — Settings

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system`.
> **Reuses the engine:** `Filament/Pages/ManageMailSettings.php` + `Settings/MailSettings` (per-template editor +
> live preview), `HtmlCodeEditor`. **Per-shop creds + billing settings:** `laravel-backend` +
> `saas-multitenancy-billing`. **Tokens:** [00-design-system.md](00-design-system.md).
> **i18n domain:** `settings.*`, `validation.*`, `actions.*`, `states.*`.
> **Pillars served:** platform (configures all three).

---

## Purpose
Per-shop configuration: connect PayPlus + Shopify, set the merchant billing rules that govern every charge,
edit lifecycle emails, manage the app subscription tier, and the standard payment/shipping/legal/order-processing
cards. **Tenant-safe:** every value is `shop_id`-scoped; no global config leaks across shops.

## Entry points / nav
`Settings` (`nav.settings`). The **Email** nav item and the upsell **Settings** tab deep-link here.

## Layout — sectioned settings (Recharge-style cards / left section nav)

```
Settings
┌── section nav ──┐ ┌──────────────────────────────────────────────┐
│ PayPlus Connection│ │  [ active section content ]                  │
│ Shopify           │ │                                              │
│ Payment           │ │                                              │
│ Shipping          │ │                                              │
│ Legal             │ │                                              │
│ Order processing  │ │                                              │
│ Merchant Billing  │ │                                              │
│ Mail Settings   ▸ │ │                                              │
│ Plan & Billing    │ │                                              │
│ Notifications     │ │                                              │
└───────────────────┘ └──────────────────────────────────────────────┘
```

Every section uses the **contextual save bar** (Polaris-style): edits enable a "Save / Discard" bar; nothing
persists until saved. Validation errors block save with inline `validation.*` copy.

---

## Section 1 — PayPlus Connection (critical, per-shop, ARCHITECTURE.md "Per-shop credentials")

The merchant pastes **their own** PayPlus account credentials. Stored **encrypted** on the `shops` row via a
dedicated cast (independent of `APP_KEY`). `PayPlusGatewayFactory::for($shop)` uses exactly these.

### Fields
| Field | Control | Required | Sensitive | Notes |
|---|---|---|---|---|
| `api_key` | text (masked after save) | yes | yes | shown masked once saved; "Reveal"/"Replace" affordance |
| `secret_key` | text (masked after save) | yes | yes | never re-displayed in full after save |
| `terminal_uid` | text | yes | no | |
| `cashier_uid` | text | yes | no | |
| `payment_page_uid` | text | yes | no | |
| `base_url` | select/text | yes | no | production vs sandbox toggle (env defaults `PAYPLUS_BASE_URL_*`) |
| `webhook_secret` | text (masked) | yes | yes | per-shop HMAC secret for PayPlus callbacks |

### "Test connection" button (component §4.5 primary CTA)
- Calls a backend test (`laravel-backend`) that validates the credentials against PayPlus **without** charging.
- **States:** idle · loading (spinner, button disabled) · **success** (green check + `settings.payplus.test_ok`)
  · **failure** (red + `settings.payplus.test_fail` + the returned error reason, masked).
- A connection-status badge (§4.2) sits at the top: `connected` (green) / `not_connected` (gray) /
  `error` (red, last test failed).

### Data fields / source
| Field | Source |
|---|---|
| credential values (masked) | `Shop.payplus_credentials` encrypted JSON (laravel-backend) |
| connection status | last test result + presence of creds | laravel-backend · TODO-DATA (status field) |

### States
- **Empty (first-run)** — no creds yet: connection badge `not_connected`; the section is the **onboarding focus**
  (Home banner links here). `settings.payplus.empty`.
- **Loading** — field skeletons while decrypting masked previews.
- **Error** — save/test error banner + retry; never echo the secret back in an error.
- **Saved** — masked fields + "Replace" affordance + connection badge updates after a Test.

### RTL notes
- Credential values + URLs stay **LTR** even in HE (they are Latin/ASCII); labels + help are RTL.
- "Test connection" button moves to start-side.

---

## Section 2 — Shopify
Read-mostly: connected shop domain, granted scopes (minimal + documented, §7.1), reinstall/uninstall status,
webhook health. Owned jointly with `shopify-integration` + `saas-multitenancy-billing`. (Specced lightly here;
full screen tracked by those agents.)

---

## Sections 3–6 — Payment / Shipping / Legal / Order processing (standard cards)

| Section | Cards / fields | Source |
|---|---|---|
| **Payment** | accepted methods (read-only — PayPlus), currency (ILS default), tax display | shop settings |
| **Shipping** | whether subscription/recurring orders inherit storefront shipping; flat override | shop settings · TODO-DATA |
| **Legal** | terms of service URL, privacy policy URL, **terms version** (drives `customer_consents.accepted_terms_version`), cancellation policy text | per-shop (§4.7) |
| **Order processing** | order tag conventions (`payment_pending`, `installment_plan_active`), fulfillment-lock default, draft-order strategy default | per-shop (§4.7) + `shopify-integration` strategy |

---

## Section 7 — Merchant Billing Settings (ARCHITECTURE.md §4.7 — no hardcoding)

The rules that govern **every** charge for this shop. All `shop_id`-scoped (Spatie settings).

| Setting | Control | Default | Drives |
|---|---|---|---|
| Retry policy | preset/editable backoff list | `[4h, 24h, 72h]` (engine) | failed-charge retries |
| Failed-payment grace period | number (days) | per-shop | when a failed plan moves to `failed`/dunning |
| Minimum deposit amount | number (ILS) | per-shop | installment plan creation validation |
| Allowed installment frequencies | multiselect | per-shop | plan/portal options |
| Max number of installments | number | per-shop | installment plan validation |
| Fulfillment locked for installments | toggle | on | `FulfillmentLockService` |
| Customers can pause subscriptions | toggle | per-shop | portal pause availability ([60](60-customer-portal.md)) |
| Customers can cancel subscriptions | toggle | per-shop | portal cancel availability |
| Cancellation policy text | textarea | per-shop | `customer_consents.cancellation_policy_snapshot` + consent UI |
| Terms version | text/auto | per-shop | consent record |
| Support email | email | per-shop | email engine sender + portal "contact support" |
| DocumentPolicy preferences | grouped controls (per context: deposit/installment/final/recurring/upsell/refund/cancellation) | per-shop | `DocumentPolicy` outputs (§4.2) |
| Default upsell order strategy | radio (child order [default] / order-edit where supported) | child order | upsell pillar (mirrors [40](40-post-purchase-offers.md) Settings tab) |

### Data fields / source
All from a per-shop `MerchantBillingSettings` (Spatie, `shop_id`-scoped) — `laravel-backend`. Several fields are
already implied by the engine (retry `[4h,24h,72h]`, fulfillment lock); the rest are new per-shop settings.

### States
- **Loading** — form skeleton.
- **Validation error** — inline `validation.*` (e.g. min deposit ≤ 0, max installments < 1).
- **Saved** — save bar confirmation; some changes (retry policy) note "applies to future charges only".
- **Error** — save error banner + retry.

### RTL notes
- Numeric inputs (deposit, installments) stay LTR; labels RTL.
- The DocumentPolicy per-context grid mirrors column order.

---

## Section 8 — Mail Settings (reuse `ManageMailSettings`)

**Pointer + delta, not a rewrite.** The engine's `ManageMailSettings` already provides a per-template editor
(subject + HTML body via `HtmlCodeEditor`/CodeMirror) with **live preview** (isolated `iframe srcdoc` +
`htmlspecialchars`, `strtr()` substitution — **never `Blade::render()` on merchant input**). Re-skin to tokens
and make it **per-shop** (`MailSettings` + SMTP override per shop).

### Templates (engine + extended)
plan created / first-payment welcome · upcoming-charge reminder · manual payment link · charge failed ·
retry scheduled · final installment completed / fulfillment released · subscription paused/cancelled ·
upsell charge succeeded/failed.

### Per-template editor
| Element | Notes |
|---|---|
| Subject | merchant-editable, supports `{{placeholders}}` |
| Body (HTML) | `HtmlCodeEditor`; **inline CSS allowed here** (email exception, CLAUDE.md §10) |
| Placeholder reference | the `{{first_name}}`, `{{amount}}`, `{{next_charge_date}}`… token list (substituted via `strtr()`) — **distinct from UI `__()` keys** (see [99-i18n-conventions.md](99-i18n-conventions.md#two-string-systems)) |
| Live preview | isolated iframe; renders with sample data; never executes merchant HTML as Blade |
| Per-shop SMTP override | optional sender override, merged at runtime (`mergeMailSettingsIntoConfig`) |

### States
- **Loading** — editor + preview skeleton.
- **Empty** — a template not yet customized shows the default (from `DefaultEmailTemplates`).
- **Error** — invalid HTML / send-test failure banner.
- **Preview** — always available; the preview is read-only and sandboxed.

---

## Section 9 — Plan & Billing (App Store flat tiers)

Owned by `saas-multitenancy-billing`; specced here for the UX surface.
- Current tier (Starter / Growth / Pro) + price + trial status.
- Usage vs gate (e.g. active subscriptions used / limit; post-purchase flows on/off).
- **Upgrade / Downgrade** CTA → Shopify AppSubscription confirmation flow.
- **Locked-feature states** referenced from gated screens land here.

### States
- **Loading** — plan card skeleton.
- **Gate-approaching** — `settings.billing.near_limit` warning when usage nears the cap.
- **Over-limit** — blocks new creation with an upgrade CTA (the creation-gate referenced by [30](30-subscriptions.md)).
- **Error** — billing-API error + retry.

---

## Section 10 — Notifications
Per-shop toggles for which lifecycle emails send (maps to Mail Settings templates) + webhook/event hooks
(Klaviyo/Make/Zapier) placeholder (later). SMS/WhatsApp gated/coming-soon.

---

## i18n keys (en + he) — selected

| Key | EN | HE |
|---|---|---|
| `settings.title` | Settings | הגדרות |
| `settings.section.payplus` | PayPlus Connection | חיבור PayPlus |
| `settings.section.shopify` | Shopify | Shopify |
| `settings.section.payment` | Payment | תשלום |
| `settings.section.shipping` | Shipping | משלוח |
| `settings.section.legal` | Legal | משפטי |
| `settings.section.order_processing` | Order processing | עיבוד הזמנות |
| `settings.section.merchant_billing` | Merchant billing | חיוב סוחר |
| `settings.section.mail` | Mail settings | הגדרות דואר |
| `settings.section.plan_billing` | Plan & billing | תוכנית וחיוב |
| `settings.section.notifications` | Notifications | התראות |
| `settings.payplus.api_key` | API key | מפתח API |
| `settings.payplus.secret_key` | Secret key | מפתח סודי |
| `settings.payplus.terminal_uid` | Terminal UID | מזהה מסוף |
| `settings.payplus.cashier_uid` | Cashier UID | מזהה קופה |
| `settings.payplus.payment_page_uid` | Payment page UID | מזהה עמוד תשלום |
| `settings.payplus.base_url` | API base URL | כתובת בסיס API |
| `settings.payplus.webhook_secret` | Webhook secret | סוד Webhook |
| `settings.payplus.test` | Test connection | בדיקת חיבור |
| `settings.payplus.test_ok` | Connection successful. | החיבור הצליח. |
| `settings.payplus.test_fail` | Connection failed: :reason | החיבור נכשל: :reason |
| `settings.payplus.status.connected` | Connected | מחובר |
| `settings.payplus.status.not_connected` | Not connected | לא מחובר |
| `settings.payplus.status.error` | Connection error | שגיאת חיבור |
| `settings.payplus.empty` | Connect your PayPlus account to start charging. | חברו את חשבון PayPlus כדי להתחיל לחייב. |
| `settings.billing.retry_policy` | Retry policy | מדיניות ניסיון חוזר |
| `settings.billing.grace_period` | Failed-payment grace period | תקופת חסד לתשלום שנכשל |
| `settings.billing.min_deposit` | Minimum deposit | מקדמה מינימלית |
| `settings.billing.allowed_frequencies` | Allowed frequencies | תדירויות מותרות |
| `settings.billing.max_installments` | Maximum installments | מספר תשלומים מרבי |
| `settings.billing.fulfillment_lock` | Lock fulfillment until fully paid | נעילת מימוש עד לתשלום מלא |
| `settings.billing.allow_pause` | Customers can pause | לקוחות יכולים להשהות |
| `settings.billing.allow_cancel` | Customers can cancel | לקוחות יכולים לבטל |
| `settings.billing.cancellation_policy` | Cancellation policy | מדיניות ביטול |
| `settings.billing.support_email` | Support email | אימייל תמיכה |
| `settings.billing.default_upsell_strategy` | Default upsell order strategy | אסטרטגיית הזמנה לברירת מחדל בהצעות |
| `settings.mail.subject` | Subject | נושא |
| `settings.mail.body` | Email body | גוף ההודעה |
| `settings.mail.preview` | Preview | תצוגה מקדימה |
| `settings.billing.near_limit` | You're close to your plan limit. | אתם מתקרבים למגבלת התוכנית. |
| `validation.min_deposit_positive` | Minimum deposit must be greater than zero. | המקדמה המינימלית חייבת להיות גדולה מאפס. |
| `validation.max_installments_min` | Allow at least one installment. | יש לאפשר לפחות תשלום אחד. |
| `validation.required_field` | This field is required. | שדה חובה. |

---

## RTL notes
- Section nav moves to the start-side (right in HE).
- Credential fields, URLs, and the SMTP host stay LTR; everything else RTL.
- Email-editor (CodeMirror) content is the merchant's HTML — direction follows their content, not the admin locale.
- The DocumentPolicy per-context grid + save bar mirror.

## Plan-gate behavior
- All settings sections are available on every tier (a shop must be able to connect + configure regardless).
- **Plan & Billing** shows the current tier + gates; **Default upsell strategy** is only actionable if the
  upsell pillar is unlocked (Pro) — otherwise it shows the locked state pointing to Plan & Billing.

## Definition of Done
Platform DoF: Settings with **PayPlus Connection + "Test connection"** (per-shop encrypted creds), **Merchant
Billing** (§4.7 full field set), **Mail** (reused per-template editor + live preview, `strtr()` not Blade),
**Plan & Billing** gate states; four states per section; EN/HE + RTL. Linked in
[99-i18n-conventions.md](99-i18n-conventions.md#platform).

---

## Open decisions for Aviad (ASK)
- **D1 — Reveal secrets after save?** Recommend: never re-display `api_key`/`secret_key`/`webhook_secret` in full;
  offer "Replace" only (paste new). Confirm (some merchants want a reveal-with-confirmation).
- **D2 — DocumentPolicy UI granularity.** Per-context document toggles (deposit/installment/final/recurring/
  upsell/refund/cancellation) vs a simpler "issue documents: yes/no + final-only". Recommend per-context grid for
  power, with sensible defaults. Confirm depth so `laravel-backend` shapes the settings.
- **D3 — Sandbox vs production toggle placement.** On PayPlus Connection (`base_url`) or a global env flag?
  Recommend per-shop `base_url` select (sandbox/production) so test shops are isolated. Confirm.
