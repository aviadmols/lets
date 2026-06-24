<?php

namespace App\Services\Orders;

use App\Models\InstallmentPlan;

/**
 * Platform-neutral deposit-invoice contract. Creates the UNPAID deposit "invoice"
 * the customer pays to activate a deposit + installments plan, and returns a neutral
 * linkage the engine stores on the plan (so PlanActivationService can find the plan
 * when the deposit is paid).
 *
 * One implementation per platform, resolved by PlatformInvoiceServiceFactory:
 *   - Shopify     → ShopifyDepositInvoiceAdapter (an unpaid Shopify draft order)
 *   - WooCommerce → WooCommerceDepositInvoiceService (the PayPlus hosted page, W11 P2)
 *
 * The returned shape is intentionally platform-agnostic:
 *   external_ref  — the id the paid-order webhook will reference (draft id / order id)
 *   external_gid  — the platform global id, when one exists (Shopify GID); else null
 *   invoice_url   — the hosted page the storefront redirects the customer to
 *   name          — a human label for the invoice/order, when available; else null
 */
interface PlatformInvoiceService
{
    /**
     * @param  array{title:string, deposit_amount:float, quantity:int, variant_gid:string}  $lineItem
     * @return array{external_ref:string, external_gid:?string, invoice_url:string, name:?string}
     */
    public function createDepositInvoice(InstallmentPlan $plan, array $lineItem): array;
}
