<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * The SERVER-SIDE trigger for a gateway order's accounting document.
 *
 * The WordPress plugin's woocommerce_order_status_changed hook was the single
 * trigger — and production proved how fragile that is: a payment confirmation
 * marked order 2816 paid, ANOTHER plugin on the site fataled inside the same
 * status-change hook chain (WP returned 500 after the status had already saved),
 * and the second confirmation path found the status unchanged, so the hook never
 * fired again. Order paid, ledger recorded, document lost — permanently, because
 * a status change happens once.
 *
 * The SaaS is the party that MARKED the order paid; it does not need WordPress to
 * echo that fact back. So the finalizer now reports the order for invoicing
 * itself, through THE SAME gates InvoicingController::issue applies to the
 * plugin's reports (scope, plan wall, trigger statuses, money present, already
 * issued) and onto THE SAME deterministic key — doc:order:{shop}:{order} — which
 * is what makes belt + braces safe: if the plugin's hook DOES also fire, both
 * reports converge on one issued_documents row. Double-issuing is prevented by
 * construction, not by hoping only one path runs.
 *
 * Fail-soft by contract: report() never throws — a document problem must never
 * un-pay an order.
 */
final class WooGatewayInvoiceReporter
{
    // === CONSTANTS ===
    /** Line-item cap, mirroring InvoicingController::MAX_LINES. */
    private const MAX_LINES = 100;

    /** The gateway id reported as the document's payment method context. */
    private const PAYMENT_GATEWAY = 'lets_payplus';

    /**
     * Queue the document for a gateway order just marked paid, if the merchant's
     * settings call for one. $order is the WC REST order (the updateOrder
     * response); $payplusBody is the confirmation the card digits come from.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payplusBody
     */
    public function report(Shop $shop, string $orderId, array $order, array $payplusBody): void
    {
        try {
            $this->doReport($shop, $orderId, $order, $payplusBody);
        } catch (\Throwable $e) {
            Log::warning('woocommerce.gateway.invoice_report_failed', [
                'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $payplusBody */
    private function doReport(Shop $shop, string $orderId, array $order, array $payplusBody): void
    {
        $shopId = (int) $shop->getKey();
        $settings = MerchantInvoicingSettings::forShop($shopId);

        // The same gates, in the same order, as InvoicingController::issue —
        // this class must never be a way around a wall the endpoint enforces.
        if (! $settings->isEnabled() || ! $shop->hasInvoicingConnection()) {
            return;
        }
        if (! $settings->coversAllOrders()) {
            return;
        }
        if ($this->belongsToPlan($shop, $order, $orderId)) {
            return; // the plan pipeline owns this order's paperwork.
        }
        if (! $settings->triggersOn((string) ($order['status'] ?? ''))) {
            return;
        }

        $total = round((float) ($order['total'] ?? 0), 2);
        if ($total <= 0) {
            return;
        }

        if ($this->alreadyIssued($shop, $orderId)) {
            return;
        }

        IssueDocumentJob::dispatch(
            shopId: $shopId,
            context: DocumentContext::PLATFORM_ORDER->value,
            order: $this->orderPayload($order, $orderId, $total, $payplusBody),
        );

        Log::info('woocommerce.gateway.invoice_reported', [
            'shop_id' => $shopId, 'order_id' => $orderId,
        ]);
    }

    /**
     * The plan wall, translated to the WC REST order shape: the plan meta the
     * plugin stamps, OR our own plan table (which PlanActivationService has
     * already stamped by the time the finalizer calls this).
     *
     * @param  array<string, mixed>  $order
     */
    private function belongsToPlan(Shop $shop, array $order, string $orderId): bool
    {
        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            if (is_array($meta)
                && in_array($meta['key'] ?? '', ['lets_plan_public_id', 'lets_subscription_plan_ids'], true)
                && ! empty($meta['value'])) {
                return true;
            }
        }

        return Tenant::run($shop, static fn (): bool => InstallmentPlan::query()
            ->where(fn (Builder $q) => $q
                ->where('external_order_id', $orderId)
                ->orWhere('shopify_order_id', $orderId))
            ->exists());
    }

    private function alreadyIssued(Shop $shop, string $orderId): bool
    {
        $key = DocumentIssuer::keyForPlatformOrder((int) $shop->getKey(), $orderId);

        return Tenant::run($shop, static fn (): bool => IssuedDocument::query()
            ->where('idempotency_key', $key)
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->exists());
    }

    /**
     * The WC REST order, coerced into the SAME neutral shape the plugin reports —
     * DocumentIssuer must not care which path delivered the order.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payplusBody
     * @return array<string, mixed>
     */
    private function orderPayload(array $order, string $orderId, float $total, array $payplusBody): array
    {
        $billing = (array) ($order['billing'] ?? []);
        $name = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));

        $lines = [];
        foreach (array_slice((array) ($order['line_items'] ?? []), 0, self::MAX_LINES) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = round((float) ($item['total'] ?? 0), 2);
            $description = trim((string) ($item['name'] ?? ''));
            if ($description === '' || $lineTotal <= 0) {
                continue;
            }
            $lines[] = [
                'description' => $description,
                'unit_price' => round($lineTotal / $quantity, 2),
                'quantity' => $quantity,
                'catalog_number' => ((string) ($item['sku'] ?? '')) ?: null,
            ];
        }

        $lastFour = preg_replace('/\D/', '', (string) (
            data_get($payplusBody, 'data.transaction.four_digits')
            ?? data_get($payplusBody, 'transaction.four_digits')
            ?? data_get($payplusBody, 'data.four_digits')
            ?? data_get($payplusBody, 'four_digits') ?? ''
        )) ?? '';

        return [
            'order_id' => $orderId,
            'order_number' => ((string) ($order['number'] ?? '')) ?: $orderId,
            'total' => $total,
            'currency' => strtoupper((string) ($order['currency'] ?? config('payplus.currency', 'ILS'))),
            'customer' => [
                'name' => $name,
                'email' => ((string) ($billing['email'] ?? '')) ?: null,
                'phone' => ((string) ($billing['phone'] ?? '')) ?: null,
                'tax_id' => null,
            ],
            'lines' => $lines,
            'payment_gateway' => self::PAYMENT_GATEWAY,
            'card_last4' => strlen($lastFour) === 4 ? $lastFour : null,
        ];
    }
}
