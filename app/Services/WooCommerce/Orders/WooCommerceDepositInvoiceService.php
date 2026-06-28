<?php

namespace App\Services\WooCommerce\Orders;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\Orders\PlatformInvoiceService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WooCommerce implementation of PlatformInvoiceService (W11 P2). Where Shopify's
 * deposit "invoice" is an unpaid draft order, WooCommerce's is the PayPlus HOSTED
 * payment page ("דף סליקה"): we ask PayPlusGatewayFactory::for($shop)->generateLink()
 * for a page that collects the DEPOSIT amount and vaults the card token, then return
 * the page URL the storefront redirects the shopper to. The deposit is NOT charged
 * here (no ledger row) — the shopper pays on the PayPlus page; on completion PayPlus
 * calls our callback, which runs PlanActivationService to RECORD the paid deposit +
 * capture the token (money law: ledger-before-charge is preserved because the page
 * collects it directly and activation only records what already moved).
 *
 * The neutral linkage we return maps generateLink's response onto the engine's shape:
 *   external_ref  → the plan public_id (the callback echoes it back as `more_info`, and
 *                   WooCommercePaidOrderPlanResolver finds the plan by it)
 *   external_gid  → PayPlus' page_request_uid (the hosted page identity; null when absent)
 *   invoice_url   → data.payment_page_link (the hosted page URL to redirect to)
 *   name          → the human label (the item title)
 *
 * Tenant law: the gateway is built PER-SHOP from the plan's shop's decrypted PayPlus
 * credentials — never a shared instance. We resolve the shop from the plan (the plan was
 * just stamped with the verified tenant in DepositPlanService) and never from global state.
 */
final class WooCommerceDepositInvoiceService implements PlatformInvoiceService
{
    // === CONSTANTS ===
    /**
     * PayPlus generateLink charge_method config key + DEFAULT.
     *
     * 0 = immediate capture/charge per current PayPlus understanding (the deposit is
     * captured now and the card token vaulted) — the unchanged default. VERIFY against
     * the real PayPlus terminal: if 0 turns out to be authorize-only on this account,
     * the owner sets WOOCOMMERCE_CHARGE_METHOD to the value the terminal uses for an
     * immediate charge. It is config-driven (not a hardcoded literal) precisely so that
     * adjustment is an env flip, not a code change. @see config/woocommerce.php
     */
    private const CONFIG_CHARGE_METHOD = 'woocommerce.charge_method';
    private const CHARGE_METHOD_DEFAULT = 0;

    /** generateLink response keys (confirmed against the reference PayPlusInstallmentGateway:55-56). */
    private const RESP_PAGE_LINK = 'data.payment_page_link';
    private const RESP_PAGE_REQUEST_UID = 'data.page_request_uid';

    public function createDepositInvoice(InstallmentPlan $plan, array $lineItem): array
    {
        $shop = $plan->shop;
        if (! $shop instanceof Shop) {
            throw new RuntimeException('Deposit plan has no resolvable shop for the PayPlus page.');
        }

        $publicId = (string) $plan->public_id;
        $amount = round((float) ($lineItem['deposit_amount'] ?? 0), 2);
        $productName = (string) ($lineItem['title'] ?? '');

        if ($amount <= 0) {
            throw new RuntimeException("Refusing to create a PayPlus page for a non-positive deposit on plan {$publicId}.");
        }

        // Per-shop gateway from the plan's shop's decrypted PayPlus creds. generateLink
        // merges payment_page_uid/terminal_uid/currency_code; we pass the money + the
        // return/callback URLs + more_info (the plan public_id echoed on the callback).
        $result = PayPlusGatewayFactory::for($shop)->generateLink([
            'amount' => $amount,
            'product_name' => $productName !== '' ? $productName : __('storefront.installments.default_item'),
            // 0 = immediate capture/charge per current PayPlus understanding; verify
            // against the terminal, adjust via WOOCOMMERCE_CHARGE_METHOD if 0 turns out
            // to be authorize-only. No behaviour change by default.
            'charge_method' => (int) config(self::CONFIG_CHARGE_METHOD, self::CHARGE_METHOD_DEFAULT),
            // more_info is the correlation marker PayPlus echoes back on the callback;
            // WooCommercePaidOrderPlanResolver finds the plan by it (= public_id).
            'more_info' => $publicId,
            'refURL_success' => $this->returnUrl($shop, $publicId, 'success'),
            'refURL_failure' => $this->returnUrl($shop, $publicId, 'failure'),
            'refURL_cancel' => $this->returnUrl($shop, $publicId, 'cancel'),
            'refURL_callback' => $this->callbackUrl($shop),
        ]);

        if (! $result->success) {
            Log::warning('woocommerce.deposit.generate_link_failed', [
                'shop_id' => $shop->getKey(),
                'plan_public_id' => $publicId,
                'error_code' => $result->errorCode,
            ]);

            throw new RuntimeException(
                "PayPlus generateLink failed for plan {$publicId}: ".(string) ($result->errorMessage ?? 'unknown'),
            );
        }

        $pageLink = (string) (data_get($result->raw, self::RESP_PAGE_LINK) ?? '');
        $pageRequestUid = data_get($result->raw, self::RESP_PAGE_REQUEST_UID);

        if ($pageLink === '') {
            throw new RuntimeException("PayPlus returned no payment_page_link for plan {$publicId}.");
        }

        return [
            // The callback echoes more_info (= public_id); the resolver finds the plan by it.
            'external_ref' => $publicId,
            'external_gid' => ($pageRequestUid !== null && $pageRequestUid !== '') ? (string) $pageRequestUid : null,
            'invoice_url' => $pageLink,
            'name' => $productName !== '' ? $productName : null,
        ];
    }

    /**
     * The shopper-facing return URL PayPlus redirects the browser to after the page
     * (carries the plan + the status; a thin "thank you / try again" landing page).
     */
    private function returnUrl(Shop $shop, string $publicId, string $status): string
    {
        return route('woocommerce.deposit.return', [
            'wc_shop_token' => (string) $shop->wc_shop_token,
            'plan' => $publicId,
            'status' => $status,
        ]);
    }

    /**
     * The server-to-server callback URL PayPlus POSTs to on completion. It carries the
     * shop's opaque wc_shop_token so the SaaS resolves the shop BEFORE trusting anything
     * in the body (the same pattern as the WC webhook delivery URL).
     */
    private function callbackUrl(Shop $shop): string
    {
        return route('woocommerce.deposit.callback', [
            'wc_shop_token' => (string) $shop->wc_shop_token,
        ]);
    }
}
