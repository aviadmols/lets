# 60 — Customer Portal + Thank-You Upsell Widget (Storefront, customer-facing)

> **Owner:** `product-ux-architect`. **Implemented by:** `admin-design-system` (storefront views) +
> `laravel-backend` (portal backend) + `shopify-integration` (thank-you render).
> **Reuses the engine:** `pps_installments.portal.show` route + `resources/views/portal/show.blade.php`,
> **`SignedUrlService::portalShowUrl()`** (signed magic link), `Storefront\ReturnController` (thank-you upsell),
> `resources/views/storefront/return.blade.php` + `modal.blade.php`.
> **Delta = multi-tenant (`shop_id`) + both `plan_kind`s + payment ledger/history + token-design.**
> **i18n domain:** `portal.*`, `upsell.*`, `states.*`. **RTL-first** (many customers are Hebrew speakers).
> **Pillars served:** installments + recurring (portal) · upsell (thank-you widget).

---

## Purpose
The customer-facing surface, reached **without login** via a signed magic link. The customer sees their
installment plans (balance, next charge, schedule) and recurring subscriptions (frequency, next charge), their
payment history, and — where the merchant allows — can pause/cancel and update their payment method. The same
storefront surface hosts the **thank-you upsell widget** (one-click accept on the saved token, with explicit
consent).

The engine's current `portal/show.blade.php` is a **single-installment-plan, RTL, hardcoded-style** view. This
spec re-skins it to tokens and **extends it to show all of the customer's plans across both `plan_kind`s** plus
payment history.

## Entry points (no admin nav)
1. **Signed magic link** — `SignedUrlService::portalShowUrl()` produces a signed URL (`plan`/`expires`/
   `signature`), optionally wrapped into the merchant's `portal_store_page_url`. Sent in lifecycle emails
   ("manage your plan") and generated on-demand from Customer Details ([20](20-customers.md)).
2. **Thank-you page** — `Storefront\ReturnController` renders after a completed purchase; hosts the upsell widget.
3. **(Later)** Shopify customer-account integration.

> **Signed-link scope (security):** a link resolves to a specific customer/plan context, `shop_id`-scoped. The
> portal must **never** show another customer's or shop's data. Expired/invalid links → the expired state below.

---

## Part A — Portal (manage plans)

### Layout (single column, mobile-first, RTL-first)

```
┌────────────────────────────────────────────┐
│            [ merchant logo ]                │  ← brand header (per-shop branding)
│  Your subscriptions & plans                 │
│ ──────────────────────────────────────────│
│  ┌── Installments plan · [active] ───────┐ │
│  │ Paid ₪600 of ₪1,200 · balance ₪600    │ │
│  │ ▓▓▓▓▓░░░░░  (progress)                 │ │
│  │ Next charge: 12 Jul · ₪300            │ │
│  │ Card: Visa •••• 4242                  │ │
│  │ [ Pay next ₪300 ] [ Pay full ₪600 ]   │ │  ← actions (engine pay-next/pay-full)
│  │ [ Cancel ]  (if merchant allows)       │ │
│  │ ▸ Schedule (deposit + installments)    │ │
│  └────────────────────────────────────────┘ │
│  ┌── Recurring · [active] ────────────────┐ │
│  │ Every 30 days · Next: 20 Jul · ₪89     │ │
│  │ Card: Visa •••• 4242                  │ │
│  │ [ Pause ] [ Cancel ]  (if allowed)     │ │
│  │ [ Update payment method ] (if supported)│ │  ← conditional, see capability flag
│  └────────────────────────────────────────┘ │
│  ▸ Payment history                          │  ← accordion: ledger (read-only)
│  ──────────────────────────────────────────│
│  Questions? Contact :support_email          │  ← support contact
└────────────────────────────────────────────┘
```

### Plan cards (component §4.11, customer-facing variant)
- **Installments:** paid/total, remaining balance, progress bar, next charge date + amount, masked card,
  schedule accordion (deposit + each installment with status), pay-next / pay-full (engine `PortalCheckoutService`),
  cancel (if allowed). Status badge uses the §4.2 map.
- **Recurring:** frequency, next charge date + amount, masked card, pause/cancel (if allowed), update payment
  method (**conditional — see capability flag**).

### Payment history (accordion §4.9)
Read-only list of the customer's charges for this plan (date, type, amount, status). Sourced from the
`payment_ledger` (`shop_id`-scoped). **No raw token, no `invoice_url`/`document_url`** (same rule as the admin
Timeline). A document, if the merchant issues one, is surfaced only as a customer-safe "receipt" link if and
where the merchant's DocumentPolicy exposes one — `TODO-DATA`.

### Data fields (source)
| Field | Source |
|---|---|
| Plans (kind, status, next charge, frequency) | plan models, `shop_id`-scoped via signed link context |
| paid/total, remaining balance, schedule | installment plan (engine: `total_amount`, `total_charged`, `payments`, `outstandingBalance()`) |
| Masked card (brand + last-4 + expiry) | `InstallmentPaymentMethod` (engine) — masked only |
| Payment history | `payment_ledger` | laravel-backend |
| Pay-next / pay-full amounts | `PortalCheckoutService::nextPaymentAmount()` (engine) |
| Pause/cancel allowed | per-shop `MerchantBillingSettings` ([50](50-settings.md) §4.7) |
| **Update-payment-method supported** | **PayPlus-flow capability flag — `TODO-DATA`/CAPABILITY (laravel-backend, plan §4.5)** |
| Support email | per-shop setting |
| Merchant branding (logo, accent) | per-shop branding (engine `BrandAssets`) |

### Actions
| Action | Trigger | Confirmation/consent | Side-effect |
|---|---|---|---|
| Pay next | installments card | **consent** (`portal.consent.pay_next`) | charge via saved reference; ledger + receipt |
| Pay full balance | installments card | **consent** (`portal.consent.pay_full`) | charge remaining; ledger; completes + releases order |
| Pause | recurring (if allowed) | `portal.confirm.pause` | status `active → paused`; ledger + Timeline |
| Cancel | either (if allowed) | `portal.confirm.cancel` (+ recurring sub-choice immediate/period-end, mirrors [30](30-subscriptions.md) D1) | status `→ cancelled`; ledger + DocumentPolicy + Shopify |
| Update payment method | recurring (**if supported**) | new-token capture flow | replaces `InstallmentPaymentMethod` |
| Contact support | footer | none | mailto / merchant support channel |

> **Pause/cancel only render if the merchant enabled them** (`allow_pause` / `allow_cancel` in §4.7). If
> disabled, show a line directing the customer to contact support (`portal.contact_to_change`).

### States
- **Loading** — card skeletons.
- **Empty** — link valid but the customer has no active plans: `portal.empty` ("You have no active plans.")
  + support contact.
- **Expired / invalid link** — `portal.link_expired`: "This link has expired. Request a new one." + a way to
  request a fresh link (re-trigger an email) — owned with `laravel-backend`.
- **Error** — `states.error.generic` + retry; an action failure shows inline `portal.action_failed` ("No charge
  was made.") and never leaves the customer unsure whether money moved.
- **Partial** — a pay-next just submitted but PayPlus webhook pending → the row shows "processing"
  (`portal.processing`) and the action buttons lock until confirmed (prevents double-pay).

### Update-payment-method (conditional — plan §4.5)
This is **flow-dependent**: whether a customer can re-vault a new token without a full re-checkout depends on the
PayPlus flow the shop uses. The button **only renders when the capability flag is true**. Otherwise it is hidden
and the customer is directed to contact support. **Flagged CAPABILITY for `laravel-backend` to confirm per flow —
see D1.**

---

## Part B — Thank-You Upsell Widget (`Storefront\ReturnController`)

Rendered on the post-purchase thank-you page after the first successful payment. The customer's PayPlus token is
already saved → one-click accept, **no card re-entry**.

### Layout (widget / modal — `storefront/return.blade.php` + `modal.blade.php`)
```
┌────────────────────────────────────────────┐
│  Add this to your order?                    │  ← merchant-set headline (i18n key per offer)
│  [ product image ]  Product B               │
│  ₪80  (was ₪100, 20% off)                   │
│  ─────────────────────────────────────────  │
│  ⓘ By accepting, your saved card will be    │  ← CONSENT DISCLOSURE (required, §4.16)
│     charged ₪80 now.                         │
│  [  Add to my order  ]   [ No thanks ]       │  ← one-click accept / decline
└────────────────────────────────────────────┘
```

### Required elements (ARCHITECTURE.md §4 + §4.3)
- **Headline + CTA** — merchant-authored per-offer i18n keys (`upsell.offer.{id}.headline` / `.cta`).
- **Consent disclosure (mandatory)** — `upsell.consent.disclosure` with `:amount`: "By accepting, your saved
  card will be charged :amount now." A `customer_consents` row is written on accept.
- **One-click accept** — posts to a **signed** `Storefront\AcceptUpsellController`. Idempotent
  (`upsell:{shop_id}:{flow_id}:{offer_id}:{parent_order_id}:{customer_id}`).
- **Double-click safety (UX)** — on click, the button immediately enters a **processing lock**
  (`upsell.processing`, spinner, disabled) so a second click cannot fire a second charge; the idempotency key is
  the backend guarantee, the lock is the UX guarantee.

### Actions
| Action | Trigger | Confirmation/consent | Side-effect |
|---|---|---|---|
| Accept (add to order) | "Add to my order" | **consent disclosure shown inline** (no extra modal) | write consent + `pending` ledger → charge saved token → child order (draft-completed-as-paid) → `charge_succeeded` event → next branch/offer |
| Decline | "No thanks" | none | `declined` event → next branch or end |

### States
- **Loading** — widget skeleton while the thank-you context resolves the active flow.
- **Empty / no offer** — no matching flow → render nothing (no empty widget; the thank-you page is clean).
- **Processing** — `upsell.processing` lock after accept (button disabled + spinner).
- **Success** — `upsell.accept_success` ("Added! Your card was charged :amount.") + optional next offer.
- **Error** — `upsell.accept_failed` ("We couldn't complete that charge. You were not charged.") — and the
  compensating-action note if a charge succeeded but the child order failed (backend handles; UX shows
  "we're finalizing your order" rather than a scary error). `TODO-DATA`: the exact compensating-state copy.

---

## i18n keys (en + he) — selected

| Key | EN | HE |
|---|---|---|
| `portal.title` | Your subscriptions & plans | המנויים והתוכניות שלך |
| `portal.plan.installments` | Installment plan | תוכנית תשלומים |
| `portal.plan.recurring` | Subscription | מנוי |
| `portal.paid_of_total` | Paid :paid of :total | שולם :paid מתוך :total |
| `portal.balance` | Balance | יתרה |
| `portal.next_charge` | Next charge | חיוב הבא |
| `portal.every_frequency` | Every :frequency | כל :frequency |
| `portal.card` | Card | כרטיס |
| `portal.pay_next` | Pay next :amount | תשלום הבא :amount |
| `portal.pay_full` | Pay full balance :amount | תשלום מלא של היתרה :amount |
| `portal.schedule` | Schedule | לוח תשלומים |
| `portal.payment_history` | Payment history | היסטוריית תשלומים |
| `portal.pause` | Pause | השהיה |
| `portal.cancel` | Cancel | ביטול |
| `portal.update_payment_method` | Update payment method | עדכון אמצעי תשלום |
| `portal.contact_to_change` | To change this plan, contact us. | לשינוי התוכנית, צרו קשר. |
| `portal.consent.pay_next` | Your saved card will be charged :amount now. | הכרטיס השמור שלך יחויב ב-:amount כעת. |
| `portal.consent.pay_full` | Your saved card will be charged :amount to settle the balance. | הכרטיס השמור שלך יחויב ב-:amount לסילוק היתרה. |
| `portal.confirm.pause` | Pause this subscription? | להשהות את המנוי? |
| `portal.confirm.cancel` | Cancel this subscription? | לבטל את המנוי? |
| `portal.processing` | Processing… | מעבד… |
| `portal.action_failed` | That didn't go through. No charge was made. | הפעולה לא הושלמה. לא בוצע חיוב. |
| `portal.empty` | You have no active plans. | אין לך תוכניות פעילות. |
| `portal.link_expired` | This link has expired. Request a new one. | הקישור פג תוקף. בקשו קישור חדש. |
| `portal.support` | Questions? Contact :support_email | שאלות? פנו אל :support_email |
| `upsell.consent.disclosure` | By accepting, your saved card will be charged :amount now. | באישור, הכרטיס השמור שלך יחויב ב-:amount כעת. |
| `upsell.offer.add` | Add to my order | הוסף להזמנה שלי |
| `upsell.offer.decline` | No thanks | לא תודה |
| `upsell.processing` | Adding to your order… | מוסיף להזמנה שלך… |
| `upsell.accept_success` | Added! Your card was charged :amount. | נוסף! הכרטיס שלך חויב ב-:amount. |
| `upsell.accept_failed` | We couldn't complete that charge. You were not charged. | לא הצלחנו להשלים את החיוב. לא חויבת. |

> **Currency** is locale-formatted (ILS, `₪` per HE convention). The customer's locale drives the portal
> language; the merchant's branding (logo/accent) comes from per-shop settings.

---

## RTL notes (RTL-FIRST surface)
- The portal is **RTL by default** (the engine view is already `dir="rtl" lang="he"`); LTR is the flip for
  English-speaking customers — opposite emphasis to the admin.
- Installments progress bar fills from the **right**; the schedule table reads RTL.
- Masked card string (`Visa •••• 4242`) and amounts stay LTR inside RTL cards.
- Pay-next/pay-full/cancel buttons stack full-width (mobile-first); their order follows reading direction.
- The thank-you widget consent line keeps `:amount` LTR-internal within an RTL sentence.

## Plan-gate behavior
- The **portal** is core (every tier) — customers of any merchant must be able to manage plans.
- The **thank-you upsell widget** only renders if the shop's upsell pillar is unlocked (Pro, `TODO-GATE`) **and**
  a matching flow is live. A locked shop simply renders no widget (no customer-facing upgrade prompt).

## Definition of Done
- **Platform DoF:** portal reachable via signed magic link showing both `plan_kind`s, balance, next charge,
  history, allowed pause/cancel; four states (incl. expired link); EN/HE + RTL-first.
- **Upsell DoF:** thank-you widget render with headline/CTA + **consent disclosure**; idempotent one-click accept
  with processing lock; four states.
Linked in [99-i18n-conventions.md](99-i18n-conventions.md).

---

## Open decisions for Aviad (ASK)
- **D1 — Payment-method update capability.** Does the PayPlus flow these merchants use support re-vaulting a new
  token without a full re-checkout? If not, the "Update payment method" button is hidden and customers contact
  support. **CAPABILITY flag — confirm with `laravel-backend` (plan §4.5).**
- **D2 — Recurring cancel default in portal** — mirror [30](30-subscriptions.md) D1 (immediate vs period-end).
  Keep portal + admin consistent.
- **D3 — Customer-facing receipt/document link.** Should the portal expose a customer-safe receipt link where the
  merchant's DocumentPolicy issues one? (Never the raw `invoice_url`.) Recommend: surface a sanitized receipt link
  only if DocumentPolicy provides a customer-safe URL. Confirm.
