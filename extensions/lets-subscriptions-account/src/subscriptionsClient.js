// === LETS Subscriptions — data client for the personal-area page ===
//
// Two directions, deliberately different:
//
//   READS  → shopify.query() on the Customer Account API. subscriptionContracts
//            is scoped by Shopify to the LOGGED-IN customer AND to contracts our
//            app owns — the strongest possible read guarantee, with no backend.
//
//   ACTIONS → direct fetch to the app's ABSOLUTE /subscriptions/api/* endpoints
//            with a session-token (JWT) bearer (the transport the lets-thank-you
//            extension proved: the sandboxed worker has no storefront origin, so
//            relative fetches cannot resolve). The backend re-verifies the token,
//            binds the shop, and matches the token's CUSTOMER against the
//            contract's owner before any verb runs.
//
// SECURITY: this file sends no shop id, no amounts, no customer id — only the
// contract GID being acted on. Everything identifying comes from the verified
// token, server-side.

const APP_HOST =
  (typeof process !== 'undefined' && process.env && process.env.LETS_APP_URL) ||
  'https://app.lets.co.il';
const API_BASE = `${APP_HOST.replace(/\/+$/, '')}/subscriptions/api`;

// The Customer Account API read. `lines(first: 10)` covers real carts; edges we
// don't render are simply not fetched. Money fields come as {amount, currencyCode}.
const CONTRACTS_QUERY = `#graphql
  query LetsSubscriptions($first: Int!) {
    customer {
      subscriptionContracts(first: $first) {
        edges {
          node {
            id
            status
            nextBillingDate
            billingPolicy { interval intervalCount }
            deliveryPrice { amount currencyCode }
            lines(first: 10) {
              edges {
                node {
                  name
                  quantity
                  currentPrice { amount currencyCode }
                  image { url altText }
                }
              }
            }
          }
        }
      }
    }
  }
`;

/**
 * The logged-in customer's subscriptions (our app's contracts only — Shopify
 * enforces both scopes). Returns a plain list the page renders directly.
 */
export async function fetchContracts(first = 20) {
  const { data, errors } = await shopify.query(CONTRACTS_QUERY, {
    variables: { first },
  });

  if (errors?.length) {
    throw new Error(errors.map((e) => e.message).join('; '));
  }

  const edges = data?.customer?.subscriptionContracts?.edges ?? [];

  return edges.map(({ node }) => ({
    gid: node.id,
    status: node.status,
    nextBillingDate: node.nextBillingDate,
    interval: node.billingPolicy?.interval ?? 'MONTH',
    intervalCount: node.billingPolicy?.intervalCount ?? 1,
    price: node.deliveryPrice ?? null,
    lines: (node.lines?.edges ?? []).map(({ node: l }) => ({
      name: l.name,
      quantity: l.quantity,
      price: l.currentPrice ?? null,
      image: l.image?.url ?? null,
      imageAlt: l.image?.altText ?? '',
    })),
  }));
}

/**
 * Run one verb against the app. `action` ∈ pause|resume|skip|reschedule|cancel.
 * The session token is fetched FRESH per call — tokens are short-lived (60s), and
 * a stale one turns into a silent 401.
 *
 * @returns {Promise<{ok: boolean, reason: ?string, contract: ?object}>}
 */
export async function contractAction(action, contractGid, extra = {}) {
  const token = await shopify.sessionToken.get();

  const res = await fetch(`${API_BASE}/${action}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ contract_gid: contractGid, ...extra }),
  });

  // A non-2xx still carries the machine reason (the page maps it to copy).
  const body = await res.json().catch(() => ({}));

  return {
    ok: res.ok && body?.ok === true,
    reason: body?.reason ?? (res.ok ? null : `http_${res.status}`),
    contract: body?.contract ?? null,
  };
}
