// === LETS native post-purchase extension (SECONDARY path) ===
//
// @shopify/post-purchase-ui-extensions(-react). Two extension points:
//   - Checkout::PostPurchase::ShouldRender → decide whether to show the page.
//   - Checkout::PostPurchase::Render       → render the offer + accept/decline.
//
// TWO accept paths, deliberately:
//   (A) PRIMARY — LETS PayPlus token charge (works for IL PayPlus merchants).
//       On accept we POST the app's signed accept endpoint, which charges the
//       already-saved PayPlus token and creates the linked child order. NO
//       changeset, so it is independent of Shopify Payments. This mirrors the
//       thank-you widget (extensions/lets-thank-you) exactly.
//   (B) SECONDARY — native applyChangeset. Adds the offer as a line item to the
//       just-placed order using Shopify's signed changeset. This requires the
//       store to support post-purchase payment (effectively Shopify Payments),
//       which IL PayPlus merchants usually DON'T have — so it is gated behind a
//       capability flag proven in Phase 0.5 (CAPABILITY.nativeChangeset).
//
// SECURITY / money law: the offer + price come from the LETS app (App Proxy,
// server-computed); the extension never invents an amount. For the native path,
// Shopify signs the changeset (`calculateChangeset` → `applyChangeset` with the
// JWT) so the buyer authorises the exact server amount.

import { extend, render, useExtensionInput, BlockStack, Button, CalloutBanner, Layout, TextBlock, TextContainer, View } from '@shopify/post-purchase-ui-extensions-react';

// === CONSTANTS ===
// Until Phase-0.5 verification proves the target store supports native
// post-purchase payment (Shopify Payments), keep the native changeset OFF and use
// the LETS token path. Flip per-store once verified.
const CAPABILITY = { nativeChangeset: false };

// The app's App-Proxy offer endpoint (server resolves the eligible offer). The
// post-purchase frame is cross-origin, so we call the app's absolute URL. The
// app verifies the post-purchase input token (see the AUTH HANDSHAKE note below).
const APP_ORIGIN = 'https://app.lets.co.il';
const OFFER_URL = `${APP_ORIGIN}/proxy/upsell/offer`;

// ── ShouldRender: only show the interstitial when the app has an eligible offer.
extend('Checkout::PostPurchase::ShouldRender', async ({ inputData, storage }) => {
  const offer = await resolveOffer(inputData);
  // Cache the resolved offer so Render doesn't refetch.
  await storage.update({ offer });
  return { render: Boolean(offer) };
});

// ── Render: present the offer with one-click accept / decline.
render('Checkout::PostPurchase::Render', () => <PostPurchaseOffer />);

function PostPurchaseOffer() {
  const { storage, inputData, applyChangeset, calculateChangeset, done } = useExtensionInput();
  const offer = storage.initialData?.offer;

  if (!offer) {
    // No eligible offer — finish immediately (Shopify advances to order status).
    done();
    return null;
  }

  async function accept() {
    if (CAPABILITY.nativeChangeset) {
      // SECONDARY: native changeset (Shopify Payments stores only). The changeset
      // token is server-signed (calculateChangeset on the app) so the buyer is
      // charged the exact server amount; then applyChangeset commits it.
      await applyChangeset(offer.changesetToken);
    } else {
      // PRIMARY: LETS token charge. POST the SIGNED accept URL the app returned —
      // charges the saved PayPlus token + creates the linked child order. The
      // amount is recomputed server-side; the extension sends no amount.
      await fetch(offer.accept_api_url, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: bearerFromInput(inputData) },
      });
    }
    done();
  }

  function decline() {
    // Fire-and-forget decline (records the funnel event); then advance.
    if (offer.decline_url) {
      fetch(offer.decline_url, { method: 'POST', headers: { Authorization: bearerFromInput(inputData) } }).catch(() => {});
    }
    done();
  }

  return (
    <BlockStack spacing="loose">
      <CalloutBanner title={offer.title}>
        <TextBlock>{formatPrice(offer.price, offer.currency)}</TextBlock>
      </CalloutBanner>
      <Layout>
        <View>
          <TextContainer>
            <TextBlock>{offer.title}</TextBlock>
          </TextContainer>
          <BlockStack spacing="tight">
            <Button onPress={accept}>Add to my order</Button>
            <Button subdued onPress={decline}>No thanks</Button>
          </BlockStack>
        </View>
      </Layout>
    </BlockStack>
  );
}

// === App-Proxy offer resolution ===
async function resolveOffer(inputData) {
  try {
    const purchase = inputData?.initialPurchase ?? {};
    const params = new URLSearchParams({
      parent_order: String(purchase.referenceId ?? ''),
      customer: String(purchase.customerId ?? ''),
      subtotal: String(purchase.totalPriceSet?.totalPrice?.amount ?? 0),
      products: (purchase.lineItems ?? [])
        .map((l) => l.product?.id ?? l.productId)
        .filter(Boolean)
        .join(','),
    });

    const res = await fetch(`${OFFER_URL}?${params.toString()}`, {
      headers: { Accept: 'application/json', Authorization: bearerFromInput(inputData) },
    });
    if (!res.ok) return null;
    const body = await res.json();
    return body?.offer ? body : null; // keep accept_api_url / decline_url too
  } catch {
    return null;
  }
}

// ── AUTH HANDSHAKE (TODO — Phase 6.x): post-purchase extensions don't get an App
// Proxy signature; instead Shopify gives the extension a signed input `token`
// (inputData.token, a JWT signed with the app secret). The app endpoint must
// verify THAT token (aud == api_key, signed by SHOPIFY_API_SECRET) to authenticate
// + derive the shop, instead of the App-Proxy signature. For v1 the PRIMARY path
// is the thank-you token widget (App-Proxy signed, already wired + tested); this
// native extension's exact token-verification handshake is the remaining wiring.
function bearerFromInput(inputData) {
  return `Bearer ${inputData?.token ?? ''}`;
}

function formatPrice(amount, currency) {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ILS' }).format(amount);
  } catch {
    return `${amount} ${currency || 'ILS'}`;
  }
}
