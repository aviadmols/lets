<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\ProductPriceResolver;
use App\Domain\Installments\RecurringPlanService;
use App\Domain\Products\ProductPlanTemplateResolver;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\PayPlus\PayPlusPageOptions;
use App\Support\Tenant;
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
    /** PayPlus charge_method config key + default (W17: 1 = immediate capture; 0 was verify-only). */
    private const CONFIG_CHARGE_METHOD = 'woocommerce.charge_method';
    private const CHARGE_METHOD_DEFAULT = 1;

    /** more_info prefix marking a plain-gateway order (vs. a LETS plan public id). */
    public const MORE_INFO_PREFIX = 'gw:';

    /** generateLink response keys (same as the deposit page). */
    private const RESP_PAGE_LINK = 'data.payment_page_link';
    /** Where the page-request id can appear (searched in order) — the plugin verifies-on-return with it. */
    private const RESP_PAGE_REQUEST_UID_PATHS = [
        'data.page_request_uid',
        'page_request_uid',
        'data.page_request.uid',
    ];

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

        // Cart-based subscriptions (W17 B): for each subscription line item the plugin sent, create
        // an awaiting_first_payment recurring plan per the merchant's TEMPLATE (cadence/discount
        // server-resolved, never client). The first cycle is paid on THIS gateway page (part of the
        // order total); WooGatewayFinalizer activates each plan when the order is marked paid.
        $subscriptionItems = (array) $request->input('subscription_items', []);
        $subscriptionPlanIds = $subscriptionItems !== []
            ? $this->createSubscriptionPlans($shop, $subscriptionItems, $request, $currency)
            : [];

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
                // 1 = immediate CHARGE/capture (W17). Config-driven + env-overridable; the SAME
                // value the deposit page uses, so the two pages can never disagree.
                'charge_method' => (int) config(self::CONFIG_CHARGE_METHOD, self::CHARGE_METHOD_DEFAULT),
                // more_info carries the WC order id (prefixed) — the callback marks it paid.
                'more_info' => self::MORE_INFO_PREFIX.$orderId,
                // Prefill the card page with the shopper's details from the WC order.
                'customer' => $this->customer($request),
                'refURL_success' => (string) $request->input('return_url', ''),
                'refURL_failure' => (string) $request->input('cancel_url', $request->input('return_url', '')),
                'refURL_cancel' => (string) $request->input('cancel_url', $request->input('return_url', '')),
                'refURL_callback' => route('woocommerce.gateway.callback', ['wc_shop_token' => (string) $shop->wc_shop_token]),
                // Ask PayPlus to call the callback on FAILURE too — otherwise a decline
                // produces no server-side signal at all (no log, no admin email). W16.
                'send_failure_callback' => true,
                // A subscription's first payment MUST vault a reusable token so the recurring
                // engine can bill future cycles — force it on regardless of the merchant setting.
                // Later key wins over the PayPlusPageOptions spread above.
                ...($subscriptionPlanIds !== [] ? ['create_token' => true] : []),
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

        // The plugin stores this + sends it back to /gateway/verify on the thank-you page, so the
        // order is confirmed even when PayPlus never pushes the callback. Search the known shapes;
        // an empty uid SILENTLY disables verify-on-return, so surface it in the log.
        $pageRequestUid = '';
        foreach (self::RESP_PAGE_REQUEST_UID_PATHS as $path) {
            $value = (string) (data_get($result->raw, $path) ?? '');
            if ($value !== '') {
                $pageRequestUid = $value;
                break;
            }
        }
        if ($pageRequestUid === '') {
            Log::warning('woocommerce.gateway.no_page_request_uid', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'top_level_keys' => is_array($result->raw) ? array_keys($result->raw) : [],
            ]);
        }

        return response()->json([
            'redirect_url' => $pageLink,
            'page_request_uid' => $pageRequestUid,
            // The recurring plans this order's subscription items created — the plugin stores them
            // as order meta so WooGatewayFinalizer activates them when the order is marked paid.
            'subscription_plan_ids' => $subscriptionPlanIds,
        ], Response::HTTP_OK);
    }

    /**
     * Create an awaiting_first_payment recurring plan per subscription line item, per the merchant's
     * TEMPLATE. Money-safe: the per-cycle amount is the server catalog price × the template discount
     * × quantity (the client sends only product/variant ids + quantity). Tenant-safe: everything
     * runs under Tenant::run($shop). Fail-closed: an item whose template/price vanished is skipped +
     * logged, never guessed.
     *
     * @param  array<int, mixed>  $items
     * @return list<string>  the created plans' public ids
     */
    private function createSubscriptionPlans(Shop $shop, array $items, Request $request, string $currency): array
    {
        return Tenant::run($shop, function () use ($shop, $items, $request, $currency): array {
            $templates = app(ProductPlanTemplateResolver::class);
            $prices = app(ProductPriceResolver::class);
            $recurring = app(RecurringPlanService::class);

            $name = trim(((string) $this->cleanString($request->input('first_name'))).' '.((string) $this->cleanString($request->input('last_name'))));
            $externalCustomerId = (string) ($request->input('customer_id') ?? '');

            $ids = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $productId = (string) ($item['product_id'] ?? '');
                $variantId = (string) ($item['variant_id'] ?? '');
                $variantId = $variantId !== '' ? $variantId : $productId;
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                if ($productId === '') {
                    continue;
                }

                $template = $templates->resolveDefaultsFor($shop, $productId, $variantId);
                if ($template === null) {
                    Log::warning('woocommerce.gateway.subscription_no_template', ['shop_id' => $shop->getKey(), 'product_id' => $productId]);

                    continue;
                }

                $resolved = $prices->resolve($productId, $variantId);
                if ($resolved === null) {
                    Log::warning('woocommerce.gateway.subscription_no_price', ['shop_id' => $shop->getKey(), 'variant_id' => $variantId]);

                    continue;
                }

                $unitPrice = round((float) $resolved['variant']->price, 2);
                $perCycle = round($template->discountedPrice($unitPrice) * $quantity, 2);
                if ($perCycle <= 0) {
                    continue;
                }

                $plan = $recurring->createAwaitingExternalPayment($shop, [
                    'product_gid' => $productId,
                    'variant_gid' => $variantId,
                    'item_title' => $resolved['title'],
                    'amount' => $perCycle,
                    'frequency' => $template->billing_frequency ?? BillingFrequency::MONTHLY,
                    'interval_count' => max(1, (int) $template->interval_count),
                    'currency' => $currency,
                    'customer_email' => $this->cleanEmail($request->input('email')),
                    'customer_name' => $name !== '' ? $name : null,
                    'customer_phone' => $this->cleanString($request->input('phone')),
                    'external_customer_id' => $externalCustomerId !== '' ? $externalCustomerId : null,
                ]);

                $ids[] = (string) $plan->public_id;
            }

            return $ids;
        });
    }

    /**
     * The PayPlus `customer` object, built from the WC order fields the plugin sends
     * (first/last name, email, phone). Each value is sanitised; empty subkeys are dropped so
     * PayPlus doesn't receive blank fields. Returns [] when the plugin sent nothing — an old
     * plugin (pre-W16) simply gets no customer object, exactly as before.
     *
     * @return array<string, string>
     */
    private function customer(Request $request): array
    {
        $name = trim(((string) $this->cleanString($request->input('first_name'))).' '.((string) $this->cleanString($request->input('last_name'))));

        return array_filter([
            'customer_name' => $name,
            'email' => (string) $this->cleanEmail($request->input('email')),
            'phone' => (string) $this->cleanString($request->input('phone')),
        ], static fn (string $v): bool => $v !== '');
    }
}
