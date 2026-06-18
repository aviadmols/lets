/** @jsxImportSource preact */
// === LETS upsell widget (Preact) — shared by both render targets ===
//
// Renders the resolved offer with one-click Accept / Decline. On Accept it POSTs
// the SIGNED accept URL (charges the saved PayPlus token, creates the child order)
// and shows the result. No card re-entry, no new payment page.
//
// Rendered via @shopify/ui-extensions web components (2026-04 runtime). Visual
// design / copy polish is owned by admin-design-system; this is the behaviour
// skeleton with valid component usage.

import { useState, useEffect } from 'preact/hooks';
import { fetchOffer, acceptOffer } from './upsellClient.js';

/**
 * @param {object} props
 * @param {object} props.context  the purchase context built from the order target
 *   (parentOrderId, customerRef, subtotal, productGids, email, currency)
 * @param {object} [props.i18n]   Shopify's localization API (shopify.i18n): provides
 *   translate(key) keyed to locales/*.json + formatCurrency(amount). Optional so the
 *   widget still renders (with EN fallbacks) if a target omits it.
 * @param {object} props.shopify  the target's global extension API. Used here for
 *   shopify.sessionToken — the App Bridge JWT that authenticates the offer fetch
 *   (the app verifies it, derives the shop, binds the tenant). Available in both
 *   the thank-you and order-status targets.
 */
export function UpsellWidget({ context, i18n, shopify }) {
  const [state, setState] = useState({ phase: 'loading' });
  const t = makeTranslator(i18n);

  // Resolve the eligible offer once on mount (records the server-side impression).
  // We first mint a fresh session token (short-lived JWT) and send it as the bearer
  // so the direct cross-origin fetch is authenticated by the shop that's looking.
  useEffect(() => {
    let live = true;
    getSessionToken(shopify)
      .then((sessionToken) => fetchOffer({ ...context, sessionToken }))
      .then((data) => {
        if (!live) return;
        if (!data?.offer) {
          setState({ phase: 'empty' });
          return;
        }
        setState({ phase: 'offer', data });
      })
      .catch(() => live && setState({ phase: 'empty' }));
    return () => {
      live = false;
    };
  }, []);

  async function onAccept() {
    if (state.phase !== 'offer') return;
    setState((s) => ({ ...s, phase: 'charging' }));
    const result = await acceptOffer(state.data.accept_api_url);
    // charged / already → success; everything else → a soft failure message.
    const success = result.status === 200 && (result.result === 'charged' || result.result === 'already_accepted');
    setState((s) => ({ ...s, phase: success ? 'done' : 'failed', result }));
  }

  function onDecline() {
    setState({ phase: 'declined' });
  }

  // Nothing to show (no matching flow, or declined/charged) → render empty.
  if (state.phase === 'loading' || state.phase === 'empty' || state.phase === 'declined') {
    return null;
  }

  if (state.phase === 'done') {
    return (
      <s-banner tone="success">
        <s-text>{t('upsell.added')}</s-text>
      </s-banner>
    );
  }

  if (state.phase === 'failed') {
    return (
      <s-banner tone="critical">
        <s-text>{t('upsell.failed')}</s-text>
      </s-banner>
    );
  }

  const offer = state.data.offer;
  const busy = state.phase === 'charging';

  // The product the customer can add to this order. The server is the price truth:
  // `price` is the (possibly discounted) server-computed charge; `base_price` is the
  // pre-discount reference shown struck-through when it is genuinely higher.
  const price = formatPrice(i18n, offer.price, offer.currency);
  const basePrice = formatPrice(i18n, offer.base_price, offer.currency);
  const discounted = Number(offer.base_price) > Number(offer.price);

  return (
    <s-section heading={t('upsell.heading')}>
      <s-stack direction="block" gap="base">
        {/* The addable product, surfaced as a clear line: name + server price. */}
        <s-stack direction="inline" gap="base" blockalignment="center">
          <s-text emphasis="bold">{offer.title}</s-text>
          {discounted ? (
            <s-text accessibilityrole="deletion" tone="subdued">{basePrice}</s-text>
          ) : null}
          <s-text emphasis="bold">{price}</s-text>
        </s-stack>
        <s-text tone="subdued">{t('upsell.reassurance')}</s-text>
        <s-stack direction="inline" gap="base">
          <s-button onClick={onAccept} disabled={busy} loading={busy}>
            {t('upsell.accept')}
          </s-button>
          <s-button kind="secondary" onClick={onDecline} disabled={busy}>
            {t('upsell.decline')}
          </s-button>
        </s-stack>
      </s-stack>
    </s-section>
  );
}

// Session token: mint a fresh short-lived JWT from the target's extension API. The
// app verifies it (HS256 w/ the app secret), derives the shop from the `dest`
// claim, and binds the tenant — so the client never sends a shop id. The API is
// exposed as `shopify.sessionToken` (current) and `shopify.idToken()` (legacy);
// support both, and resolve to '' if neither is present (the fetch then 401s and we
// render empty — fail closed, never show an unauthenticated offer).
async function getSessionToken(shopify) {
  try {
    const api = shopify?.sessionToken;
    if (api && typeof api.get === 'function') return (await api.get()) ?? '';
    if (typeof api === 'function') return (await api()) ?? '';
    if (typeof shopify?.idToken === 'function') return (await shopify.idToken()) ?? '';
  } catch {
    /* fall through — no token → the offer fetch 401s → empty (fail closed) */
  }
  return '';
}

// i18n: real keys live in the extension's locales/*.json (en + he). Prefer
// Shopify's localization API (shopify.i18n.translate, locale-aware); fall back to a
// small EN map so the component never renders a raw key if the API is absent.
function makeTranslator(i18n) {
  const fallback = {
    'upsell.heading': 'Add to your order',
    'upsell.accept': 'Add to my order',
    'upsell.decline': 'No thanks',
    'upsell.reassurance': 'One click — charged to the card you just used. No re-entry.',
    'upsell.added': 'Added to your order — no card re-entry needed.',
    'upsell.failed': "We couldn't add that. You have not been charged.",
  };
  return (key) => {
    if (i18n && typeof i18n.translate === 'function') {
      try {
        const v = i18n.translate(key);
        if (typeof v === 'string' && v && v !== key) return v;
      } catch {
        /* fall through to the EN map */
      }
    }
    return fallback[key] ?? key;
  };
}

// Price formatting: Shopify's i18n.formatCurrency is locale-aware (preferred);
// Intl is the fallback. The amount is always a SERVER value — display only.
function formatPrice(i18n, amount, currency) {
  const num = Number(amount);
  if (i18n && typeof i18n.formatCurrency === 'function') {
    try {
      return i18n.formatCurrency(num);
    } catch {
      /* fall through to Intl */
    }
  }
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ILS' }).format(num);
  } catch {
    return `${num} ${currency || 'ILS'}`;
  }
}
