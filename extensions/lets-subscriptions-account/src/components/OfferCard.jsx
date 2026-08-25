/** @jsxImportSource preact */
// === One account offer, from AccountPresenter's offers / rail_offers / sub.offers ===
//
// The promotion sits at the top level (heading, subtext, image); each CHOICE is
// a target row with its own price, dates, button text and disclosure — all
// written server-side by AccountOfferPresenter. The browser posts back only the
// offer id, the target key and the quoted amount (a guard the server re-prices).
//
// Accepting is a CHARGE on a card the shopper cannot see, so it is confirmed
// with the target's own disclosure sentence — the one naming the amount and the
// date — as an explicit two-step (the sandboxed worker has no window.confirm).
//
// Merchant CUSTOM markup (offer.html / offer.css) is NOT rendered here: the s-*
// component tree has no sanctioned innerHTML. Such offers fall back to this
// structured card, which carries every target and every button regardless.

import { useState } from 'preact/hooks';
import { withDate } from './format.js';

/** The merchant ordered the targets in the admin; `index` is that order. Sort a copy. */
function byIndex(targets) {
  return targets.slice().sort((a, b) => (Number(a.index) || 0) - (Number(b.index) || 0));
}

export function OfferCard({ offer, copy, act, locale }) {
  const targets = Array.isArray(offer.targets) ? byIndex(offer.targets) : [];
  if (!targets.length) return null;

  // A one-target card may borrow that product's image and title, the way the
  // single-target card always did; a card offering three has no one product to picture.
  const only = targets.length === 1 ? targets[0].product || {} : {};
  const image = offer.image_url || only.image;
  const heading = offer.heading || only.title || '';

  return (
    <s-section>
      <s-stack direction="block" gap="base">
        {image && <s-image src={image} alt={heading} aspectRatio="1" inlineSize="small" border="base" borderRadius="base" />}
        {heading && <s-heading>{heading}</s-heading>}
        {offer.subtext && <s-text tone="subdued">{offer.subtext}</s-text>}

        {targets.map((target) => (
          <OfferTarget key={target.key} offer={offer} target={target} heading={heading} copy={copy} act={act} locale={locale} />
        ))}
      </s-stack>
    </s-section>
  );
}

/**
 * One row: what it is, what it costs, when the money moves, and the button
 * that does it. Every sentence is the server's — the row joins strings, it
 * never formats money and never derives a date.
 */
function OfferTarget({ offer, target, heading, copy, act, locale }) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);

  const product = target.product || {};
  const quantity = Number(target.quantity) > 1 ? Number(target.quantity) : 0;
  const showName = product.title && (quantity || product.title !== heading);

  // A subscription's price carries its cadence; a one-time product carries the
  // word that says it will not come back.
  const tail = target.kind === 'one_time' ? copy('offer_one_time') : target.cadence;
  const price = [target.price_display, tail].filter(Boolean).join(' · ');

  const label = target.button_text || (target.kind === 'one_time' ? copy('offer_buy_now') : copy('offer_accept'));

  async function accept() {
    setBusy(true);
    const ok = await act('accept_offer', {
      subscription: offer.source_plan,
      offer: offer.id,
      target: target.key,
      amount: target.amount,
    });
    setBusy(false);
    if (ok) setConfirming(false);
  }

  return (
    <s-stack direction="block" gap="small-200">
      {showName && <s-text emphasis="bold">{quantity ? `${product.title} ×${quantity}` : product.title}</s-text>}
      {price && <s-text>{price}</s-text>}

      {/* WHEN the money moves is part of the offer, not a detail. */}
      {target.first_charge_at && <s-text tone="subdued">{withDate(copy('offer_from'), target.first_charge_at, locale)}</s-text>}
      {target.next_order_at && <s-text tone="subdued">{withDate(copy('offer_add_to_next'), target.next_order_at, locale)}</s-text>}

      {/* Replacing is not adding: the shopper loses a plan they already have. */}
      {target.mode === 'replace' && <s-text tone="subdued">{copy('offer_replaces')}</s-text>}

      {!confirming ? (
        <s-stack direction="inline" gap="small-200">
          <s-button kind="primary" disabled={busy} onClick={() => (target.disclosure ? setConfirming(true) : accept())}>
            {label}
          </s-button>
        </s-stack>
      ) : (
        <s-stack direction="block" gap="small-200">
          {/* The server's disclosure sentence: the amount and the date, per target. */}
          <s-text tone="subdued">{target.disclosure}</s-text>
          <s-stack direction="inline" gap="small-200">
            <s-button kind="primary" loading={busy} disabled={busy} onClick={accept}>
              {label}
            </s-button>
            <s-button kind="plain" disabled={busy} onClick={() => setConfirming(false)}>
              {copy('action.cancel_keep')}
            </s-button>
          </s-stack>
        </s-stack>
      )}
    </s-stack>
  );
}
