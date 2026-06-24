<?php

namespace App\Services\Shopify\Orders;

use App\Models\InstallmentPlan;
use App\Services\Orders\PlatformInvoiceService;

/**
 * Shopify implementation of PlatformInvoiceService: the deposit "invoice" is an
 * UNPAID Shopify draft order (created via ShopifyDraftOrderService). Maps the
 * Shopify-shaped return ({draft_order_id, draft_order_gid, invoice_url, name}) onto
 * the platform-neutral linkage the engine stores. Behaviour is byte-identical to the
 * pre-seam DepositPlanService → ShopifyDraftOrderService call.
 */
final class ShopifyDepositInvoiceAdapter implements PlatformInvoiceService
{
    public function __construct(private readonly ShopifyDraftOrderService $draftOrders) {}

    public function createDepositInvoice(InstallmentPlan $plan, array $lineItem): array
    {
        $invoice = $this->draftOrders->createDepositInvoice($plan, $lineItem);

        return [
            'external_ref' => (string) ($invoice['draft_order_id'] ?? ''),
            'external_gid' => ($invoice['draft_order_gid'] ?? null) ?: null,
            'invoice_url' => (string) ($invoice['invoice_url'] ?? ''),
            'name' => ($invoice['name'] ?? null) ?: null,
        ];
    }
}
