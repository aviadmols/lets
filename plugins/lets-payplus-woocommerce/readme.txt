=== LETS — PayPlus Subscriptions & Installments for WooCommerce ===
Contributors: lets
Tags: payplus, subscriptions, installments, deposit, upsell, woocommerce
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.1
Stable tag: 0.35.0
License: Proprietary

Connect your WooCommerce store to LETS for PayPlus deposits + installments, recurring
subscriptions, one-click post-purchase upsells, and optional full PayPlus checkout.

== Description ==

LETS lets Israeli WooCommerce merchants on PayPlus:

* Take a **deposit + installments** until an item is fully paid, then release fulfillment.
* Run open-ended **recurring subscriptions** (billed automatically until cancelled).
* Offer **one-click post-purchase upsells** on the saved card (no card re-entry).
* Optionally accept **normal checkout through PayPlus** (the "PayPlus (LETS)" gateway).

Everything is managed from the LETS dashboard at https://app.lets.co.il. The plugin links
your store to LETS and renders the storefront surfaces; the money, schedules, retries, and
documents run on the LETS engine.

This plugin links your store to LETS. You generate a one-time connection token in the LETS
dashboard (Shops → your store → "Add WooCommerce store") and paste it here. The token is
decoded on your server and used to HMAC-sign the connection request; the api secret never
reaches the browser.

= How it works =

* **Browser → plugin** calls are guarded by a WordPress nonce.
* **Plugin → LETS** calls are signed server-side with HMAC-SHA256 (the api secret stays on
  your server).
* **LETS → plugin** callbacks are verified with a per-store webhook secret.

The shopper's card is collected on the PayPlus hosted payment page; the plugin never sees
or stores card data.

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin and choose this zip (or extract it
   into wp-content/plugins/).
2. Activate "LETS — PayPlus Subscriptions & Installments for WooCommerce".
3. Go to Settings → LETS and paste your connection token, then click "Connect to LETS".
4. (Optional) To accept normal checkout through PayPlus, enable "PayPlus (LETS)" under
   WooCommerce → Settings → Payments.

== Frequently Asked Questions ==

= Does the plugin store card data? =
No. Cards are entered on the PayPlus hosted page. The plugin only redirects to it and
records the result.

= Is Hebrew / RTL supported? =
Yes. The storefront surfaces are RTL-aware and ship with he_IL translations; strings load
from the LETS dashboard locale for server-rendered copy and from the plugin text domain
(lets-payplus) for the storefront widgets.

= Which WordPress / WooCommerce versions are supported? =
WordPress 5.8+ (tested to 6.6), WooCommerce 6.0+ (tested to 9.1), PHP 7.4+.

== Changelog ==

= 0.35.0 =
* A verified sign-in code that finds no WordPress user now asks LETS whether it
  already knows the address. A member the store imported — a year of
  subscriptions, no WP account — gets their account opened FROM what LETS knows
  (name + email) and is signed straight in, instead of being asked to introduce
  themselves on a store that has been charging them. The panel says "setting up
  your account…" while it happens. Strangers still get the quick-registration
  form, privileged accounts are refused as always, and an existing WP user
  reached by a LETS-known phone is linked (phone indexed) rather than duplicated.

= 0.34.0 =
* A custom-HTML offer card can now carry its own CSS. The merchant defines the
  classes their markup uses in a new CSS tab beside the HTML editor, so the card
  looks designed on any theme instead of only on one that happens to define
  those classes. The stylesheet is cleaned on the LETS server (no @import, no
  hostile url() schemes, no markup) and injected on the page as inert text —
  once per offer, however many plans it renders under. The admin preview shows
  the styled card, exactly as the shopper will get it.

= 0.33.0 =
* One offer area can now carry several products. A single card in the personal
  area can put a choice in front of a subscriber — the bigger plan, the refill,
  the accessory — each with its own price, its own dates and its own button,
  instead of one offer per card.
* A subscriber can buy a plain one-time product with one click, on the card
  already on file: billed now, or added to their next subscription order so it
  arrives with the delivery they are already getting. The card says which before
  they confirm, and the confirmation names the amount and the date.
* A merchant writing their own offer HTML places each button where they want it;
  a button whose product is gone is removed rather than left as a gap, and a
  product the design never mentioned still gets a working button at the end.

= 0.32.0 =
* Offers in the personal area. A merchant can now put an offer in front of a
  subscriber where they already are — a full-width strip above their plans, the
  side rail, or under the one plan it would change — targeted at exactly the
  subscribers it suits (yearly members on a given product, say).
* One click, on the card already on file. Accepting switches the plan or adds a
  new one on the saved PayPlus token, with no checkout and no card re-entry.
  The shopper is shown what will be charged and when, and must confirm it; a
  switch can charge today or wait for the current plan's renewal date instead.
* The merchant may design the card in LETS or write their own HTML. Custom
  markup is sanitized on the LETS server against an allow-list — scripts,
  frames, forms and event handlers are removed — and the accept button is put
  back by the plugin, never taken from the merchant's markup.

= 0.31.0 =
* "Log in as customer" — from the customer page in LETS, a merchant now lands on
  their own store already signed in as that shopper, on My Account, instead of
  reading a copy of the page. A red bar across the top says who they are signed
  in as, and one click leaves it and returns to the dashboard.
* WordPress decides who that is, not LETS: the link carries a single-use ticket
  worth two minutes, WordPress resolves it to one of its OWN users, and an
  account that can edit the site is refused outright — so this can never become a
  way into a store's back office. Every attempt, granted or refused, is written
  to the activity log on Settings → LETS, and to the customer's timeline.

= 0.30.0 =
* A subscription purchase can no longer produce TWO tax invoices. Stores that
  invoice every order reported a subscription order as a plain order the instant
  WooCommerce marked it paid — a moment before LETS linked that order to the
  plan — so the same payment was declared once by the order pipeline and once by
  the subscription pipeline. The order is now recognised as a subscription from
  its own cart line, which is true from checkout, and LETS refuses the second
  document as well.

= 0.29.0 =
* "One subscription per customer" now holds on the block checkout too. The rule
  was enforced by a classic-checkout hook that a store using the Checkout block
  never fires, so a shopper who was not logged in when they added to the cart
  could reach payment with a second subscription. The Store API path refuses it
  with the same message.
* The first row of the LETS admin menu reads "ראשי" instead of repeating "LETS".

= 0.28.0 =
* The code panel is now the sign-in screen, not a box under it. On a store that
  offers passwordless sign-in, My Account opens on one centred card in the
  shop's own accent colour instead of a username/password form almost nobody on
  the store has a password for. The password form is still there, one quiet link
  below — staff, password managers and any two-factor plugin are unaffected, and
  a store that has not switched code sign-in on keeps WooCommerce's login screen
  exactly as it was.
* Signing in is a sequence now, not a stack. Enter your number, and the screen
  becomes the code screen: it says which number the code went to (masked to the
  last four digits), takes the six digits and submits itself on the sixth, and
  offers "change details" and a "send again" that unlocks after thirty seconds.
* A shopper the store has never seen can now sign in. The code is sent whether
  or not the address already has an account; when it turns out to belong to
  nobody, the screen asks for a first name, a last name and the one detail it
  does not yet have, and opens the customer account there and then. Previously
  those shoppers were told a code was on its way and nothing ever arrived.
* Registration only ever completes for an address that answered a code, the
  verified detail cannot be edited on the way through, and an address that
  already has an account says so and offers to sign in with it instead.

= 0.27.0 =
* The LETS management system now opens inside WordPress. Click "LETS" in the
  admin menu and the panel is simply there, already signed in as you — no second
  tab, no second password, and no chance of landing on the wrong shop. The
  personal-area editor and the connection screen moved one level in, keeping
  their own addresses, so every existing bookmark and toolbar shortcut still
  works.
* The sign-in behind that screen is minted fresh on every visit, server-side,
  and is valid once and for sixty seconds. Nothing is cached and nothing reaches
  the browser that could be replayed. A store that is not connected — or a key
  the dashboard no longer recognises — gets a plain explanation and a link to
  the connection screen, instead of an empty frame.

= 0.26.0 =
* SMS sign-in now works on a store of any size. The phone number a shopper types
  is matched through an index the plugin maintains and backfills in the
  background, instead of a scan that gave up after the first 500 customers —
  which meant customer 501 was told a code was on its way and never got one.
* A phone number is one number however it is written. `050-123 4567`,
  `+972 50 123 4567` and `0501234567` are now the same destination everywhere:
  the code you were sent verifies whatever way you retype the number, asking for
  a new code retires the old one, and the "5 codes an hour" limit counts a
  handset once instead of once per spelling.
* The sign-in panel tells the truth when something goes wrong. A blocked or
  failed request used to look exactly like a delivered code — and, on the second
  step, told shoppers their correct code was wrong. It now says so.
* Pressing Enter in the panel asks for the code instead of submitting the
  WooCommerce login form with an empty username, and signing in from the
  checkout's "returning customer?" form leaves you in the checkout with your
  cart, instead of dropping you on the account page.
* Passwordless sign-in is for customers. An account that can edit the site, its
  products or its orders is refused and must use the WordPress login, so a
  six-digit code can never open the shop's back office.
* Sign in with Google. Paste your Google OAuth client ID in the LETS dashboard
  (Settings → Customer area → Sign-in) and the login form shows Google's own
  button. Google proves the email; WordPress decides which account it belongs
  to and signs that customer in — existing accounts only, and the plugin
  verifies every credential against your client ID on the server.
* The one-time-code panel grew up: when you offer both email and SMS, the
  shopper picks the channel with a proper toggle instead of a one-way link —
  and a shop that offers only SMS now opens on the phone field instead of an
  email field it would never send to.
* The members-club shortcode is finally dressed: the signed-out invitation now
  wears the plugin's own card styling instead of whatever the theme does to a
  naked heading, and the iframe's sizing moved out of inline styles.
* Design housekeeping flagged by the design kit: the product-page "subscribe"
  hint and the cart subscription box no longer share a class (each gets its own
  intended look), the side-by-side upsell layout is defined once instead of
  twice, and a dead timer rule is gone.

= 0.24.0 =
* Your theme's link styling no longer bleeds into the personal area: the
  navigation, the order numbers and the banners stop arriving underlined and in
  the theme's link colour. Order numbers now read in the page's own ink and
  underline on hover, where an underline means "this goes somewhere".
* The order history's buttons sit properly side by side, and the invoice reads
  as the quieter of the two — the row is about the order; the receipt is beside
  it.
* New setting: the typeface. The default is unchanged and is still the
  recommendation — the area declares no font at all and inherits your shop's, so
  it reads as part of your shop. Heebo and the system font are there for a theme
  font that cannot carry a page of numbers and labels. Heebo is only downloaded
  if you pick it.

= 0.23.0 =
* Your banners, colours, section order and wording are now edited inside
  WordPress, under the new LETS menu — no second tab, no second login. They are
  still stored in your LETS account, so the preview there and the page your
  shoppers get can never drift apart.
* A shopper looking for last month's receipt no longer has to open the order:
  the invoice sits in the actions column of the order history, on every order
  that has one.
* The LETS shortcut in the toolbar now opens these screens instead of leaving
  WordPress. Reports and the advanced settings are one click further in.

= 0.22.0 =
* My Account is now one screen rather than a plugin bolted onto WooCommerce's:
  the plugin renders the whole page — its own navigation carrying the shopper's
  name, its own layout, and WooCommerce's orders, addresses and account details
  restyled to match.
* The page uses the width it can actually have instead of the theme's narrow
  prose column, and lays out as a header, a main column of subscriptions and a
  rail for rewards, what's next and your banners. On a phone the tabs become one
  scrollable strip.
* WooCommerce's dashboard prose ("from your account dashboard you can view your
  recent orders…") is replaced by the personal area itself.
* The area's language is now its own setting in the LETS dashboard — Hebrew,
  English, or follow the store — instead of being borrowed from the members-club
  page. It covers the sign-in codes you send, too.
* The personal area now renders even on a theme that ships its own My Account
  template — which most themes do, usually without having changed anything in it.
  A shop that really did write its own account page can hand it back with one
  filter: add_filter('lets_payplus_account_own_template', '__return_false').

= 0.21.0 =
* A full personal area inside My Account: the shopper sees their subscriptions,
  what is coming and when (next delivery, the date an intro price ends, the final
  installment, birthday points), their rewards balance, and can pause, resume,
  cancel, skip a delivery, move its date or change what arrives next time.
  Rendered into the theme rather than an iframe, so it inherits your fonts.
* Sign in with a one-time code by email or SMS, without a password. The code is
  issued and checked by LETS; WordPress still performs the login itself.
* WooCommerce's own account tabs (orders, addresses, account details) are restyled
  with the same tokens, so the whole area reads as one screen.
* The merchant chooses which sections appear, in what order, adds up to three side
  banners, and tunes colours from Settings → Customer area in the LETS dashboard.

= 0.20.0 =
* FIX (critical): reading the invoicing settings crashed the site on PHP 8. Two callers
  pass an empty array for "no query" and the helper handed it straight to ltrim(), which is a
  warning on PHP 7 and a fatal on PHP 8 — so on a PHP 8 host every order status change and
  every thank-you page returned a 500, and orders LETS created came back as failures after
  WooCommerce had already created them. The helper now accepts both shapes, and reading the
  settings can no longer throw into a WooCommerce hook at all.
* A "LETS" column and filter on WooCommerce → Orders, showing which orders LETS
  created and which kind each is (subscription cycle, upsell, gift, installments).
  WooCommerce has no order tags of its own, so the mark LETS stamps on the order is
  surfaced here. Works on both the HPOS orders screen and the classic list.

= 0.19.0 =
* Loyalty gift orders created by LETS are never reported for invoicing: a gift is
  given, not sold, so it has no income to declare.


= 0.18.0 =
* Invoices & receipts on the order: an order-screen box (classic and HPOS) listing the
  Green Invoice documents issued for that order, with open links — including documents
  issued through LETS plans (deposits, subscription cycles), fetched from LETS when the
  order was never stamped. A document link also renders on the customer's order page,
  honouring the "attach to order" setting in the LETS dashboard.
* Declares WooCommerce HPOS (custom order tables) compatibility.


= 0.16.0 =
* Access to the LETS management dashboard from inside WordPress: a "LETS" shortcut in the
  admin bar (reachable from any screen), "Settings" + "Dashboard" quick links on the Plugins
  list, and an "Open LETS dashboard" button on Settings → LETS. The link follows the
  environment the store was connected to.

= 0.2.0 =
* Deposit + installments product-page widget (server-computed schedule → PayPlus page).
* Recurring subscriptions ("Subscribe & save") mode on the product widget.
* One-click post-purchase upsell on the thank-you page (charges the saved PayPlus token).
* Optional full PayPlus checkout gateway ("PayPlus (LETS)", mode B).
* he_IL / RTL storefront strings; token-driven styles (no inline CSS).
* bin/build-plugin.sh reproducible package build.

= 0.1.0 =
* Initial connect skeleton: Settings → LETS page + token decode + HMAC connect request.

== Upgrade Notice ==

= 0.2.0 =
Adds the deposit/installments, subscription, upsell, and optional PayPlus-gateway
storefront surfaces. Reconnect from Settings → LETS if product sync hasn't run.
