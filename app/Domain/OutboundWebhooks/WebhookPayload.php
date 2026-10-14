<?php

namespace App\Domain\OutboundWebhooks;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use Carbon\CarbonInterface;

/**
 * The JSON a merchant's endpoint receives — ONE shape for every topic:
 *
 *   { event_id, topic, created_at, test, shop: {...}, data: { charge, order, customer, subscription } }
 *
 * `data` always carries the same four keys (null where a part does not apply, e.g.
 * no `subscription` on a plain store checkout), so a receiving scenario can map the
 * fields once and use them for every topic.
 *
 * Never carries a card token, a PayPlus credential, or the raw gateway response:
 * only what the merchant's own systems need to act on the sale.
 *
 * Every builder reads under the bound tenant (the delivery job's TenantContext), so
 * a source id can never reach another shop's row.
 */
final class WebhookPayload
{
    // === CONSTANTS ===
    public const VERSION = '2026-10-14';

    public const SOURCE_LEDGER = 'ledger';

    public const SOURCE_PAYMENT_FAILED = 'payment_failed';

    public const SOURCE_BILLING_ATTEMPT = 'billing_attempt';

    public const SOURCE_WOO_ORDER = 'woo_order';

    /** Every key the `order` block carries, whatever the source (plus `items` and `coupons`). */
    public const ORDER_KEYS = [
        'id', 'number', 'status', 'role', 'parent_order_id', 'currency', 'total', 'subtotal',
        'discount_total', 'shipping_total', 'tax_total', 'payment_method', 'payment_method_title',
        'transaction_id', 'customer_note', 'created_at', 'paid_at', 'billing', 'shipping',
    ];

    /**
     * Wrap a topic's `data` in the envelope.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function envelope(Shop $shop, string $topic, string $eventKey, array $data, bool $test = false): array
    {
        return [
            'event_id' => self::eventId($shop, $eventKey),
            'topic' => $topic,
            'api_version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'test' => $test,
            'shop' => [
                'id' => (int) $shop->getKey(),
                'name' => (string) ($shop->business_name ?: $shop->name ?: ''),
                'domain' => $shop->displayDomain(),
                'platform' => (string) $shop->platform,
            ],
            'data' => $data,
        ];
    }

    /** Stable per event, identical on every retry — the receiver's dedupe key. */
    public static function eventId(Shop $shop, string $eventKey): string
    {
        return 'evt_'.substr(hash('sha256', $shop->getKey().'|'.$eventKey), 0, 32);
    }

    /**
     * Build `data` from a delivery's source. Null when the source row is gone.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    public function dataFor(array $source): ?array
    {
        return match ($source['kind'] ?? null) {
            self::SOURCE_LEDGER => $this->fromLedger((int) ($source['ledger_id'] ?? 0)),
            self::SOURCE_PAYMENT_FAILED => $this->fromFailedPayment($source),
            self::SOURCE_BILLING_ATTEMPT => $this->fromBillingAttempt((int) ($source['attempt_id'] ?? 0)),
            self::SOURCE_WOO_ORDER => is_array($source['order'] ?? null) ? $this->fromWooOrder($source['order']) : null,
            default => null,
        };
    }

    /**
     * The `order` block — the same keys for every source, null where a source
     * does not know a field (a PayPlus renewal has no line items; a WooCommerce
     * report has them all).
     *
     * @param  array<string, mixed>  $known
     * @return array<string, mixed>
     */
    public static function order(array $known): array
    {
        return array_merge(array_fill_keys(self::ORDER_KEYS, null), ['items' => [], 'coupons' => []], $known);
    }

    /**
     * A WooCommerce order the plugin reported (OrderWebhookController already
     * bounded every field). WooCommerce is the order truth here — the charge block
     * mirrors the order's own total and gateway.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function fromWooOrder(array $order): array
    {
        $billing = (array) ($order['billing'] ?? []);
        $planId = $order['plan_public_id'] ?? null;
        $plan = $planId ? InstallmentPlan::query()->where('public_id', $planId)->first() : null;
        $name = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));

        return [
            'charge' => [
                'id' => null,
                'status' => 'succeeded',
                'context' => 'store_order',
                'amount' => (float) ($order['total'] ?? 0),
                'currency' => (string) ($order['currency'] ?? ''),
                'gateway' => $order['payment_method'] ?? null,
                'transaction_uid' => $order['transaction_id'] ?? null,
                'idempotency_key' => null,
                'attempt' => null,
                'failure_code' => null,
                'failure_message' => null,
                'will_retry' => false,
                'next_retry_at' => null,
                'charged_at' => $order['paid_at'] ?? null,
            ],
            'order' => self::order(array_intersect_key($order, array_flip(self::ORDER_KEYS)) + [
                'items' => (array) ($order['items'] ?? []),
                'coupons' => (array) ($order['coupons'] ?? []),
            ]),
            'customer' => $this->customer($name, $billing['email'] ?? null, $billing['phone'] ?? null, $order['customer_id'] ?? null),
            'subscription' => $plan ? $this->plan($plan) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function fromLedger(int $ledgerId): ?array
    {
        $row = PaymentLedger::query()->with('plan')->find($ledgerId);
        if ($row === null) {
            return null;
        }

        $plan = $row->plan;

        return [
            'charge' => [
                'id' => (int) $row->getKey(),
                'status' => (string) $row->status,
                'context' => (string) $row->charge_context,
                'amount' => (float) $row->amount,
                'currency' => (string) $row->currency,
                'gateway' => 'payplus',
                'transaction_uid' => $row->payplus_transaction_uid,
                'idempotency_key' => (string) $row->idempotency_key,
                'attempt' => null,
                'failure_code' => null,
                'failure_message' => null,
                'will_retry' => false,
                'next_retry_at' => null,
                'charged_at' => self::date($row->updated_at),
            ],
            'order' => self::order([
                'id' => $row->child_order_id ?: $row->shopify_order_id ?: $row->parent_order_id ?: $plan?->externalOrderId(),
                'parent_order_id' => $row->parent_order_id,
                'currency' => (string) $row->currency,
                'total' => (float) $row->amount,
                'payment_method' => 'payplus',
                'transaction_id' => $row->payplus_transaction_uid,
            ]),
            'customer' => $this->customer(
                $row->customer_name ?: $plan?->customer_name,
                $row->customer_email ?: $plan?->customer_email,
                $plan?->customer_phone,
                $row->shopify_customer_id ?: $plan?->externalCustomerId(),
            ),
            'subscription' => $plan ? $this->plan($plan) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    public function fromFailedPayment(array $source): ?array
    {
        $payment = InstallmentPayment::query()->find((int) ($source['payment_id'] ?? 0));
        $plan = $payment ? InstallmentPlan::query()->find($payment->plan_id) : null;
        if ($payment === null || $plan === null) {
            return null;
        }

        $willRetry = (bool) ($source['will_retry'] ?? false);

        return [
            'charge' => [
                'id' => null,
                'status' => 'failed',
                'context' => $payment->payment_type?->value,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
                'gateway' => 'payplus',
                'transaction_uid' => null,
                'idempotency_key' => null,
                'attempt' => (int) ($source['attempt'] ?? $payment->attempt_count),
                'failure_code' => $source['error_code'] ?? $payment->failure_code,
                'failure_message' => $source['error_message'] ?? $payment->failure_message,
                'will_retry' => $willRetry,
                'next_retry_at' => $willRetry ? self::date($payment->next_retry_at) : null,
                'charged_at' => null,
            ],
            'order' => self::order(['id' => $plan->externalOrderId(), 'currency' => (string) $payment->currency]),
            'customer' => $this->customer($plan->customer_name, $plan->customer_email, $plan->customer_phone, $plan->externalCustomerId()),
            'subscription' => $this->plan($plan) + ['payment_sequence' => (int) $payment->sequence],
        ];
    }

    /** @return array<string, mixed>|null */
    public function fromBillingAttempt(int $attemptId): ?array
    {
        $attempt = SubscriptionBillingAttempt::query()->find($attemptId);
        $contract = $attempt ? SubscriptionContract::query()->find($attempt->subscription_contract_id) : null;
        if ($attempt === null || $contract === null) {
            return null;
        }

        $succeeded = $attempt->status === SubscriptionBillingAttempt::STATUS_SUCCEEDED;

        return [
            'charge' => [
                'id' => (int) $attempt->getKey(),
                'status' => (string) $attempt->status,
                'context' => PaymentLedger::CONTEXT_RECURRING,
                'amount' => $contract->amount !== null ? (float) $contract->amount : null,
                'currency' => (string) $contract->currency,
                'gateway' => 'shopify_payments',
                'transaction_uid' => $attempt->shopify_attempt_gid,
                'idempotency_key' => (string) $attempt->idempotency_key,
                'attempt' => null,
                'failure_code' => $succeeded ? null : $attempt->error_code,
                'failure_message' => $succeeded ? null : $attempt->error_message,
                'will_retry' => false,
                'next_retry_at' => null,
                'charged_at' => $succeeded ? self::date($attempt->resolved_at) : null,
            ],
            'order' => self::order([
                'id' => $attempt->shopify_order_gid,
                'currency' => (string) $contract->currency,
                'total' => $succeeded && $contract->amount !== null ? (float) $contract->amount : null,
                'payment_method' => 'shopify_payments',
            ]),
            'customer' => $this->customer($contract->customer_name, $contract->customer_email, null, $contract->shopify_customer_gid),
            'subscription' => [
                'id' => (int) $contract->getKey(),
                'public_id' => (string) $contract->shopify_gid,
                'kind' => 'recurring',
                'status' => strtolower((string) $contract->status),
                'product_title' => data_get($contract->lines, '0.title'),
                'amount' => $contract->amount !== null ? (float) $contract->amount : null,
                'frequency' => SubscriptionContract::INTERVAL_FREQUENCY[(string) $contract->interval] ?? null,
                'interval_count' => (int) $contract->interval_count,
                'next_charge_at' => self::date($contract->next_billing_date),
                'billing_cycle' => (string) $attempt->billing_cycle_key,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function plan(InstallmentPlan $plan): array
    {
        return [
            'id' => (int) $plan->getKey(),
            'public_id' => $plan->public_id,
            'kind' => $plan->plan_kind?->value,
            'status' => $plan->status?->value,
            'product_title' => $plan->productTitle(),
            'amount' => $plan->installment_amount !== null ? (float) $plan->installment_amount : null,
            'frequency' => $plan->billing_frequency?->value,
            'interval_count' => (int) $plan->interval_count,
            'next_charge_at' => self::date($plan->next_charge_at),
            'total_amount' => (float) $plan->total_amount,
            'total_charged' => (float) $plan->total_charged,
            'remaining_amount' => $plan->isRecurring() ? null : $plan->remainingAmount(),
        ];
    }

    /** @return array<string, ?string> */
    private function customer(?string $name, ?string $email, ?string $phone, ?string $externalId): array
    {
        return [
            'name' => self::nullable($name),
            'email' => self::nullable($email),
            'phone' => self::nullable($phone),
            'external_id' => self::nullable($externalId),
        ];
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function date(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }
}
