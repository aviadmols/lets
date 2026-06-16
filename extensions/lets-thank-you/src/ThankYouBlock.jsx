// === Target: purchase.thank-you.block.render ===
//
// The PRIMARY post-purchase surface. Runs on the Thank-you page right after
// checkout. Reads the just-placed order from the extension API, builds the
// purchase context, and mounts the LETS upsell widget. The widget fetches the
// eligible offer through the App Proxy and charges the saved PayPlus token on
// accept (no card re-entry).
//
// 2026-04 runtime: Preact + @shopify/ui-extensions. The entrypoint renders into
// document.body; `shopify.*` is the global extension API for this target.

import '@shopify/ui-extensions/preact';
import { render } from 'preact';
import { UpsellWidget } from './UpsellWidget.jsx';

export default function () {
  render(<ThankYou />, document.body);
}

function ThankYou() {
  // The order/customer facts the resolver needs. `shopify.order` is the placed
  // order on the thank-you target; gids are normalised by the app-side resolver.
  const order = shopify?.order?.current ?? shopify?.order ?? {};
  const lines = order.lineItems ?? [];

  const context = {
    parentOrderId: String(order.id ?? order.orderId ?? ''),
    customerRef: String(order.customer?.id ?? order.customerId ?? ''),
    subtotal: Number(order.subtotal?.amount ?? order.totalPrice?.amount ?? 0),
    productGids: lines.map((l) => l.merchandise?.product?.id ?? l.productId).filter(Boolean),
    email: order.customer?.email ?? order.email ?? undefined,
    currency: order.totalPrice?.currencyCode ?? order.currencyCode ?? 'ILS',
  };

  return <UpsellWidget context={context} />;
}
