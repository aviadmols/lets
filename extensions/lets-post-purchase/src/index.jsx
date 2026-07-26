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

  const money = (amount) => `${Number(amount).toFixed(2)} ${offer.currency || ''}`.trim();
  const saves = Number(offer.base_price) > Number(offer.price);

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

  return (
    <BlockStack spacing="loose">
      <CalloutBanner title={offer.title}>
        <TextContainer>
          <Text size="medium">{offer.description}</Text>
        </TextContainer>
      </CalloutBanner>

      <Layout
        media={[
          { viewportSize: 'small', sizes: [1, 0, 1] },
          { viewportSize: 'medium', sizes: [532, 0, 1] },
          { viewportSize: 'large', sizes: [560, 38, 340] },
        ]}
      >
        <View>
          {offer.image_url ? <Image description={offer.title} source={offer.image_url} /> : null}
        </View>

        <View />

        <BlockStack spacing="xloose">
          <TextContainer>
            <Heading>{offer.title}</Heading>
            <Tiles>
              <Text role="deletion" appearance="subdued">
                {saves ? money(offer.base_price) : ''}
              </Text>
              <Text emphasized size="large">{money(offer.price)}</Text>
            </Tiles>
          </TextContainer>

          {error ? (
            <TextBlock appearance="critical">
              We could not add this to your order. You have not been charged for it.
            </TextBlock>
          ) : null}

          <BlockStack spacing="tight">
            <Button submit onPress={accept} loading={busy}>
              Pay now · {money(offer.price)}
            </Button>
            <Button subdued onPress={done} disabled={busy}>
              No thanks
            </Button>
          </BlockStack>

          <TextBlock size="small" appearance="subdued">
            Charged to the payment method you just used. No card details are re-entered.
          </TextBlock>
        </BlockStack>
      </Layout>
    </BlockStack>
  );
}
