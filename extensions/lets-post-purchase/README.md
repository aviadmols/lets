# lets-post-purchase — SECONDARY native post-purchase interstitial

A Shopify **post-purchase checkout extension** (`type = "checkout_post_purchase"`,
`@shopify/post-purchase-ui-extensions`). It renders a full interstitial page
*after* the order is confirmed but *before* the Order-status page, with the two
extension points declared in code:

- `Checkout::PostPurchase::ShouldRender` — show the page only if the app has an
  eligible offer.
- `Checkout::PostPurchase::Render` — render the offer + accept / decline.

## Why this is the SECONDARY path

The native post-purchase **changeset** payment flow (`calculateChangeset` →
`applyChangeset`) requires the store to support post-purchase payment, which in
practice means **Shopify Payments**. Israeli **PayPlus** merchants typically are
**not** on Shopify Payments, so this extension defaults to the **LETS PayPlus token
path** (POST the app's signed accept endpoint — identical to `lets-thank-you`) and
only uses the native changeset when `CAPABILITY.nativeChangeset` is proven for the
target store in **Phase 0.5**.

Build the token path first; treat native post-purchase as an optional enhancement,
never a dependency. The **primary** post-purchase surface is `lets-thank-you`.

## Open item — the auth handshake (TODO)

Post-purchase extensions do **not** get an App-Proxy signature. Shopify hands the
extension a signed input **token** (a JWT signed with the app secret). The app
endpoint must verify *that* token (aud == API key, signed by `SHOPIFY_API_SECRET`)
to authenticate + derive the shop, instead of the App-Proxy signature. See
`src/index.jsx` → `bearerFromInput`. The v1 baseline ships the App-Proxy-signed
thank-you widget; this native extension's token-verification is the remaining wiring.

## Deploy

```sh
shopify app deploy   # auto-discovered from shopify.app.toml; needs Partner login
```
