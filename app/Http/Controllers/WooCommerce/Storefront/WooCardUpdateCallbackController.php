<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\CardUpdateService;
use App\Models\Shop;
use App\Services\PayPlus\PayPlusCallbackVerifier;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPlus → SaaS CARD-UPDATE callback — the re-vault page's server-to-server
 * completion, on exactly the deposit callback's trust rails:
 *
 *   1. The opaque callback token resolves the shop BEFORE any body field is
 *      trusted; unknown token → 404, and no shop's existence is ever revealed.
 *   2. A present PayPlus `hash` signature is verified against the shop's
 *      secret_key and FAILS CLOSED; enforcement of absent signatures follows the
 *      same config switch as the deposit callback.
 *   3. The body is a CLAIM. PayPlusCallbackVerifier asks PayPlus's own IPN about
 *      the page and only a page PayPlus reports APPROVED, carrying this exact
 *      `cardupd:` marker, attaches a card — the card PayPlus reports for that
 *      page. A body naming someone else's plan names a page that was never paid.
 *   4. The marker can only ever act on the plan it names — a deposit callback
 *      replayed here matches nothing, and vice versa — and CardUpdateService is
 *      idempotent on the token uid, so a replay changes nothing.
 *
 * No money moves here in any branch: this endpoint only VAULTS.
 */
final class WooCardUpdateCallbackController
{
    // === CONSTANTS ===
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];

    private const LOG_PREFIX = 'installments.card_update';

    public function __invoke(Request $request, string $callback_token, PayPlusCallbackVerifier $verifier): JsonResponse
    {
        // EITHER column: the token was born as `wc_shop_token` and PayPlus can be
        // holding a page URL minted with it long before the rename.
        $shop = Shop::query()
            ->where('callback_token', $callback_token)
            ->orWhere('wc_shop_token', $callback_token)
            ->first();

        if ($shop === null) {
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $signature = $verifier->signature($request, $shop);
        if (($refusal = $verifier->refusal($signature, $shop, self::LOG_PREFIX)) !== null) {
            return $refusal;
        }
        $signed = $signature === PayPlusCallbackVerifier::SIGNATURE_VALID;

        $payload = (array) $request->json()->all();
        $moreInfo = $this->moreInfo($payload);
        $statusCode = strtolower($this->statusCode($payload));

        Log::info(self::LOG_PREFIX.'.callback', [
            'shop_id' => $shop->getKey(),
            'more_info' => $moreInfo,
            'status_code' => $statusCode,
            'signed' => $signed,
        ]);

        // Only OUR marker. Anything else is acknowledged and ignored.
        if (! str_starts_with($moreInfo, CardUpdateService::MORE_INFO_PREFIX)) {
            return response()->json(['ok' => true, 'updated' => false]);
        }

        // `cardupd:{public_id}` as it always was, optionally followed by the id
        // of the durable link the page was minted from — so the merchant's status
        // line closes the RIGHT link when they sent more than one reminder.
        ['public_id' => $planPublicId, 'link_id' => $linkId] = CardUpdateService::parseMoreInfo($moreInfo);

        // What PayPlus itself says about the page this body names. No amount is
        // owed on a card update, so the binding is the marker alone.
        $confirmation = $verifier->confirm($shop, $payload, $moreInfo, $signed);

        if ($confirmation->unavailable()) {
            // PayPlus could not be asked — let it deliver again rather than lose a card.
            return response()->json(['error' => 'confirmation_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // A refused card is acknowledged (PayPlus stops retrying), vaults nothing,
        // and is SAID on the plan — "they tried and the bank refused" is a
        // different call to make than "they never opened it". Only for a page
        // that is provably ours: a signed decline, or one PayPlus's record holds.
        if (! in_array($statusCode, self::SUCCESS_CODES, true)) {
            if ($signed || $confirmation->bound()) {
                Tenant::run($shop, fn () => app(CardUpdateService::class)
                    ->recordFailedAttempt($shop, $planPublicId, $linkId, $statusCode));
            }

            return response()->json(['ok' => true, 'updated' => false]);
        }

        if (! $confirmation->confirmed()) {
            Log::warning(self::LOG_PREFIX.'.callback_unconfirmed', [
                'shop_id' => $shop->getKey(),
                'reason' => $confirmation->reason,
            ]);

            return response()->json(['ok' => true, 'updated' => false]);
        }

        $method = Tenant::run($shop, fn () => app(CardUpdateService::class)
            ->applyCallback($shop, $planPublicId, $confirmation->body, $linkId));

        return response()->json(['ok' => true, 'updated' => $method !== null]);
    }

    /** The echoed more_info, tolerant of nested/flat PayPlus shapes. */
    private function moreInfo(array $payload): string
    {
        return (string) (
            data_get($payload, 'transaction.more_info')
            ?? data_get($payload, 'more_info')
            ?? data_get($payload, 'data.transaction.more_info')
            ?? ''
        );
    }

    /** The PayPlus status/result code, tolerant of nested/flat shapes. */
    private function statusCode(array $payload): string
    {
        return (string) (
            data_get($payload, 'transaction.status_code')
            ?? data_get($payload, 'status_code')
            ?? data_get($payload, 'transaction.status')
            ?? data_get($payload, 'status')
            ?? ''
        );
    }
}
