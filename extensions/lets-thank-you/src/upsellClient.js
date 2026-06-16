// === LETS upsell client (shared by the thank-you + order-status targets) ===
//
// Talks to the LETS app through the Shopify App Proxy. Two calls:
//   1. fetchOffer(): GET /apps/payplus/upsell/offer?... → the eligible offer JSON
//      (server-computed price) + a SIGNED accept URL, or { offer: null }.
//   2. acceptOffer(acceptUrl): POST the signed accept URL → the app charges the
//      saved PayPlus token + creates the linked child order. Idempotent server-side
//      (a double-tap collapses to ONE charge).
//
// SECURITY: this file sends NO shop id and NO amount. The App Proxy adds the
// Shopify `signature` (the app verifies it and derives the shop); the price + the
// signed accept URL come back FROM the server. The client is display-only.
//
// The proxy subpath ("payplus") + app-relative base ("/apps") mirror
// shopify.app.toml [app_proxy] (prefix = "apps", subpath = "payplus").

const PROXY_BASE = '/apps/payplus';
const OFFER_PATH = `${PROXY_BASE}/upsell/offer`;

/**
 * Build the offer query from the order context the extension exposes. We pass the
 * purchase facts the resolver needs (parent order, customer ref, subtotal,
 * purchased product gids); the App Proxy appends the signature.
 *
 * @param {object} ctx
 * @param {string} ctx.parentOrderId  numeric/gid id of the just-placed order
 * @param {string} ctx.customerRef    shopify customer id (token lookup key)
 * @param {number} ctx.subtotal       order subtotal (display/trigger only)
 * @param {string[]} ctx.productGids  purchased product gids (trigger match)
 * @param {string} [ctx.email]
 * @param {string} [ctx.currency]
 */
export async function fetchOffer(ctx) {
  const params = new URLSearchParams();
  params.set('parent_order', ctx.parentOrderId ?? '');
  params.set('customer', ctx.customerRef ?? '');
  params.set('subtotal', String(ctx.subtotal ?? 0));
  if (ctx.productGids?.length) params.set('products', ctx.productGids.join(','));
  if (ctx.email) params.set('email', ctx.email);
  if (ctx.currency) params.set('currency', ctx.currency);

  const res = await fetch(`${OFFER_PATH}?${params.toString()}`, {
    method: 'GET',
    headers: { Accept: 'application/json' },
  });

  if (!res.ok) return { offer: null };
  return res.json(); // { offer, accept_api_url, accept_url, decline_url } | { offer: null }
}

/**
 * Accept the offer by POSTing the SIGNED accept URL the server returned. No body
 * is needed — every fact (shop/flow/offer/parent_order/customer) is inside the
 * signature, and the amount is recomputed server-side. Returns the charge result.
 *
 * @param {string} signedAcceptUrl  the `accept_api_url` from fetchOffer()
 */
export async function acceptOffer(signedAcceptUrl) {
  const res = await fetch(signedAcceptUrl, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  });

  // 200 charged/already, 422 no-consent/no-method, 402 charge_failed.
  const body = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, ...body };
}
