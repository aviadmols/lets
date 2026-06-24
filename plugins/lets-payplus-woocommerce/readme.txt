=== LETS — PayPlus Subscriptions & Installments for WooCommerce ===
Contributors: lets
Tags: payplus, subscriptions, installments, deposit, upsell, woocommerce
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: Proprietary

Connect your WooCommerce store to LETS for PayPlus deposits + installments, recurring
subscriptions, and one-click post-purchase upsells.

== Description ==

LETS lets Israeli WooCommerce merchants on PayPlus take a deposit + installments until
fully paid, run recurring subscriptions, and offer one-click post-purchase upsells on
the saved card — all managed from the LETS dashboard at https://app.lets.co.il.

This plugin links your store to LETS. You generate a one-time connection token in the
LETS dashboard (Shops → your store → "Add WooCommerce store") and paste it here. The
token is decoded on your server and used to HMAC-sign the connection request; the
secret never reaches the browser.

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin and choose this zip (or extract
   it into wp-content/plugins/).
2. Activate "LETS — PayPlus Subscriptions & Installments for WooCommerce".
3. Go to Settings → LETS and paste your connection token, then click "Connect to LETS".

== Changelog ==

= 0.1.0 =
* Initial connect skeleton: Settings → LETS page + token decode + HMAC connect request.
