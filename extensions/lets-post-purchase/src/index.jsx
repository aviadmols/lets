/**
 * LETS native post-purchase upsell — the Shopify-Payments rail.
 *
 * Shopify calls this twice, and the split matters:
 *
 *   1. ShouldRender — before the interstitial exists. We ask the app whether the
 *      just-paid checkout has an offer. Returning {render:false} lets Shopify go
 *      straight to the thank-you page with no flicker, so a store with no
 *      matching flow pays nothing for having the extension installed.
 *   2. Render — the page itself. On accept we ask the app for a SIGNED CHANGESET
 *      and hand it to applyChangeset; Shopify then re-charges the payment method
 *      the shopper already used. We never touch a card, and we never compute a
 *      price here — the amount is whatever the app signed.
 *
 * The `token` Shopify gives us is the ONLY thing the app trusts: it is signed
 * with the app secret and carries the shop and the purchase, so this file never
 * sends a shop id, a price, or a product id.
 */
import React, { useEffect, useState } from 'react';
import {
  extend,
  render,
  BlockStack,
  Button,
  CalloutBanner,
  Heading,
  Image,
  Layout,
  Text,
  TextBlock,
  TextContainer,
  Tiles,
  View,
} from '@shopify/post-purchase-ui-extensions-react';

// === CONSTANTS ===
const APP_URL = 'https://app.lets.co.il';
const OFFER_ENDPOINT = `${APP_URL}/post-purchase/offer`;
const SIGN_ENDPOINT = `${APP_URL}/post-purchase/sign`;

/** Ask the app for an offer; anything but a real offer means "do not render". */
extend('Checkout::PostPurchase::ShouldRender', async ({ inputData, storage }) => {
  try {
    const response = await fetch(OFFER_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ token: inputData.token }),
    });

    if (!response.ok) {
      return { render: false };
    }

    const body = await response.json();
    if (!body || !body.offer) {
      return { render: false };
    }

    // Handed to Render as initialData — one round trip, not two.
    await storage.update({ offer: body.offer });

    return { render: true };
  } catch (error) {
    // A post-purchase page that errors would block the shopper from their order
    // confirmation. Failing to "no offer" is always the safe direction.
    return { render: false };
  }
});

render('Checkout::PostPurchase::Render', () => <App />);

function App({ extensionPoint, storage, inputData, applyChangeset, done, calculateChangeset }) {
  const offer = storage?.initialData?.offer;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    // Nothing to show (a stale storage read) → let the shopper straight through.
    if (!offer) {
      done();
    }
  }, [offer, done]);

  if (!offer) {
    return null;
  }

  // The merchant's own copy + element choices, resolved and localized by the app.
  // The extension decides NOTHING about wording or order — it walks what it was
  // given, so what the merchant sees in the admin preview is what ships.
  const p = offer.presentation ?? {};
  const copy = p.copy ?? {};
  const on = p.elements ?? {};
  const order = p.order ?? [];

  const money = (amount) => `${Number(amount).toFixed(2)} ${offer.currency || ''}`.trim();
  const saves = Number(offer.base_price) > Number(offer.price);
  const priceDisplay = copy.price_display ?? money(offer.price);
  const wasDisplay = copy.was_display ?? (saves ? money(offer.base_price) : null);

  // One element key → its node. Rendered in the merchant's order; an unknown or
  // disabled key simply yields nothing.
  const block = (key) => {
    if (!on[key]) return null;

    switch (key) {
      case 'eyebrow':
        return copy.eyebrow ? <TextBlock key={key} size="small" appearance="subdued">{copy.eyebrow}</TextBlock> : null;
      case 'badge':
        return copy.badge ? <TextBlock key={key} size="small" emphasized>{copy.badge}</TextBlock> : null;
      case 'headline':
        return copy.headline ? <Heading key={key}>{copy.headline}</Heading> : null;
      case 'product_name':
        return copy.product_name ? <TextBlock key={key}>{copy.product_name}</TextBlock> : null;
      case 'subcopy':
        return copy.subcopy ? <TextBlock key={key} appearance="subdued">{copy.subcopy}</TextBlock> : null;
      case 'price':
        return (
          <Tiles key={key}>
            {wasDisplay ? <Text role="deletion" appearance="subdued">{wasDisplay}</Text> : null}
            <Text emphasized size="large">{priceDisplay}</Text>
          </Tiles>
        );
      case 'save':
        return copy.save_label ? <TextBlock key={key} size="small" appearance="accent">{copy.save_label}</TextBlock> : null;
      case 'trust':
        return copy.trust ? <TextBlock key={key} size="small" appearance="subdued">{copy.trust}</TextBlock> : null;
      case 'disclosure':
        return copy.disclosure ? <TextBlock key={key} size="small" appearance="subdued">{copy.disclosure}</TextBlock> : null;
      default:
        return null;
    }
  };

  async function accept() {
    setBusy(true);
    setError('');

    try {
      const response = await fetch(SIGN_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token: inputData.token, offer_id: offer.offer_id }),
      });

      const body = await response.json();
      if (!response.ok || !body.ok || !body.changeset) {
        setBusy(false);
        setError(body?.reason || 'could_not_apply');

        return;
      }

      // Shopify charges here — on the SAME payment method as the checkout.
      const result = await applyChangeset(body.changeset);
      if (result?.errors?.length) {
        setBusy(false);
        setError('could_not_apply');

        return;
      }

      done();
    } catch (e) {
      setBusy(false);
      setError('network');
    }
  }

  // Text blocks in the merchant's order, minus the ones with their own slot
  // (image sits in the media column; the buttons are pinned below the copy so a
  // reordered list can never put "No thanks" above the price it declines).
  const textBlocks = order.filter((k) => k !== 'image' && k !== 'cta' && k !== 'decline').map(block);

  const showImage = on.image && offer.image_url;
  const mediaSide = p.layout === 'media_side';
  const spacing = p.spacing ?? 'loose';

  const copyColumn = (
    <BlockStack spacing="xloose">
      <TextContainer>{textBlocks}</TextContainer>

      {error ? <TextBlock appearance="critical">{copy.error_text}</TextBlock> : null}

      <BlockStack spacing="tight">
        <Button submit onPress={accept} loading={busy}>
          {busy ? copy.accept_busy : copy.accept_cta}
        </Button>
        {on.decline ? (
          <Button
            plain={p.decline_style === 'link'}
            subdued={p.decline_style !== 'link'}
            onPress={done}
            disabled={busy}
          >
            {copy.decline_cta}
          </Button>
        ) : null}
      </BlockStack>
    </BlockStack>
  );

  return (
    <BlockStack spacing={spacing}>
      {copy.headline ? (
        <CalloutBanner title={copy.headline}>
          <TextContainer>
            <Text size="medium">{copy.subcopy ?? offer.description}</Text>
          </TextContainer>
        </CalloutBanner>
      ) : null}

      {mediaSide && showImage ? (
        <Layout
          media={[
            { viewportSize: 'small', sizes: [1, 0, 1] },
            { viewportSize: 'medium', sizes: [532, 0, 1] },
            { viewportSize: 'large', sizes: [560, 38, 340] },
          ]}
        >
          <View>
            <Image description={copy.product_name ?? offer.title} source={offer.image_url} />
          </View>
          <View />
          {copyColumn}
        </Layout>
      ) : (
        <BlockStack spacing={spacing}>
          {showImage ? (
            <Image description={copy.product_name ?? offer.title} source={offer.image_url} />
          ) : null}
          {copyColumn}
        </BlockStack>
      )}
    </BlockStack>
  );
}
