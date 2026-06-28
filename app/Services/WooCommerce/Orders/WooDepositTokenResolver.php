<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Installments\Contracts\DepositTokenResolver;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * WooCommerce implementation of DepositTokenResolver (defensive follow-up).
 *
 * Where Shopify must WALK the order's transaction receipts (and a 4-strategy
 * /Transactions/View + /PaymentPages/ipn chain) to find the saved card token, the
 * WooCommerce deposit is paid DIRECTLY on the PayPlus hosted page — so PayPlus POSTs
 * the token + card metadata straight to our deposit callback. This resolver simply
 * EXTRACTS those fields from the callback body and hands them to PlanActivationService,
 * which vaults them as the plan's InstallmentPaymentMethod so the recurring/installment
 * engine can charge later cycles one-click on the saved token.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PAYPLUS FIELD ASSUMPTIONS — OWNER MUST CONFIRM AGAINST A REAL CALLBACK
 * ─────────────────────────────────────────────────────────────────────────────
 * The reusable-token signal we treat as authoritative is, in priority order:
 *
 *   1. token_uid       → the saved card-token UID (the reference engine's
 *                        `token_uid`, stored as InstallmentPaymentMethod
 *                        ->payplus_card_token_uid; this is what chargeWithReference
 *                        sends with use_token=true). **This is THE field we assume
 *                        is the reusable token.**
 *   2. customer_uid    → the PayPlus customer reference; vaulted as
 *                        payplus_customer_uid. The gateway can charge on customer_uid
 *                        alone when token_uid is absent, so we vault it too.
 *
 * Card metadata (display only, never used to charge): four_digits → card_last_four,
 * brand_name → card_brand, expiry_month/expiry_year → exp_month/exp_year.
 *
 * These names come from the reference PayPlusCustomerTokenResolver (the proven
 * Shopify path) — they are the PayPlus API's own field names. We have NOT yet seen a
 * real PayPlus WC deposit callback, so the owner must confirm:
 *   - that the reusable token does arrive as `token_uid` (not e.g. `card_token`,
 *     `token`, or only via a follow-up /PaymentPages/ipn lookup), and
 *   - whether it sits at the body root, under `transaction`, or under `data`.
 * We search ALL THREE shapes defensively; if PayPlus uses a different key, add it to
 * TOKEN_PATHS below — no other change is needed.
 *
 * DEFENSIVE NO-OP: when the callback carries no recognizable token AND no customer
 * reference, resolveFromOrder returns null. PlanActivationService then activates the
 * plan WITHOUT a payment method (the deposit is still recorded, the schedule still
 * advances); only the later auto-charges need the token, and the engine's own
 * consent/payment-method checks already gate those. We log the miss so the owner can
 * inspect a real callback and confirm the field name.
 */
final class WooDepositTokenResolver implements DepositTokenResolver
{
    // === CONSTANTS ===

    /**
     * The deposit callback (WooDepositCallbackController) wraps the raw PayPlus body
     * under this key; we also search the payload root so the resolver works whether
     * it is handed the wrapped activation payload or a raw PayPlus body.
     */
    private const WRAP_KEY = 'payplus';

    /**
     * Candidate dot-paths (relative to each searched base) for the reusable card-token
     * UID. ASSUMED `token_uid` per the reference engine; the alternates are field names
     * PayPlus has used in the wild for the same value. First non-empty wins.
     */
    private const TOKEN_PATHS = [
        'token_uid',
        'transaction.token_uid',
        'data.token_uid',
        'card_token',
        'transaction.card_token',
    ];

    /** Candidate paths for the PayPlus customer reference (charge can use this alone). */
    private const CUSTOMER_PATHS = [
        'customer_uid',
        'transaction.customer_uid',
        'data.customer_uid',
    ];

    /** Candidate paths for the card last-four digits (display only). */
    private const LAST_FOUR_PATHS = [
        'four_digits',
        'transaction.four_digits',
        'data.four_digits',
        'last_four',
    ];

    /** Candidate paths for the card brand (display only). */
    private const BRAND_PATHS = [
        'brand_name',
        'transaction.brand_name',
        'data.brand_name',
        'card_brand',
    ];

    /** Candidate paths for the card expiry month (display only). */
    private const EXP_MONTH_PATHS = [
        'expiry_month',
        'transaction.expiry_month',
        'data.expiry_month',
    ];

    /** Candidate paths for the card expiry year (display only). */
    private const EXP_YEAR_PATHS = [
        'expiry_year',
        'transaction.expiry_year',
        'data.expiry_year',
    ];

    /**
     * @param  array<string, mixed>  $orderPayload  the WC deposit-callback activation
     *                                               payload (raw PayPlus body under
     *                                               `payplus`, or a raw PayPlus body)
     * @return array{
     *     payplus_card_token_uid?: ?string,
     *     payplus_customer_uid?: ?string,
     *     payplus_token_reference?: ?string,
     *     card_brand?: ?string,
     *     card_last_four?: ?string,
     *     exp_month?: ?int,
     *     exp_year?: ?int
     * }|null
     */
    public function resolveFromOrder(Shop $shop, array $orderPayload): ?array
    {
        // Search the wrapped PayPlus body first (the callback's normal shape), then the
        // payload root (so a raw PayPlus body also resolves). The first base that yields
        // a token OR a customer reference wins.
        $bases = [];
        $wrapped = data_get($orderPayload, self::WRAP_KEY);
        if (is_array($wrapped)) {
            $bases[] = $wrapped;
        }
        $bases[] = $orderPayload;

        foreach ($bases as $base) {
            $tokenUid = $this->firstString($base, self::TOKEN_PATHS);
            $customerUid = $this->firstString($base, self::CUSTOMER_PATHS);

            // Nothing reusable here → try the next base.
            if ($tokenUid === '' && $customerUid === '') {
                continue;
            }

            return [
                'payplus_card_token_uid' => $tokenUid !== '' ? $tokenUid : null,
                'payplus_customer_uid' => $customerUid !== '' ? $customerUid : null,
                'payplus_token_reference' => null,
                'card_brand' => $this->firstString($base, self::BRAND_PATHS) ?: null,
                'card_last_four' => $this->firstString($base, self::LAST_FOUR_PATHS) ?: null,
                'exp_month' => $this->firstInt($base, self::EXP_MONTH_PATHS),
                'exp_year' => $this->firstInt($base, self::EXP_YEAR_PATHS),
            ];
        }

        // Defensive no-op: the plan still activates (deposit recorded, schedule advanced);
        // only auto-charging needs the token, and the engine gates that on its own
        // consent/payment-method checks. Log so the owner can inspect a real callback.
        Log::info('woocommerce.deposit.no_reusable_token_in_callback', [
            'shop_id' => $shop->getKey(),
            // The PayPlus top-level keys we DID see, to help the owner spot the real
            // token field name without logging any secret value.
            'observed_keys' => array_keys((array) (is_array($wrapped) ? $wrapped : $orderPayload)),
        ]);

        return null;
    }

    /**
     * First non-empty string value among the candidate paths, '' when none.
     *
     * @param  array<string, mixed>  $base
     * @param  array<int, string>  $paths
     */
    private function firstString(array $base, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($base, $path);
            // A non-empty string, or a scalar numeric (PayPlus sometimes sends
            // four_digits as an int) — coerced to string. Never an array/object.
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * First positive int value among the candidate paths, null when none.
     *
     * @param  array<string, mixed>  $base
     * @param  array<int, string>  $paths
     */
    private function firstInt(array $base, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($base, $path);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
