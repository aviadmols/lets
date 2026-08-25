// === LETS Subscriptions — data client for the PayPlus personal-area model ===
//
// Sibling of subscriptionsClient.js, same transport contract: absolute URLs
// (the sandboxed worker has no storefront origin, so relative fetches cannot
// resolve) and a session-token (JWT) bearer fetched FRESH per call — tokens
// live ~60 seconds, and a stale one turns into a silent 401. One retry on a
// 401, with a new token, covers the token that expired mid-flight.
//
// The payload is the whole AccountPresenter model: every string translated,
// every amount resolved, every button decided SERVER-side. This file sends no
// shop id, no customer id and no prices of its own — only the subscription's
// public id and the offer/target keys being acted on. Identity comes from the
// verified token, server-side.

const APP_HOST =
  (typeof process !== 'undefined' && process.env && process.env.LETS_APP_URL) ||
  'https://app.lets.co.il';
const API_BASE = `${APP_HOST.replace(/\/+$/, '')}/subscriptions/api`;

/** One authenticated request, retried once with a fresh token on a 401. */
async function request(path, options = {}) {
  let res = await send(path, options);

  if (res.status === 401) {
    res = await send(path, options);
  }

  return res;
}

async function send(path, { method = 'GET', body } = {}) {
  const token = await shopify.sessionToken.get();

  return fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
}

/**
 * The whole personal area, in the shopper's language.
 *
 * @returns {Promise<object>} the AccountPresenter model
 * @throws when the response is not the expected `{ok: true, account}` shape
 */
export async function fetchAccount(locale) {
  const query = locale ? `?locale=${encodeURIComponent(locale)}` : '';
  const res = await request(`/account${query}`);
  const body = await res.json().catch(() => ({}));

  if (!res.ok || body?.ok !== true || !body?.account) {
    throw new Error(`account_bootstrap_http_${res.status}`);
  }

  return body.account;
}

/**
 * Run one personal-area verb (pause | resume | cancel | skip | reschedule |
 * items | accept_offer). The body carries `subscription` (the public id) plus
 * the verb's own keys (`date`, `line_items`, `offer`, `target`, `amount`).
 *
 * Whatever the verdict, the server ships the REFRESHED model alongside it —
 * the page must redraw from `account`, never from its own optimism. A 404 is
 * "not yours or not real", deliberately indistinguishable.
 *
 * @returns {Promise<{ok: boolean, result: ?string, account: ?object}>}
 */
export async function accountAction(action, body = {}) {
  const res = await request(`/account/${action}`, { method: 'POST', body });
  const payload = await res.json().catch(() => ({}));

  return {
    ok: res.ok && payload?.ok === true,
    result: payload?.result ?? (res.ok ? null : `http_${res.status}`),
    account: payload?.account ?? null,
  };
}

// The card-update verb's endpoint spells the dash, the payload's action name
// spells the underscore (the copy bag keys off it: `result_card_update`).
const CONTRACT_ACTION_PATHS = { card_update: '/card-update' };

/**
 * Run one Shopify-Payments CONTRACT verb (pause | resume | skip | reschedule |
 * cancel | card_update). Same transport as the account verbs — fresh token,
 * one 401 retry — posting only the contract GID plus the verb's own keys; the
 * server re-proves ownership from the token before anything runs.
 *
 * The answer carries no account model: after an ok the caller re-fetches the
 * bootstrap, because Shopify owns the contract and only a re-read is the truth.
 *
 * @returns {Promise<{ok: boolean, reason: ?string}>}
 */
export async function contractAction(action, contractGid, extra = {}) {
  const res = await request(CONTRACT_ACTION_PATHS[action] || `/${action}`, {
    method: 'POST',
    body: { contract_gid: contractGid, ...extra },
  });
  const payload = await res.json().catch(() => ({}));

  return {
    ok: res.ok && payload?.ok === true,
    reason: payload?.reason ?? (res.ok ? null : `http_${res.status}`),
  };
}
