<?php

namespace App\Http\Controllers\WooCommerce;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `all_orders` scope: documents for EVERY order the WooCommerce site receives,
 * not only the ones that went through a LETS plan.
 *
 * LETS never sees a plain WooCommerce checkout — a bank transfer, a cash-on-delivery
 * order, another gateway entirely. So the plugin reports them: when an order reaches
 * a status the merchant chose, the plugin server HMAC-POSTs it here.
 *
 * Two endpoints:
 *   GET  /invoicing-settings         → what the plugin hook needs to decide whether to
 *                                      fire at all (enabled / scope / statuses), so a
 *                                      merchant on `plans_only` costs zero HTTP calls.
 *   POST /orders/issue-document      → report one paid order; we queue its document.
 *
 * The signed body is still MERCHANT INPUT. Everything is read by name, coerced, and
 * bounded here; the money on the document is the total the plugin reports for its own
 * order (WooCommerce is the ORDER truth for a plain order — the `gateway` ledger row
 * WooGatewayFinalizer records is a payment record, and documents key on the order, not
 * on that row), and the double-issue walls are:
 *   1. an order carrying a LETS plan id is REJECTED (it is already invoiced through
 *      the plan pipeline, and invoicing it twice would double-declare the income);
 *   2. the deterministic doc:order:{shop}:{order} key + its unique index.
 */
final class InvoicingController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** Hard cap on reported line items — a document is paperwork, not a data dump. */
    private const MAX_LINES = 100;

    /** GET /api/woocommerce/invoicing-settings — the plugin hook's decision inputs. */
    public function settings(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $settings = MerchantInvoicingSettings::forShop((int) $shop->getKey());

        return response()->json([
            'ok' => true,
            'settings' => [
                // `enabled` reflects BOTH switches the merchant has: the module toggle
                // and a working connection. A plugin told "enabled" for a shop with no
                // credentials would POST orders that can only ever fail.
                'enabled' => $settings->isEnabled() && $shop->hasInvoicingConnection(),
                'scope' => $settings->scope(),
                'trigger_statuses' => $settings->triggerStatuses(),
                'attach_to_order' => $settings->attachesToOrder(),
            ],
        ]);
    }

    /** POST /api/woocommerce/orders/issue-document — report one paid store order. */
    public function issue(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $shopId = (int) $shop->getKey();
        $settings = MerchantInvoicingSettings::forShop($shopId);

        // Module off, unconfigured, or scoped to plans only → nothing to do. Answered
        // as a normal 200 so a plugin whose cached settings are stale simply learns the
        // current scope instead of logging an error storm.
        if (! $settings->isEnabled() || ! $shop->hasInvoicingConnection()) {
            return response()->json(['ok' => true, 'queued' => false, 'reason' => 'disabled']);
        }

        if (! $settings->coversAllOrders()) {
            return response()->json(['ok' => true, 'queued' => false, 'reason' => 'out_of_scope']);
        }

        $orderId = $this->cleanString($request->input('order_id'));
        if ($orderId === null) {
            return response()->json(['error' => 'invalid_order'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Wall 1: this order already belongs to a LETS plan, so the plan pipeline has
        // invoiced (or will invoice) it. Issuing again would double-declare the income.
        //
        // Checked TWO ways, because the plugin's own check runs on a store we do not
        // control: the reported plan meta (fast path), AND our own plan table, which
        // holds the truth even if the store's meta was stripped, or the plugin is an
        // older build that does not send it.
        if ($this->belongsToPlan($shop, $request, $orderId)) {
            return response()->json(['ok' => true, 'queued' => false, 'reason' => 'plan_order']);
        }

        // Wall 1b: a LOYALTY GIFT. Nothing was sold and nothing was paid, so there
        // is no income to declare — a tax document for it would report revenue the
        // merchant never received. The plugin skips gift orders too, but only
        // updated builds do; this is the wall that holds regardless of what the
        // store is running or whether its meta survived.
        if ($this->isGiftOrder($shop, $orderId)) {
            return response()->json(['ok' => true, 'queued' => false, 'reason' => 'gift_order']);
        }

        // The merchant chose which statuses count as "paid". Re-check server-side: the
        // plugin's cached settings can be stale, and a status the merchant did NOT pick
        // must never mint a tax document.
        $status = (string) $request->input('status', '');
        if ($status !== '' && ! $settings->triggersOn($status)) {
            return response()->json(['ok' => true, 'queued' => false, 'reason' => 'status_not_selected']);
        }

        $total = round((float) $request->input('total', 0), 2);
        if ($total <= 0) {
            return response()->json(['error' => 'invalid_total'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Wall 2: already issued → return the existing document rather than a second one.
        $existing = $this->existingDocument($shop, $orderId);
        if ($existing !== null) {
            return response()->json([
                'ok' => true,
                'queued' => false,
                'reason' => 'already_issued',
                'document' => $this->documentPayload($existing),
            ]);
        }

        IssueDocumentJob::dispatch(
            shopId: $shopId,
            context: DocumentContext::PLATFORM_ORDER->value,
            order: $this->orderPayload($request, $orderId, $total, $shop),
        );

        return response()->json(['ok' => true, 'queued' => true]);
    }

    /**
     * Is this store order already owned by a LETS plan? The plugin reports the plan
     * meta it sees; we ALSO look the order up in our own plan table, so a stripped
     * meta field or an older plugin build cannot get an order invoiced twice.
     *
     * The plan lookup runs inside the shop's tenant context, so the BelongsToShop
     * global scope — not a hand-written where — confines it to this tenant.
     */
    private function belongsToPlan(Shop $shop, Request $request, string $orderId): bool
    {
        if ($this->cleanString($request->input(WooCommerceOrderStrategy::META_PLAN_PUBLIC_ID)) !== null) {
            return true;
        }

        return Tenant::run($shop, static fn (): bool => InstallmentPlan::query()
            ->where(fn (Builder $q) => $q
                ->where('external_order_id', $orderId)
                ->orWhere('shopify_order_id', $orderId))
            ->exists());
    }

    /**
     * Did THIS app create this order as a loyalty gift? Looked up in our own
     * records rather than trusted from the reported meta — the plugin's report is
     * merchant input, and a gift order is exactly the case where a stripped meta
     * field would mint a tax document for revenue that does not exist.
     */
    private function isGiftOrder(Shop $shop, string $orderId): bool
    {
        return Tenant::run($shop, static fn (): bool => GiftRecipient::query()
            ->where('external_order_id', $orderId)
            ->exists());
    }

    // === Input shaping ===

    /**
     * The reported order, coerced into the neutral shape DocumentIssuer consumes.
     * Nothing is forwarded verbatim: every field is read by name, typed, and bounded.
     *
     * @return array<string, mixed>
     */
    private function orderPayload(Request $request, string $orderId, float $total, Shop $shop): array
    {
        return [
            'order_id' => $orderId,
            'order_number' => $this->cleanString($request->input('order_number')) ?? $orderId,
            'total' => $total,
            'currency' => $this->currency($request, $shop),
            'customer' => [
                'name' => $this->cleanString($request->input('customer_name')) ?? '',
                'email' => $this->cleanEmail($request->input('customer_email')),
                'phone' => $this->cleanString($request->input('customer_phone')),
                'tax_id' => $this->cleanString($request->input('customer_tax_id')),
            ],
            'lines' => $this->lines($request),
            'payment_gateway' => $this->cleanString($request->input('payment_gateway')),
            'card_last4' => $this->digitsOrNull($request->input('card_last4'), 4),
        ];
    }

    /**
     * Reported line items. Rows without a description or with a non-positive price are
     * dropped — the issuer balances any resulting gap against the order total, so a
     * partial breakdown still yields a document that sums to the real money.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Request $request): array
    {
        $lines = [];

        foreach (array_slice((array) $request->input('lines', []), 0, self::MAX_LINES) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $description = $this->cleanString($line['description'] ?? null);
            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

            if ($description === null || $unitPrice <= 0) {
                continue;
            }

            $lines[] = [
                'description' => $description,
                'unit_price' => $unitPrice,
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'catalog_number' => $this->cleanString($line['catalog_number'] ?? null),
            ];
        }

        return $lines;
    }

    /** A 3-letter currency code, defaulting to the platform currency. */
    private function currency(Request $request, Shop $shop): string
    {
        $currency = strtoupper(trim((string) $request->input('currency', '')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1
            ? $currency
            : strtoupper((string) config('payplus.currency', 'ILS'));
    }

    /** Exactly $length digits, or null — never a partially-numeric card fragment. */
    private function digitsOrNull(mixed $value, int $length): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return strlen($digits) === $length ? $digits : null;
    }

    // === Existing-document lookup ===

    /**
     * POST /api/woocommerce/orders/documents — every ISSUED document for ONE store
     * order, so the plugin can show the merchant (and, behind attach_to_order, the
     * shopper) what paperwork exists for it.
     *
     * A READ, so deliberately NONE of issue()'s gates: no scope check and no
     * belongsToPlan wall, because a plan order's documents — issued through the
     * ledger pipeline with external_order_id set — are exactly what must be
     * returned too. Only `issued` rows: failed/unresolved are the SaaS admin's
     * work queue, never the store's.
     *
     * POST, not GET: the plugin's GET signer excludes the query string, so a GET
     * with parameters could not be signed by the existing signer.
     */
    public function documents(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $orderId = $this->cleanString($request->input('order_id'));
        if ($orderId === null) {
            return response()->json(['error' => 'invalid_order'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $shopId = (int) $shop->getKey();
        $platformKey = DocumentIssuer::keyForPlatformOrder($shopId, $orderId);

        // Tenant-bound read: the BelongsToShop global scope — not a hand-written
        // where — is what confines the query to this tenant.
        $documents = Tenant::run($shop, static fn () => IssuedDocument::query()
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->where(fn ($q) => $q
                ->where('idempotency_key', $platformKey)
                ->orWhere('external_order_id', $orderId))
            ->orderBy('issued_at')
            ->get());

        return response()->json([
            'ok' => true,
            'documents' => $documents
                ->map(fn (IssuedDocument $d): array => $this->documentPayload($d) + [
                    'type' => (string) ($d->document_type ?? ''),
                    'context' => (string) $d->context,
                    'amount' => (float) ($d->amount ?? 0),
                    'currency' => (string) $d->currency,
                    'issued_at' => $d->issued_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The already-ISSUED document for this order, read INSIDE the shop's tenant
     * context so the BelongsToShop global scope — not a hand-written where — is what
     * confines the query to this tenant.
     */
    private function existingDocument(Shop $shop, string $orderId): ?IssuedDocument
    {
        $key = DocumentIssuer::keyForPlatformOrder((int) $shop->getKey(), $orderId);

        return Tenant::run($shop, static fn (): ?IssuedDocument => IssuedDocument::query()
            ->where('idempotency_key', $key)
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->first());
    }

    /** @return array<string, mixed> */
    private function documentPayload(IssuedDocument $document): array
    {
        return [
            'id' => (string) $document->provider_document_id,
            'number' => (string) ($document->document_number ?? ''),
            'url' => (string) ($document->document_url ?? ''),
        ];
    }
}
