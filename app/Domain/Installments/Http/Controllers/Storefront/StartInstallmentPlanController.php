<?php

namespace App\Domain\Installments\Http\Controllers\Storefront;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\InstallmentQuote;
use App\Domain\Installments\ProductPriceResolver;
use App\Models\MerchantBillingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /proxy/installments/start
 *
 * Creates the DEPOSIT + installments plan (status awaiting_first_payment, tenant
 * stamped) and the UNPAID Shopify deposit invoice, then returns the hosted invoice
 * URL the storefront redirects the PARENT window to (the iframe posts a
 * `lets:redirect` message up; see the snippet/app block).
 *
 * Money law (re-stated at the commit point): the price is resolved SERVER-SIDE from
 * the catalog cache and the quote is RECOMPUTED here from clamped knobs — we never
 * trust the preview the client last saw. Tenant law: $shop is the App-Proxy-verified
 * shop only; DepositPlanService stamps shop_id from it.
 *
 * No charge happens here — the deposit is paid by the customer on the returned
 * invoice page, and orders/paid then activates the plan (PlanActivationService).
 */
final class StartInstallmentPlanController extends ProxyInstallmentController
{
    public function __construct(
        private readonly ProductPriceResolver $prices,
        private readonly DepositPlanService $plans,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $productGid = (string) $request->input('product_gid', '');
        $variantGid = (string) $request->input('variant_gid', '');

        $resolved = $this->prices->resolve($productGid, $variantGid);
        if ($resolved === null) {
            return response()->json(['error' => 'variant_not_priceable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $price = round((float) $resolved['variant']->price, 2);

        // RECOMPUTE the quote server-side from clamped knobs — the preview the client
        // saw is advisory; this is the authoritative money truth that gets charged.
        // The merchant's billing bounds (deposit floor, installment ceiling, allowed
        // frequencies) are enforced HERE, at the commit point, so a tampered request
        // can never start a plan outside this shop's policy.
        $quote = InstallmentQuote::build(
            totalAmount: $price,
            depositPercent: (int) $request->input('deposit_percent', InstallmentQuote::DEFAULT_DEPOSIT_PERCENT),
            installments: (int) $request->input('installments', InstallmentQuote::DEFAULT_INSTALLMENTS),
            frequency: DepositPlanService::frequencyFrom($request->input('frequency')),
            paymentDay: (int) $request->input('payment_day', InstallmentQuote::DEFAULT_PAYMENT_DAY),
            currency: (string) ($request->input('currency') ?: config('payplus.currency', 'ILS')),
            bounds: MerchantBillingSettings::current(),
        );

        try {
            $result = $this->plans->create($shop, $quote, [
                'product_gid' => $productGid,
                'variant_gid' => $variantGid,
                'item_title' => $resolved['title'],
                'customer_email' => $this->cleanEmail($request->input('customer_email')),
                'customer_name' => $this->cleanString($request->input('customer_name')),
                'customer_phone' => $this->cleanString($request->input('customer_phone')),
                // The App Proxy injects logged_in_customer_id when the shopper is
                // authenticated — a TRUSTED value (it's inside the signed param set).
                'shopify_customer_id' => $this->cleanString($request->query('logged_in_customer_id')),
            ]);
        } catch (\Throwable $e) {
            Log::error('installments.start_failed', [
                'shop_id' => $shop->getKey(),
                'variant_gid' => $variantGid,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'plan_creation_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'plan_public_id' => $result['plan']->public_id,
            'invoice_url' => $result['invoice_url'],
            'deposit_amount' => $quote->depositAmount,
            'currency' => $quote->currency,
        ], Response::HTTP_CREATED);
    }

    private function cleanEmail(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
