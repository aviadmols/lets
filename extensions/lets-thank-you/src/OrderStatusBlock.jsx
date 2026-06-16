/** @jsxImportSource preact */
// === Target: customer-account.order-status.block.render ===
//
// Same LETS upsell widget on the customer-account Order-status page (the order
// page the customer can revisit post-checkout). Useful when the thank-you offer
// was missed or the merchant wants a persistent offer surface. Same App-Proxy
// fetch, same signed token-charge accept.
//
// 2026-04 runtime: Preact + @shopify/ui-extensions.

import '@shopify/ui-extensions/preact';
import { render } from 'preact';
import { UpsellWidget } from './UpsellWidget.jsx';

export default function () {
  render(<OrderStatus />, document.body);
}

function OrderStatus() {
  // On the order-status target the order is exposed via the account API.
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

  // shopify.i18n drives localised copy (locales/*.json) + locale-aware currency.
  return <UpsellWidget context={context} i18n={shopify?.i18n} />;
}
