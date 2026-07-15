=== LETS — PayPlus Subscriptions & Installments for WooCommerce ===
Contributors: lets
Tags: payplus, subscriptions, installments, deposit, upsell, woocommerce
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.1
Stable tag: 0.5.0
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
