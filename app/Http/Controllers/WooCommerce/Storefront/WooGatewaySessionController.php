<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\PayPlus\PayPlusPageOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/gateway/session  (the full PayPlus gateway, "mode B")
 *
 * The plugin's WC_Payment_Gateway::process_payment() calls this (server-signed HMAC) to
 * pay a NORMAL WooCommerce order through PayPlus. We ask the per-shop gateway for a hosted
 * page for the order TOTAL and return the page URL the gateway redirects the shopper to.
 * On payment, PayPlus calls /woocommerce/gateway/callback/{wc_shop_token}, which marks the
 * WC order paid. Coexists with the deposit/subscribe widgets.
 *
 * Money law: no ledger row here — a plain checkout's money is recorded by WooCommerce when
 * the callback marks the order paid (this gateway is "normal checkout via PayPlus", not a
 * LETS plan). The amount is the order total the plugin reports; the SaaS does not re-price
 * a cart it doesn't own (it is WooCommerce's order). Tenant law: the shop is the
 * HMAC-verified shop; the per-shop gateway is built from its decrypted PayPlus creds.
 */
final class WooGatewaySessionController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** PayPlus charge_method 0 = charge (capture now). */
    private const CHARGE_METHOD_CHARGE = 0;

    /** more_info prefix marking a plain-gateway order (vs. a LETS plan public id). */
    public const MORE_INFO_PREFIX = 'gw:';

    /** generateLink response keys (same as the deposit page). */
    private const RESP_PAGE_LINK = 'data.payment_page_link';

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $orderId = (string) $request->input('order_id', '');
        $amount = round((float) $request->input('amount', 0), 2);
        if ($orderId === '' || $amount <= 0) {
            return response()->json(['error' => 'invalid_order'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // PayPlus must be connected on this shop to mint a hosted page. Without decryptable
        // credentials (api_key/secret), fail CLEAN here — not as an "Undefined array key"
        // 500 deep in the gateway — so the plugin can tell the shopper the store's PayPlus
        // connection needs attention (re-enter the keys in Settings → PayPlus Connection).
        if (! $shop->hasPayplusConnection()) {
            Log::warning('woocommerce.gateway.payplus_not_connected', ['shop_id' => $shop->getKey(), 'order_id' => $orderId]);

            return response()->json(['error' => 'payplus_not_connected'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // PayPlus can't mint a hosted card page without a payment_page_uid. Catch the
        // common "terminal selected but no payment page chosen" case up front and return
        // a SPECIFIC reason, so the plugin can tell the merchant to finish the connection.
        if (empty($shop->payplusConfig()['payment_page_uid'])) {
            Log::warning('woocommerce.gateway.no_payment_page_uid', ['shop_id' => $shop->getKey(), 'order_id' => $orderId]);

            return response()->json(['error' => 'payplus_no_payment_page'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $currency = (string) ($request->input('currency') ?: config('payplus.currency', 'ILS'));

        try {
            $result = PayPlusGatewayFactory::for($shop)->generateLink([
                // The merchant's page options (language, installments, Bit/PayPal, receipts,
                // create_token…). Spread FIRST so the money + correlation keys below always
                // win; PayPlusPageOptions can only emit documented, allow-listed keys, and
                // PayPlusGateway forces payment_page_uid/terminal_uid to this shop's own creds.
                ...app(PayPlusPageOptions::class)->for($shop),
                'amount' => $amount,
                'currency_code' => $currency,
                'product_name' => (string) ($request->input('product_name') ?: __('storefront.installments.default_item')),
                'charge_method' => self::CHARGE_METHOD_CHARGE,
                // more_info carries the WC order id (prefixed) — the callback marks it paid.
                'more_info' => self::MORE_INFO_PREFIX.$orderId,
                'refURL_success' => (string) $request->input('return_url', ''),
                'refURL_failure' => (string) $request->input('cancel_url', $request->input('return_url', '')),
                'refURL_cancel' => (string) $request->input('cancel_url', $request->input('return_url', '')),
                'refURL_callback' => route('woocommerce.gateway.callback', ['wc_shop_token' => (string) $shop->wc_shop_token]),
            ]);
        } catch (\Throwable $e) {
            Log::error('woocommerce.gateway.session_failed', ['shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'gateway_unavailable'], Response::HTTP_BAD_GATEWAY);
        }

        $pageLink = (string) (data_get($result->raw, self::RESP_PAGE_LINK) ?? '');
        if (! $result->success || $pageLink === '') {
            // PayPlus accepted the request but returned no hosted-page link. Surface WHY
            // (PayPlus's own status/description) + whether the terminal/payment-page UIDs
            // were discovered — the usual cause is an incomplete PayPlus connection
            // (api_key/secret entered, but no payment_page_uid). No secrets are logged.
            $cfg = $shop->payplusConfig();
            Log::warning('woocommerce.gateway.no_payment_page', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'payplus_status' => data_get($result->raw, 'results.status'),
                'payplus_code' => data_get($result->raw, 'results.code'),
                'payplus_message' => data_get($result->raw, 'results.description')
                    ?? data_get($result->raw, 'results.message'),
                'has_terminal_uid' => ! empty($cfg['terminal_uid']),
                'has_payment_page_uid' => ! empty($cfg['payment_page_uid']),
                'has_cashier_uid' => ! empty($cfg['cashier_uid']),
            ]);

            return response()->json(['error' => 'gateway_unavailable'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['redirect_url' => $pageLink], Response::HTTP_OK);
    }
}
