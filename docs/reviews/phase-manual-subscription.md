# Code review log — adding a subscription by hand, without payment (append-only)

---

## 2026-09-23 — R1 (the form, the service, and the promise it could not keep)

**Scope.** `ManualSubscriptionService`, `NewSubscription` (the form),
`ProductOptions` (the picker, extracted from `ViewSubscription`), the
`newSubscription` action on `ListSubscriptions`, the `plan_created_manually`
Timeline kind, `lang/{en,he}/{subscriptions,timeline}.php`,
`tests/Feature/Subscriptions/CreateSubscriptionTest.php`.

**What it is for.** A comped member, a staff subscription, a gift, a subscriber
whose money is collected somewhere else. Until now the only way to put one in
was a CSV import, and every storefront path refuses it outright:
`RecurringPlanService` throws on an amount of zero at all three entry points,
because a CHECKOUT for nothing is a bug.

**VERDICT R1: BLOCKED.** Four money-safety findings, all one mistake.

The first version made "this subscription is free" out of a SHAPE — no charge
date, no vaulted card, no consent row — and called those three walls. They were
three *facts about a new row*, and the engine repairs exactly those facts:

1. **`resume()` mints a clock for a plan that has none.** That is a real fix for
   migrated members (`FailedPlanRecoveryTest` pins it), and it is reachable by an
   admin, by a bulk verb, and **by the customer themselves** in their account
   area. One Pause → Resume made a comped plan due today.
2. **The consent gate matches the CUSTOMER, not the plan** — so comping somebody
   who already subscribes inherits the consent they gave for the subscription
   they PAY for. The wall the docblock called "the important one" was the one
   that did not exist for the commonest case.
3. **A plan with no card never reaches that gate anyway.** It falls into
   MANUAL-PAYMENT mode first, which emails the customer an invoice and advances
   their cycle. A comped member would have been dunned, monthly, forever.
4. **A zero amount had no guard at collection time.** Every path that CREATES a
   plan refuses one; nothing refused one on the way to the gateway, which
   declines ₪0.00 — and that decline reads as a failed cycle and starts a dunning
   ladder against a customer whose card is fine.

**The fix: a column, not a shape.** `installment_plans.no_charge`, read in the
places every path already goes through:

- `ChargeOrchestrator::charge()` refuses it **before** the charge-attempt record,
  **before** the manual-mode branch and **before** the consent gate — no ledger
  row, no mail, no clock movement. A second refusal, independent of the first,
  rejects a cycle worth nothing after the slot exists and before `Ledger::open`.
- `DispatchDuePlansCommand` drops them from the due scan, so a comped plan that
  somehow acquired a clock does not queue a job every five minutes to be told no.
- `SubscriptionLifecycleService::resume()` mints no clock for one — "they are a
  member again" never means "start collecting today". An ordinary subscription
  still gets its clock back (pinned).
- `CardUpdateService::availableFor()` stops offering a card-update link: the
  engine would refuse the charge whatever is vaulted, so asking for card details
  would be collecting a card for nothing.
- `AnalyticsMetrics::mrr()` leaves them out. They still count as a subscriber and
  as a subscription — they are one — but their amount is what the membership is
  worth, not money that will arrive.

The three original walls are still there and still worth having. They are simply
no longer the promise.

**Also from R1** (suggestions applied): the write is wrapped in
`Tenant::run($shop, …)` so the two lookups inside it are scoped to the shop the
call NAMES rather than to whatever the request happened to bind; the `MAX_INTERVAL`
ceiling exists service-side, not only in the form; `MAX_CONTACT_LENGTH` split from
the title's; the redundant `meta.manual.no_charge` marker removed, because two
copies of one fact is how they come to disagree.

**Not done, deliberately:** `ManualSubscriptionService` still re-authors the plan
row rather than sharing `RecurringPlanService::buildPlanRow()` (no money logic is
duplicated, and the shapes differ on the pricing snapshot); the form infers the
shop's currency from its newest plan rather than a shop-level setting, which does
not exist yet.

**Tests.** `CreateSubscriptionTest` 12 · `NoChargePlanTest` 8 — the second file is
entirely the after-the-click paths R1 named: a comped plan handed a card AND a
clock AND a customer who already consented is still refused with zero gateway
calls and no ledger row; no manual-payment invoice and no clock advance for a
cardless one; the scheduler queues nothing even with a clock; resume mints no
clock (and still does for an ordinary plan); a zero-amount cycle is refused before
the gateway; no card-update link; a subscriber, never revenue. Full suite 2239
green.

**Still unverified live:** the migration
(`2026_09_23_000001_add_no_charge_to_installment_plans`) has NOT been run on
production, and the scheduler's due scan now names the column — it must run
before this deploys. No comped subscription has been created on a real store, and
the form has not been rendered in a real browser.

---

## 2026-09-23 — R2 (re-review) — VERDICT: PASS-WITH-SUGGESTIONS

All four blockers cleared and independently re-traced. The two checks worth
recording, because they are the ones that would have failed silently:

- **The column is `boolean(…)->default(false)`, not nullable.** Against a
  nullable column, `->where('no_charge', false)` in the due scan would have
  excluded EVERY pre-existing plan and stopped the whole book billing. A fail-open
  wall would have been obvious; this one fails closed and silent.
- **`chargeWithReference` has exactly three call sites app-wide.** The orchestrator
  (gated), and the upsell + account-offer paths, both of which require an active
  payment method — which `CardUpdateService` can no longer attach to a comped plan.
  Wall 2 is closed at the source rather than per-caller.

**R2 suggestions applied in the same unit** (neither moved money, both stated
something false):

1. **A false statement in a customer's inbox.** The reminder fan-out and the
   home screen's upcoming-charges list carried no predicate, and the two
   deliberate date verbs were ungated — so a comped plan handed a date would
   have emailed its owner "we will charge you ₪X on the 5th" for a charge the
   engine refuses, and counted them on the merchant's incoming-money list.
   `DispatchRemindersCommand`, `HomeDashboard::upcomingCharges()`,
   `ViewSubscription::canEditNextCharge()` and the bulk `SetNextChargeDate`
   now all read the flag.
2. **The zero-amount refusal looped the scheduler.** A refusal that left
   `next_charge_at` where it was would be re-dispatched every five minutes for
   the life of the plan, writing a Timeline row each run — the exact pattern
   `DispatchDuePlansCommand` already warns about. The refusal now advances a
   recurring cycle (as manual mode does) and stops an installment plan's clock,
   since a slot worth nothing means there is nothing left to collect.
3. **The flag was invisible.** A comped plan looked identical to one that is
   quietly failing to bill — the one thing a merchant opens the page to find
   out. Added a badge beside the status (`x-rc.badge`, no new CSS) and a ternary
   filter on the list. A ternary and not a tab: it is a property of a
   subscription, not a stage of one.

**Left open, deliberately:** there is still **no conversion path** — nothing
clears `no_charge`, so turning a comped member into a paying one means a new
subscription through the ordinary checkout. That is the wall the unit wanted;
it is recorded here so the next person knows it is a decision.

---

## 2026-09-23 — R3 (the address, and the closed list it comes from)

**Why.** The merchant asked to type the customer's address "exactly as I have it
in the store's checkout". The checkout asks for six things — city, street,
building, apartment, **floor**, **entrance** — and picks the first two from a
closed list.

**The plan's address vocabulary grew by two.** `ADDRESS_FIELDS` gains `floor` and
`entrance`, which reaches everything that already walks it: the detail page's
edit form and its prefill (now walked, not listed again), the readable line (the
labelled parts are a MAP now, so "4, 2, ב" between a street and a city can never
be printed as bare numbers), the CSV's columns both ways, and
`GiftShippingAddress::fromPlanContact` — which already had floor/entrance
parameters and simply had nothing to put in them. A courier sheet for a
hand-typed member no longer stops at the flat number.

**The list is read from the REGISTRY, not from the store.** The obvious route —
ask the plugin, which already holds these lists — is closed: its address
endpoints are guarded by a WordPress REST nonce, a browser-session credential
this server cannot mint. Reaching them would need a new signed route in the
plugin and a release every store installs. `AddressRegistry` reads the same
public data.gov.il resources the plugin downloads from, with the same resource
ids and fields, cached for 90 days (5 minutes on failure, so a dead registry is
not re-asked per keystroke). One cache for every shop, and it works on the
Shopify rail where the plugin never runs.

It is **not** a merchant-steered outbound request — the host is a constant and
the only thing that varies is a numeric city code the registry itself issued —
so it needs none of SafeSiteFetcher's walls and gets none of its ceremony.

**Fail open, exactly as the checkout does.** A registry that does not answer
returns `null`, NOT an empty list, and the form falls back to free text. The
distinction is the whole point: a form that reads an outage as "there are no
cities" refuses every address in the country. Pinned in `AddressRegistryTest`,
along with the spelling tolerance (the two registry resources disagree about
hyphens) and the fact that streets are fetched by the registry's own city code
rather than by name.

**Left as free text, deliberately:** the detail page's "Edit contact details".
That form also repairs imported addresses, whose spellings predate any list, and
a closed list there would block the correction it exists for.

**Tests.** `AddressRegistryTest` 6 · `CreateSubscriptionTest` 20 (the whole
checkout address round-trips, unknown keys are dropped, the line labels its
parts, and it reaches a shipping block with floor and entrance). Test runs no
longer depend on the registry's uptime: the create tests fake it unreachable on
purpose, which is also the free-text shape they type into. Full suite 2256 green.

---

## 2026-09-23 — R4 (three WooCommerce checkout asks)

Plugin **0.53.0**. Three merchant requests, and the middle one turned out not to
need building at all.

**1. "Issue the receipt to …"** — one optional checkout field
(`class-lets-receipt-name.php`). When a shopper fills it, the accounting document
is made out to that name; the ORDER still records who paid, because a refund and
a chargeback are answered from that and not from whose name is on the receipt.
The SaaS carries it as `customer.receipt_name` beside the buyer's own name, and
`DocumentIssuer::documentName()` decides between them in ONE place so the sale
and the credit note that reverses it name the same party.

**2. PayPal — already there; what was missing was the wall.** PayPal is a PayPlus
charge method, merchant-toggleable on Settings → LETS today; no gateway to build.
What did not exist is the rule that a SUBSCRIPTION cannot be paid with it. It is
now enforced in three places, because each covers what the others cannot:

- `PayPlusPageOptions::forTokenPage()` — a subscription's page offers the card and
  hides everything else. Bit, PayPal, Multipass and the vouchers all take the first
  cycle perfectly well and leave nothing to bill the second one with; the
  subscription would go live and fail at its first renewal while the shopper
  believed they had subscribed.
- `woocommerce_available_payment_gateways` — a basket with a subscription shows
  only the LETS method. Not a list of banned gateways: the rule is not about
  PayPal, it is about what can still be charged next month, and naming them one
  by one leaves the next plugin somebody installs quietly able to break a
  subscription. It hands back the full list rather than an EMPTY one if the LETS
  gateway is itself unavailable — a checkout with no way to pay is worse than the
  problem.
- A refusal on both checkout paths for an order that arrives on the wrong rail
  anyway (a stale page, a saved session).

**3. The subscription-terms tick** — required only when a subscription is in the
basket, never pre-ticked, with the merchant's own wording (`{link}` becomes the
anchor) and their terms URL. What was accepted is frozen onto the order: the
wording, the link and the time, because a terms page edited next year must not be
able to change what the customer agreed to.

**The block-checkout trap, avoided.** Both new fields were first written to read
their value from order meta under a guessed key (`_wc_other/…`). The
additional-fields API stores registered fields under a key of its own devising,
and for the TERMS tick a wrong guess does not fail quietly — it fails closed, on
every order: the tick reads as empty however hard it was ticked, and the whole
checkout refuses. Both now hook `woocommerce_validate_additional_field` (and its
earlier name), which HANDS the value over, so there is nothing to guess. The
acceptance is then stamped without re-reading the tick, because the order does
not exist unless validation let it through.

**Defaults, so an update changes nothing by surprise:** the receipt field is OFF,
the terms tick is OFF and stays off until a terms URL is set, and the gateway
lock is ON — it is a money rule, and a store that has been letting subscriptions
be paid on a rail that cannot bill them again has a problem either way.

**Tests.** `WooCommerceCartSubscriptionFlowTest` +2 (a subscription page is
card-only with `create_token`; an ordinary basket keeps every method the merchant
enabled) · `DocumentIssuerTest` +2 (the document is made out to the requested
name; a blank one leaves the buyer's own).

**Unverified live, and it needs saying:** none of the plugin half has run inside a
real WooCommerce checkout. The block-checkout paths in particular are written
against WooCommerce's documented API, not against an observed store — the
classic checkout is the one this plugin's other fields already use.

**Tests (R2).** `NoChargePlanTest` 11 (+3 for R2: the reminder is sent for a paying
plan and withheld for a comped one carrying the same clock; the upcoming-charges
list shows only the paying one; a zero-amount plan is not re-dispatched) ·
`CreateSubscriptionTest` 12 · full suite 2242 green.
