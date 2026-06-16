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
 */
export function UpsellWidget({ context }) {
  const [state, setState] = useState({ phase: 'loading' });

  // Resolve the eligible offer once on mount (records the server-side impression).
  useEffect(() => {
    let live = true;
    fetchOffer(context)
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
        <s-text>{translate('upsell.added')}</s-text>
      </s-banner>
    );
  }

  if (state.phase === 'failed') {
    return (
      <s-banner tone="critical">
        <s-text>{translate('upsell.failed')}</s-text>
      </s-banner>
    );
  }

  const offer = state.data.offer;
  const busy = state.phase === 'charging';

  return (
    <s-section heading={offer.title}>
      <s-stack direction="block" gap="base">
        <s-text>
          {offer.title} — {formatPrice(offer.price, offer.currency)}
        </s-text>
        <s-stack direction="inline" gap="base">
          <s-button onClick={onAccept} disabled={busy} loading={busy}>
            {translate('upsell.accept')}
          </s-button>
          <s-button kind="secondary" onClick={onDecline} disabled={busy}>
            {translate('upsell.decline')}
          </s-button>
        </s-stack>
      </s-stack>
    </s-section>
  );
}

// i18n: real keys live in the extension's locales/*.json (en + he). This wrapper
// keeps the component string-free; the build injects the localised value.
function translate(key) {
  const fallback = {
    'upsell.accept': 'Add to my order',
    'upsell.decline': 'No thanks',
    'upsell.added': 'Added to your order — no card re-entry needed.',
    'upsell.failed': "We couldn't add that. You have not been charged.",
  };
  return fallback[key] ?? key;
}

function formatPrice(amount, currency) {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ILS' }).format(amount);
  } catch {
    return `${amount} ${currency || 'ILS'}`;
  }
}
