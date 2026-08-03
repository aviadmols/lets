<?php

namespace App\Mail;

use App\Mail\Concerns\UsesCustomMailTemplate;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your order was updated" — the shopper added something after checkout, and the
 * confirmation the platform already sent is now out of date.
 *
 * Sent ONLY to shoppers who actually added an item. The store keeps sending its
 * own order confirmation; a second "here is your order" for an order nobody
 * changed is noise the merchant would have to apologise for.
 *
 * `{items_table}` arrives PRE-RENDERED as one scalar (OrderUpdatedNotifier).
 * The renderer substitutes scalars with strtr and nothing else — the RCE wall —
 * so a merchant body cannot iterate a collection, and giving it one would mean
 * compiling merchant HTML.
 */
final class OrderUpdatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomMailTemplate;

    public function __construct(
        public readonly Shop $shop,
        public readonly string $orderNumber,
        public readonly string $itemsTable,
        public readonly string $addedTotal,
        public readonly string $currency,
        public readonly string $customerName = '',
    ) {}

    public function envelope(): Envelope
    {
        return $this->inMailLocale($this->shop, fn (): Envelope => $this->buildEnvelope(
            MerchantMailSettings::TEMPLATE_ORDER_UPDATED, $this->shop, $this->vars(),
        ));
    }

    public function content(): Content
    {
        return $this->inMailLocale($this->shop, fn (): Content => $this->buildContent(
            MerchantMailSettings::TEMPLATE_ORDER_UPDATED, $this->shop, $this->vars(),
        ));
    }

    /** @return array<string, scalar|null> */
    private function vars(): array
    {
        return [
            'customer_name' => $this->customerName,
            'business_name' => $this->resolveBusinessName($this->shop),
            'order_number' => $this->orderNumber,
            'items_table' => $this->itemsTable,
            'added_total' => $this->addedTotal,
            'currency' => $this->currency,
        ];
    }
}
