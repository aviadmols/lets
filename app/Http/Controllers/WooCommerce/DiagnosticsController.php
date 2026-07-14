<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\PayPlus\PayPlusPageOptions;
use App\Services\WooCommerce\WooConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Merchant-facing diagnostics for the WordPress plugin's Settings → LETS screen (its
 * "Test connection" and "Test payment page" buttons). Reaching this controller AT ALL
 * already proves the plugin↔LETS HMAC auth works — VerifyWooCommerceSignature bound the
 * shop — so the report only has to answer "what's missing DOWNSTREAM of that".
 *
 * Money law: the payment-page probe asks PayPlus for a real hosted page but writes NO
 * WooCommerce order and NO payment_ledger row. It is a probe, not a charge: the page is
 * simply never paid, we register NO callback URL, and its more_info carries a `test:`
 * prefix — WooGatewayCallbackController only ever acts on the `gw:` prefix, so even a
 * stray payment on a probe page can mark nothing paid.
 *
 * Never returns a secret: booleans about what is configured, PayPlus's own error text,
 * and the page URL — nothing else.
 */
final class DiagnosticsController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** more_info prefix marking a PROBE page. The gateway callback must never act on it. */
    public const MORE_INFO_PREFIX = 'test:';

    /** Nominal amount for the probe page (it is never paid). */
    private const PROBE_AMOUNT = 1.0;

    /** generateLink response key holding the hosted-page URL (same as the gateway). */
    private const RESP_PAGE_LINK = 'data.payment_page_link';

    /** POST /api/woocommerce/diagnostics — the ✅/❌ report the plugin renders. */
    public function report(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'ok' => true,
            'lets' => [
                'shop' => $shop->displayDomain(),
                'plan' => $shop->plan,
            ],
            // Reuse the same tester the platform admin's "Test connection" button uses.
            'woocommerce' => app(WooConnectionTester::class)->test($shop),
            'payplus' => $this->payplusReport($shop),
        ]);
    }

    /**
     * POST /api/woocommerce/diagnostics/payment-page — ask PayPlus for a REAL hosted page
     * and hand back the URL, or PayPlus's verbatim rejection. This is the merchant's proof
     * that checkout can actually reach the credit-card form.
     */
    public function paymentPage(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payplus = $this->payplusReport($shop);

        // Don't even call PayPlus when we already know it cannot succeed — return the
        // precise missing piece (this is the state that broke checkout for shop 2).
        if (! $payplus['ready']) {
            return response()->json([
                'ok' => false,
                'error' => $payplus['reason'],
                'payplus' => $payplus,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = PayPlusGatewayFactory::for($shop)->generateLink([
                // Probe the page the SHOPPER would actually see — same merchant options.
                ...app(PayPlusPageOptions::class)->for($shop),
                'amount' => self::PROBE_AMOUNT,
                'product_name' => __('storefront.installments.probe_item'),
                'charge_method' => 0,
                // Never vault a card from a probe, whatever the merchant's setting says.
                'create_token' => false,
                // No refURL_callback on purpose — a probe page must be able to mark nothing.
                'more_info' => self::MORE_INFO_PREFIX.$shop->getKey(),
            ]);
        } catch (Throwable $e) {
            Log::warning('woocommerce.diagnostics.payment_page_error', [
                'shop_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'payplus' => $payplus,
            ], Response::HTTP_BAD_GATEWAY);
        }

        $link = (string) (data_get($result->raw, self::RESP_PAGE_LINK) ?? '');

        if (! $result->success || $link === '') {
            // PayPlus's OWN words — the entire point of this tool.
            $reason = (string) (data_get($result->raw, 'results.description')
                ?? data_get($result->raw, 'results.message')
                ?? 'PayPlus returned no payment page.');

            Log::warning('woocommerce.diagnostics.no_payment_page', [
                'shop_id' => $shop->getKey(),
                'payplus_status' => data_get($result->raw, 'results.status'),
                'payplus_code' => data_get($result->raw, 'results.code'),
                'payplus_message' => $reason,
            ]);

            return response()->json([
                'ok' => false,
                'error' => $reason,
                'payplus_status' => data_get($result->raw, 'results.status'),
                'payplus' => $payplus,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'ok' => true,
            'payment_page_url' => $link,
            'payplus' => $payplus,
        ]);
    }

    /**
     * What this shop has configured (BOOLEANS only — never the values) and whether PayPlus
     * can actually mint a card page, with a machine-readable reason when it cannot. The
     * plugin maps these reasons to clear shopper/merchant text.
     *
     * @return array{ready:bool,reason:?string,has_api_key:bool,has_secret_key:bool,has_terminal_uid:bool,has_payment_page_uid:bool,has_cashier_uid:bool}
     */
    private function payplusReport(Shop $shop): array
    {
        $cfg = $shop->payplusConfig();

        $has = [
            'has_api_key' => ! empty($cfg['api_key']),
            'has_secret_key' => ! empty($cfg['secret_key']),
            'has_terminal_uid' => ! empty($cfg['terminal_uid']),
            'has_payment_page_uid' => ! empty($cfg['payment_page_uid']),
            'has_cashier_uid' => ! empty($cfg['cashier_uid']),
        ];

        // payment_page_uid is REQUIRED: PayPlus cannot create a card page without it.
        $reason = match (true) {
            ! $has['has_api_key'], ! $has['has_secret_key'] => 'payplus_not_connected',
            ! $has['has_terminal_uid'] => 'payplus_no_terminal',
            ! $has['has_payment_page_uid'] => 'payplus_no_payment_page',
            default => null,
        };

        return ['ready' => $reason === null, 'reason' => $reason] + $has;
    }
}
