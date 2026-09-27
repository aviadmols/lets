<?php

namespace App\Services\PayPlus;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusPageStatus;
use App\Services\WooCommerce\Orders\WooDepositTokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE one gate every PayPlus → LETS payment callback passes before it may move
 * anything: mark a WooCommerce order paid, activate a plan, attach a card.
 *
 * WHY. A callback is an unauthenticated POST. The per-shop token in its URL
 * resolves the shop, but it is not a secret worth the name — it rode in the
 * shopper-facing return URLs for months, so any paying shopper could read it.
 * A body that says "status 000" is therefore a CLAIM, never a payment.
 *
 * TWO WALLS, both applied:
 *
 *   1. SIGNATURE. PayPlus signs callbacks as base64(HMAC-SHA256(raw body,
 *      the shop's secret_key)) in the `hash` header. A present-but-wrong hash
 *      is refused (401) always; an absent one is refused only when
 *      config('woocommerce.require_callback_signature') is on, because not
 *      every terminal has been seen to sign — a shop whose callbacks arrive
 *      unsigned must keep receiving them.
 *
 *   2. SERVER-TO-SERVER CONFIRMATION — the wall that does not depend on
 *      whether PayPlus signed. We ask PayPlus's own IPN for the page
 *      (PayPlusPageStatus, the verify-on-return pull) and act only when PayPlus
 *      holds an APPROVED transaction whose more_info is the marker WE put on
 *      the page (gw:{order}, the plan public_id, cardupd:{plan}:{link}), and —
 *      where the caller knows what is owed — for at least that amount in that
 *      currency. A forged body names a page that does not exist, or a page for
 *      something else, or a cheaper page; all three stop here.
 *
 * WHAT THE CALLER ACTS ON is PayPlus's record of the page, not the callback.
 * A validly SIGNED callback is PayPlus's own words too, so its fields fill the
 * gaps the IPN leaves (the IPN wins on every key both carry). An UNSIGNED
 * callback contributes one thing only, and only under a cross-check: a card
 * token the IPN did not return, when the callback's last four digits match the
 * card PayPlus itself reports for the page. Every other unsigned field is
 * ignored.
 *
 * Tenant law: the shop is the one the URL token resolved; every IPN call uses
 * that shop's own decrypted credentials (PayPlusPageStatus::for).
 */
final class PayPlusCallbackVerifier
{
    // === CONSTANTS ===
    /** The header PayPlus signs the raw callback body in. */
    public const HASH_HEADER = 'hash';

    /** true → an unsigned callback is refused 401. @see config/woocommerce.php */
    public const CONFIG_REQUIRE_SIGNATURE = 'woocommerce.require_callback_signature';

    public const SIGNATURE_VALID = 'valid';

    public const SIGNATURE_ABSENT = 'absent';

    public const SIGNATURE_INVALID = 'invalid';

    /** A hash was sent but the shop has no secret_key to check it with. */
    public const SIGNATURE_UNVERIFIABLE = 'unverifiable';

    /**
     * Paying LESS than owed by more than this is a mismatch. A cent of slack for
     * float round-trips; paying MORE (PayPlus credit-plan interest) is not.
     */
    private const AMOUNT_TOLERANCE = 0.01;

    /** Where the page request id sits in a callback body (and the verify-on-return stub). */
    private const PAGE_REQUEST_PATHS = [
        'transaction.payment_page_request_uid',
        'data.transaction.payment_page_request_uid',
        'payment_page_request_uid',
        'data.payment_page_request_uid',
        'transaction.page_request_uid',
        'page_request_uid',
    ];

    /** Where our correlation marker sits — IPN (data.*) first, then the callback shapes. */
    private const MORE_INFO_PATHS = [
        'data.transaction.more_info',
        'data.more_info',
        'data.transactions.0.more_info',
        'transaction.more_info',
        'more_info',
    ];

    private const AMOUNT_PATHS = [
        'data.transaction.amount',
        'data.amount',
        'data.transactions.0.amount',
        'transaction.amount',
        'amount',
    ];

    private const CURRENCY_PATHS = [
        'data.transaction.currency',
        'data.transaction.currency_code',
        'data.currency',
        'data.currency_code',
        'transaction.currency',
        'transaction.currency_code',
        'currency',
        'currency_code',
    ];

    /** A three-letter ISO 4217 code — the only currency shape compared. */
    public const ISO_CURRENCY = '/^[A-Za-z]{3}$/';

    /** The key a cross-checked unsigned token is placed under (the resolver's first TOKEN_PATH). */
    private const TOKEN_KEY = 'token_uid';

    public function __construct(private readonly WooDepositTokenResolver $tokens) {}

    /**
     * The IPN sometimes answers with a LIST of transactions under `data` (the
     * reference engine met it). Fold the first one into the usual
     * `data.transaction` shape, so every reader downstream — this wall, the
     * finalizer's amount check, the card vault, the ledger row — sees one shape.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function normaliseIpn(array $body): array
    {
        $data = $body['data'] ?? null;
        if (is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $body['data'] = ['transaction' => $data[0]];
        }

        return $body;
    }

    // === Wall 1: the signature ===

    /** How the callback's `hash` header compares to the shop's secret. */
    public function signature(Request $request, Shop $shop): string
    {
        $sent = (string) $request->header(self::HASH_HEADER, '');
        if ($sent === '') {
            return self::SIGNATURE_ABSENT;
        }

        $secret = (string) ($shop->payplusCredential('secret_key') ?? '');
        if ($secret === '') {
            return self::SIGNATURE_UNVERIFIABLE;
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($expected, $sent) ? self::SIGNATURE_VALID : self::SIGNATURE_INVALID;
    }

    /**
     * The refusal for this verdict, or null to proceed (to wall 2).
     *
     * @param  string  $logPrefix  e.g. `woocommerce.deposit` — the log keys are unchanged
     */
    public function refusal(string $verdict, Shop $shop, string $logPrefix): ?JsonResponse
    {
        $required = (bool) config(self::CONFIG_REQUIRE_SIGNATURE, false);
        $context = ['shop_id' => $shop->getKey()];

        if ($required && (string) ($shop->payplusCredential('secret_key') ?? '') === '') {
            // Told to enforce what we cannot verify → refuse rather than trust.
            Log::error($logPrefix.'.callback_missing_secret', $context);

            return response()->json(['error' => 'service_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($verdict === self::SIGNATURE_INVALID) {
            Log::warning($logPrefix.'.callback_bad_signature', $context);

            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($required && $verdict !== self::SIGNATURE_VALID) {
            Log::warning($logPrefix.'.callback_unsigned_rejected', $context);

            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    // === Wall 2: PayPlus's own record ===

    /**
     * Ask PayPlus whether the page this claim is about was really paid, for us.
     *
     * @param  array<string, mixed>  $callback  the (untrusted) callback body; for a
     *                                          verify-on-return pull, a stub carrying
     *                                          only `page_request_uid`
     * @param  string  $expectedMoreInfo  the marker WE put on the page
     * @param  bool  $signed  the callback passed wall 1 with a VALID signature
     * @param  string|null  $ownPageRequestUid  the page id WE stored when minting it —
     *                                          preferred over any id in the body
     * @param  float|null  $minAmount  what is owed; null = no money is owed (card update)
     */
    public function confirm(
        Shop $shop,
        array $callback,
        string $expectedMoreInfo,
        bool $signed,
        ?string $ownPageRequestUid = null,
        ?float $minAmount = null,
        ?string $currency = null,
    ): PayPlusConfirmation {
        if ($expectedMoreInfo === '') {
            return $this->refuse($shop, 'no_marker');
        }

        $cfg = $shop->payplusConfig();
        if (empty($cfg['api_key']) || empty($cfg['secret_key'])) {
            return $this->refuse($shop, 'no_credentials');
        }

        $ownUid = trim((string) $ownPageRequestUid);
        $pageRequestUid = $ownUid !== '' ? $ownUid : $this->first($callback, self::PAGE_REQUEST_PATHS);
        if ($pageRequestUid === '') {
            return $this->refuse($shop, 'no_page_request_uid');
        }

        $status = PayPlusPageStatus::for($shop)->status($pageRequestUid);
        if (! $status['ok']) {
            Log::warning('payplus.callback.confirmation_unavailable', ['shop_id' => $shop->getKey()]);

            return new PayPlusConfirmation(PayPlusConfirmation::UNAVAILABLE, reason: 'ipn_unavailable');
        }

        /** @var array<string, mixed> $ipn */
        $ipn = self::normaliseIpn($status['body']);

        // BIND the page to the claim: PayPlus's record must carry OUR marker. A
        // record without one binds only when the page id is our own stored one,
        // or when PayPlus's signed callback names the marker.
        $ipnMoreInfo = $this->first($ipn, self::MORE_INFO_PATHS);
        if ($ipnMoreInfo !== '') {
            if (! hash_equals($expectedMoreInfo, $ipnMoreInfo)) {
                return $this->refuse($shop, 'more_info_mismatch');
            }
        } elseif (! ($ownUid !== '' || ($signed && $this->first($callback, self::MORE_INFO_PATHS) === $expectedMoreInfo))) {
            return $this->refuse($shop, 'more_info_missing');
        }

        $body = $signed ? array_replace_recursive($callback, $ipn) : $ipn;

        // Approval read at TRANSACTION level from PayPlus's own words — the IPN,
        // or a signed callback where the IPN left the code out. Never the
        // unsigned body's "000".
        if (! PayPlusPageStatus::approvedIn($body)) {
            return new PayPlusConfirmation(PayPlusConfirmation::DECLINED, $body, 'not_approved');
        }

        if ($minAmount !== null) {
            $paid = $this->first($body, self::AMOUNT_PATHS);
            if (! is_numeric($paid)) {
                return $this->refuse($shop, 'amount_missing');
            }
            if ((float) $paid + self::AMOUNT_TOLERANCE < round($minAmount, 2)) {
                return $this->refuse($shop, 'amount_short');
            }
        }

        // Only an ISO code is compared: a symbol or a numeric id is not evidence of a
        // different currency, and refusing on it would drop genuine payments.
        $paidCurrency = $this->first($body, self::CURRENCY_PATHS);
        if ($currency !== null && preg_match(self::ISO_CURRENCY, $currency) === 1
            && preg_match(self::ISO_CURRENCY, $paidCurrency) === 1
            && strtoupper($paidCurrency) !== strtoupper($currency)) {
            return $this->refuse($shop, 'currency_mismatch');
        }

        if (! $signed) {
            $body = $this->withCrossCheckedToken($shop, $body, $callback);
        }

        return new PayPlusConfirmation(PayPlusConfirmation::CONFIRMED, $body);
    }

    /**
     * The one unsigned field ever taken: a card token the IPN did not return,
     * and only when the callback's last four equal the card PayPlus reports for
     * this page. Without that match, the page's card is simply not saved (the
     * caller says so on the plan) — never a token a stranger typed into a body.
     *
     * @param  array<string, mixed>  $ipn
     * @param  array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withCrossCheckedToken(Shop $shop, array $ipn, array $callback): array
    {
        $fromIpn = $this->tokens->resolveFromOrder($shop, $ipn);
        if (($fromIpn['payplus_card_token_uid'] ?? null) !== null) {
            return $ipn;
        }

        $ipnLastFour = (string) ($fromIpn['card_last_four'] ?? '');
        if ($ipnLastFour === '') {
            return $ipn;
        }

        $fromCallback = $this->tokens->resolveFromOrder($shop, $callback);
        $token = (string) ($fromCallback['payplus_card_token_uid'] ?? '');
        if ($token === '' || ! hash_equals($ipnLastFour, (string) ($fromCallback['card_last_four'] ?? ''))) {
            return $ipn;
        }

        $ipn[self::TOKEN_KEY] = $token;

        return $ipn;
    }

    private function refuse(Shop $shop, string $reason): PayPlusConfirmation
    {
        Log::warning('payplus.callback.unconfirmed', ['shop_id' => $shop->getKey(), 'reason' => $reason]);

        return new PayPlusConfirmation(PayPlusConfirmation::UNCONFIRMED, reason: $reason);
    }

    /**
     * First non-empty scalar among the paths, as a string ('' when none).
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $paths
     */
    private function first(array $body, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($body, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }
}
