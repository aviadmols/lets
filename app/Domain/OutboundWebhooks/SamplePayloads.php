<?php

namespace App\Domain\OutboundWebhooks;

use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Models\InstallmentPayment;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Test JSONs for the screen — "show me what you will send" and "send one now".
 *
 * A sample is built from the shop's OWN latest matching event when there is one
 * (through the very builder the live events use, so the sample cannot drift from
 * the real thing), and from a fabricated example when the shop has none yet.
 * Either way it is flagged `"test": true` and carries a fresh event_id, so a
 * receiver never confuses it with — or dedupes it against — a real charge.
 */
final class SamplePayloads
{
    // === CONSTANTS ===
    public const ORIGIN_REAL = 'real';

    public const ORIGIN_EXAMPLE = 'example';

    public function __construct(private readonly WebhookPayload $payload) {}

    /**
     * @param  bool  $preferReal  build from the shop's latest real event when one exists
     * @return array{payload: array<string, mixed>, origin: string, event_key: string}
     */
    public function for(Shop $shop, string $topic, bool $preferReal = true): array
    {
        $data = $preferReal ? $this->realData($shop, $topic) : null;
        $origin = $data === null ? self::ORIGIN_EXAMPLE : self::ORIGIN_REAL;
        $data ??= $this->example($shop, $topic);

        $eventKey = 'test:'.$topic.':'.Str::uuid()->toString();

        return [
            'payload' => WebhookPayload::envelope($shop, $topic, $eventKey, $data, test: true),
            'origin' => $origin,
            'event_key' => $eventKey,
        ];
    }

    /** @return array<string, mixed>|null */
    private function realData(Shop $shop, string $topic): ?array
    {
        return match ($topic) {
            WebhookTopic::ORDER_PAID => $shop->platform === Shop::PLATFORM_WOOCOMMERCE
                ? $this->latestWooOrder()
                : $this->latestLedger(WebhookTopic::ORDER_CONTEXTS),
            WebhookTopic::RECURRING_SUCCEEDED => $this->latestLedger(WebhookTopic::RECURRING_CONTEXTS)
                ?? $this->latestAttempt(SubscriptionBillingAttempt::STATUS_SUCCEEDED),
            WebhookTopic::RECURRING_FAILED => $this->latestFailedPayment()
                ?? $this->latestAttempt(SubscriptionBillingAttempt::STATUS_FAILED),
            default => null,
        };
    }

    /**
     * @param  list<string>  $contexts
     * @return array<string, mixed>|null
     */
    private function latestLedger(array $contexts): ?array
    {
        $id = PaymentLedger::query()
            ->whereIn('charge_context', $contexts)
            ->whereIn('status', [PaymentLedger::STATUS_SUCCEEDED, PaymentLedger::STATUS_REFUNDED])
            ->latest('id')
            ->value('id');

        return $id === null ? null : $this->payload->fromLedger((int) $id);
    }

    /**
     * A WooCommerce store's orders live only in what its plugin reported — the
     * newest report any of its endpoints received is the real sample.
     *
     * @return array<string, mixed>|null
     */
    private function latestWooOrder(): ?array
    {
        $source = WebhookDelivery::query()
            ->where('topic', WebhookTopic::ORDER_PAID)
            ->where('is_test', false)
            ->where('event_key', 'like', 'woo_order:%')
            ->latest('id')
            ->value('source');

        $source = is_string($source) ? json_decode($source, true) : $source;

        return is_array($source['order'] ?? null) ? $this->payload->fromWooOrder($source['order']) : null;
    }

    /** @return array<string, mixed>|null */
    private function latestFailedPayment(): ?array
    {
        $payment = InstallmentPayment::query()
            ->whereIn('payment_type', [PaymentType::INSTALLMENT->value, PaymentType::RECURRING->value])
            ->whereNotNull('failure_message')
            ->latest('updated_at')
            ->first();

        return $payment === null ? null : $this->payload->fromFailedPayment([
            'payment_id' => $payment->getKey(),
            'attempt' => $payment->attempt_count,
            'will_retry' => $payment->next_retry_at !== null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function latestAttempt(string $status): ?array
    {
        $id = SubscriptionBillingAttempt::query()->where('status', $status)->latest('id')->value('id');

        return $id === null ? null : $this->payload->fromBillingAttempt((int) $id);
    }

    /**
     * A believable, obviously-fake event for a shop with no history yet.
     *
     * @return array<string, mixed>
     */
    private function example(Shop $shop, string $topic): array
    {
        $failed = $topic === WebhookTopic::RECURRING_FAILED;
        $isOrder = $topic === WebhookTopic::ORDER_PAID;
        $now = now();

        return [
            'charge' => [
                'id' => $failed ? null : 1001,
                'status' => $failed ? 'failed' : 'succeeded',
                'context' => $isOrder ? PaymentLedger::CONTEXT_GATEWAY : PaymentLedger::CONTEXT_RECURRING,
                'amount' => 129.90,
                'currency' => 'ILS',
                'gateway' => 'payplus',
                'transaction_uid' => $failed ? null : '00000000-0000-4000-8000-000000000001',
                'idempotency_key' => $failed ? null : 'shop:'.$shop->getKey().':example:1001',
                'attempt' => $failed ? 1 : null,
                'failure_code' => $failed ? '1' : null,
                'failure_message' => $failed ? __('webhooks.sample.decline') : null,
                'will_retry' => $failed,
                'next_retry_at' => $failed ? $now->copy()->addDays(3)->toIso8601String() : null,
                'charged_at' => $failed ? null : $now->toIso8601String(),
            ],
            'order' => WebhookPayload::order($isOrder ? $this->exampleOrder($now) : ['id' => '10001', 'currency' => 'ILS']),
            'customer' => [
                'name' => __('webhooks.sample.customer_name'),
                'email' => 'customer@example.com',
                'phone' => '050-0000000',
                'external_id' => '501',
            ],
            'subscription' => $isOrder ? null : [
                'id' => 501,
                'public_id' => 'example-plan-501',
                'kind' => 'recurring',
                'status' => 'active',
                'product_title' => __('webhooks.sample.product_title'),
                'amount' => 129.90,
                'frequency' => 'monthly',
                'interval_count' => 1,
                'next_charge_at' => $now->copy()->addMonth()->toIso8601String(),
                'total_amount' => 0.0,
                'total_charged' => $failed ? 389.70 : 519.60,
                'remaining_amount' => null,
            ],
        ];
    }

    /** @return array<string, mixed> a full example order, the shape a WooCommerce report fills */
    private function exampleOrder(CarbonInterface $now): array
    {
        $address = [
            'first_name' => __('webhooks.sample.first_name'),
            'last_name' => __('webhooks.sample.last_name'),
            'company' => null,
            'address_1' => __('webhooks.sample.street'),
            'address_2' => null,
            'city' => __('webhooks.sample.city'),
            'state' => null,
            'postcode' => '6100000',
            'country' => 'IL',
            'email' => 'customer@example.com',
            'phone' => '050-0000000',
        ];

        return [
            'id' => '10001',
            'number' => '10001',
            'status' => 'processing',
            'role' => 'checkout',
            'currency' => 'ILS',
            'total' => 129.90,
            'subtotal' => 119.90,
            'discount_total' => 0.0,
            'shipping_total' => 10.0,
            'tax_total' => 18.87,
            'payment_method' => 'lets_payplus',
            'payment_method_title' => 'PayPlus',
            'transaction_id' => '00000000-0000-4000-8000-000000000001',
            'created_at' => $now->toIso8601String(),
            'paid_at' => $now->toIso8601String(),
            'billing' => $address,
            'shipping' => $address,
            'coupons' => [],
            'items' => [[
                'product_id' => '501',
                'variation_id' => null,
                'sku' => 'COFFEE-1KG',
                'name' => __('webhooks.sample.product_title'),
                'quantity' => 1,
                'subtotal' => 119.90,
                'total' => 119.90,
                'after_sell' => false,
            ]],
        ];
    }
}
