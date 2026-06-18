// === LETS upsell client (shared by the thank-you + order-status targets) ===
//
// Talks to the LETS app with a DIRECT fetch to an ABSOLUTE URL. Two calls:
//   1. fetchOffer(): GET https://app.lets.co.il/upsell/offer?... → the eligible
//      offer JSON (server-computed price) + a SIGNED accept URL, or { offer: null }.
//   2. acceptOffer(acceptUrl): POST the signed accept URL → the app charges the
//      saved PayPlus token + creates the linked child order. Idempotent server-side
//      (a double-tap collapses to ONE charge).
//
// WHY DIRECT FETCH (not a relative App-Proxy path): checkout / customer-account UI
// extensions (purchase.thank-you.block.render, customer-account.order-status.block
// .render) run in a sandboxed web worker with NO storefront origin/session, so a
// relative `/apps/payplus/...` fetch has no base to resolve and never reaches the
// proxy. Shopify's guidance for these targets is a direct fetch to an absolute app
// URL authenticated with a SESSION-TOKEN (JWT) bearer. The session-token API is
// available in both targets; the app verifies the JWT and derives the shop from it.
// The app returns Access-Control-Allow-Origin:* so this cross-origin fetch works.
//
// SECURITY: this file sends NO shop id and NO amount. The shop is derived by the
// app FROM the verified session token; the price + the signed accept URL come back
// FROM the server. The client is display-only.

// The production app host. The offer endpoint + the signed accept URL both live on
// app.lets.co.il. Read from the extension's injected env if the framework exposes
// one, else this single CONST (the known, stable production URL).
const APP_HOST =
  (typeof process !== 'undefined' && process.env && process.env.LETS_APP_URL) ||
  'https://app.lets.co.il';
const OFFER_URL = `${APP_HOST.replace(/\/+$/, '')}/upsell/offer`;

/**
 * Build the offer query from the order context the extension exposes. We pass the
 * purchase facts the resolver needs (parent order, customer ref, subtotal,
 * purchased product gids). The session-token bearer authenticates the request and
 * tells the app which shop is asking (no shop id in the client payload).
 *
 * @param {object} ctx
 * @param {string} ctx.parentOrderId  numeric/gid id of the just-placed order
 * @param {string} ctx.customerRef    shopify customer id (token lookup key)
 * @param {number} ctx.subtotal       order subtotal (display/trigger only)
 * @param {string[]} ctx.productGids  purchased product gids (trigger match)
 * @param {string} [ctx.email]
 * @param {string} [ctx.currency]
 * @param {string} ctx.sessionToken   App Bridge session token (JWT) for the bearer
 */
export async function fetchOffer(ctx) {
  const params = new URLSearchParams();
  params.set('parent_order', ctx.parentOrderId ?? '');
  params.set('customer', ctx.customerRef ?? '');
  params.set('subtotal', String(ctx.subtotal ?? 0));
  if (ctx.productGids?.length) params.set('products', ctx.productGids.join(','));
  if (ctx.email) params.set('email', ctx.email);
  if (ctx.currency) params.set('currency', ctx.currency);

  const headers = { Accept: 'application/json' };
  // The session token authenticates the request (the app verifies the JWT, derives
  // the shop, binds the tenant). Without it the app returns 401 → { offer: null }.
  if (ctx.sessionToken) headers.Authorization = `Bearer ${ctx.sessionToken}`;

  const res = await fetch(`${OFFER_URL}?${params.toString()}`, {
    method: 'GET',
    headers,
  });

  if (!res.ok) return { offer: null };
  return res.json(); // { offer, accept_api_url, accept_url, decline_url } | { offer: null }
}

/**
 * Accept the offer by POSTing the SIGNED accept URL the server returned. No body
 * is needed — every fact (shop/flow/offer/parent_order/customer) is inside the
 * signature, and the amount is recomputed server-side. The accept URL is ABSOLUTE
 * (app.lets.co.il) and the app sends CORS headers, so this cross-origin POST works.
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
