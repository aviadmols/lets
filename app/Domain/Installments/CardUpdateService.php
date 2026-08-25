<?php

namespace App\Domain\Installments;

use App\Models\ActivityEvent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\PayPlus\PayPlusPageOptions;
use App\Services\WooCommerce\Orders\WooDepositTokenResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Update my card" on the PayPlus rail — the flow the reference engine never had
 * (see the TODO(payplus-card-update) it left behind).
 *
 * THE SHAPE: we mint a PayPlus HOSTED page whose only job is to re-vault a new
 * token (`create_token => true`); the shopper types their card on PAYPLUS'S page
 * (digits never touch us); PayPlus's server-to-server callback hands us the new
 * token; we vault it as a fresh InstallmentPaymentMethod and point the plan — and
 * every sibling plan that shared the old card — at it. The old method row stays,
 * untouched: it is the history of what charged until today.
 *
 * THE MONEY QUESTION: the page carries a MINIMAL amount and a config-driven
 * charge_method. `0` is PayPlus's authorize/verify-only mode (the W17 note: a
 * success screen, no capture) — for a charge that was the bug, for a card update
 * it is the feature. UNVERIFIED against a live terminal whether verify-only also
 * vaults a token; if it does not, flip CONFIG_CHARGE_METHOD to 1 (capture the
 * symbolic amount) and refund by policy. Both knobs are env flips, not code.
 *
 * TRUST: the callback rides the same rails as the deposit callback — the opaque
 * {wc_shop_token} resolves the shop before anything in the body is trusted, the
 * optional PayPlus `hash` signature fails closed when present, and the body can
 * only ever re-point plans of the customer its `more_info` names. Replay is
 * idempotent: the same token uid for the same customer reuses the same row.
 */
final class CardUpdateService
{
    // === CONSTANTS ===
    /**
     * The correlation marker (`more_info`) prefix. Deposit callbacks echo a bare
     * public_id; card updates are prefixed so neither flow can ever be replayed
     * into the other.
     */
    public const MORE_INFO_PREFIX = 'cardupd:';

    /** @see config/payplus.php — the symbolic page amount (₪) and charge method. */
    public const CONFIG_AMOUNT = 'payplus.card_update_amount';

    public const CONFIG_CHARGE_METHOD = 'payplus.card_update_charge_method';

    /** generateLink response key (same as every other hosted-page mint). */
    private const RESP_PAGE_LINK = 'data.payment_page_link';

    /** Plans in these states are done charging; a new card changes nothing. */
    public const TERMINAL = [PlanStatus::COMPLETED, PlanStatus::CANCELLED];

    public function __construct(private readonly WooDepositTokenResolver $tokens) {}

    /** Can this plan offer the button at all? (Shop side of the answer.) */
    public static function availableFor(Shop $shop, InstallmentPlan $plan): bool
    {
        return $shop->hasPayplusConnection()
            && trim((string) ($shop->wc_shop_token ?? '')) !== ''
            && ! in_array($plan->status, self::TERMINAL, true);
    }

    /**
     * Mint the hosted re-vault page for this plan's owner, or null on refusal.
     * Moves no money by intent (see the class doc) and changes no state — the
     * link is the whole outcome, and an unclicked link expires on PayPlus's side.
     */
    public function mintPage(Shop $shop, InstallmentPlan $plan): ?string
    {
        if (! self::availableFor($shop, $plan)) {
            return null;
        }

        try {
            $result = PayPlusGatewayFactory::for($shop)->generateLink([
                // The merchant's page options first; the correlation keys win.
                ...app(PayPlusPageOptions::class)->for($shop),
                'amount' => round(max(0.0, (float) config(self::CONFIG_AMOUNT, 1.0)), 2),
                'product_name' => (string) __('storefront.card_update.item'),
                'charge_method' => (int) config(self::CONFIG_CHARGE_METHOD, 0),
                // The whole point of the page.
                'create_token' => true,
                'more_info' => self::MORE_INFO_PREFIX.$plan->public_id,
                'customer' => array_filter([
                    'customer_name' => trim((string) ($plan->customer_name ?? '')) ?: null,
                    'email' => trim((string) ($plan->customer_email ?? '')) ?: null,
                    'phone' => trim((string) ($plan->customer_phone ?? '')) ?: null,
                ]),
                'refURL_success' => $this->returnUrl($shop, 'success'),
                'refURL_failure' => $this->returnUrl($shop, 'failure'),
                'refURL_cancel' => $this->returnUrl($shop, 'cancel'),
                'refURL_callback' => route('woocommerce.cardupdate.callback', [
                    'wc_shop_token' => (string) $shop->wc_shop_token,
                ]),
                'send_failure_callback' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('installments.card_update.mint_failed', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $result->success) {
            Log::warning('installments.card_update.generate_link_refused', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'error_code' => $result->errorCode,
            ]);

            return null;
        }

        $link = (string) (data_get($result->raw, self::RESP_PAGE_LINK) ?? '');

        return $link !== '' ? $link : null;
    }

    /**
     * Apply a successful re-vault callback: vault the token, point the plan at
     * it, and carry every sibling plan that shared the old card along.
     *
     * Runs under Tenant::run($shop) — every query below is tenant-scoped.
     *
     * @param  array<string, mixed>  $payload  the raw PayPlus body
     */
    public function applyCallback(Shop $shop, string $planPublicId, array $payload): ?InstallmentPaymentMethod
    {
        $plan = InstallmentPlan::query()->where('public_id', $planPublicId)->first();
        if ($plan === null) {
            return null;
        }

        $token = $this->tokens->resolveFromOrder($shop, [WooDepositTokenResolver::WRAP_KEY => $payload]);
        if ($token === null || ($token['payplus_card_token_uid'] ?? null) === null) {
            Log::warning('installments.card_update.no_token_in_callback', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
            ]);

            return null;
        }

        $previousMethodId = $plan->payment_method_id !== null ? (int) $plan->payment_method_id : null;

        $method = $this->findExisting($plan, (string) $token['payplus_card_token_uid'])
            ?? InstallmentPaymentMethod::query()->create([
                // Identity copied off the PLAN, exactly like activation vaulting —
                // never off the callback, which is only a hint.
                'customer_id' => $plan->customer_id,
                'shopify_customer_id' => $plan->shopify_customer_id,
                'payplus_card_token_uid' => $token['payplus_card_token_uid'],
                'payplus_customer_uid' => $token['payplus_customer_uid'] ?? null,
                'payplus_token_reference' => $token['payplus_token_reference'] ?? null,
                'card_brand' => $token['card_brand'] ?? null,
                'card_last_four' => $token['card_last_four'] ?? null,
                'exp_month' => $token['exp_month'] ?? null,
                'exp_year' => $token['exp_year'] ?? null,
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

        $repointed = $this->repoint($plan, $method, $previousMethodId);

        Timeline::record(
            kind: Timeline::KIND_CARD_UPDATED,
            details: array_filter([
                'brand' => $method->card_brand,
                'last_four' => $method->card_last_four,
                'plans' => $repointed,
            ]),
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_CUSTOMER,
            shopId: (int) $shop->getKey(),
        );

        return $method;
    }

    // === Internals ===

    /**
     * A replayed callback (or a shopper vaulting the same card twice) reuses the
     * existing row. Compared in PHP: the token column is an encrypted cast, so
     * SQL cannot ask.
     */
    private function findExisting(InstallmentPlan $plan, string $tokenUid): ?InstallmentPaymentMethod
    {
        $query = InstallmentPaymentMethod::query()->where(function ($q) use ($plan): void {
            $matched = false;
            if ($plan->customer_id !== null) {
                $q->orWhere('customer_id', $plan->customer_id);
                $matched = true;
            }
            $ref = trim((string) ($plan->shopify_customer_id ?? ''));
            if ($ref !== '') {
                $q->orWhere('shopify_customer_id', $ref);
                $matched = true;
            }
            if (! $matched) {
                $q->whereRaw('1 = 0'); // no identity → match nobody, fail closed
            }
        });

        foreach ($query->orderByDesc('id')->limit(50)->get() as $candidate) {
            if ((string) $candidate->payplus_card_token_uid === $tokenUid) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Point the plan — and every non-terminal sibling that shared its OLD card —
     * at the new method. One person updated one card; a subscription of theirs
     * left failing on the dead token would be the bug they came to fix.
     *
     * @return int how many plans now charge the new card
     */
    private function repoint(InstallmentPlan $plan, InstallmentPaymentMethod $method, ?int $previousMethodId): int
    {
        $plan->forceFill(['payment_method_id' => $method->getKey()])->save();
        $count = 1;

        if ($previousMethodId === null || $previousMethodId === (int) $method->getKey()) {
            return $count;
        }

        $siblings = InstallmentPlan::query()
            ->whereKeyNot($plan->getKey())
            ->where('payment_method_id', $previousMethodId)
            ->whereNotIn('status', array_map(static fn (PlanStatus $s): string => $s->value, self::TERMINAL))
            ->get();

        foreach ($siblings as $sibling) {
            $sibling->forceFill(['payment_method_id' => $method->getKey()])->save();
            $count++;
        }

        return $count;
    }

    private function returnUrl(Shop $shop, string $status): string
    {
        return route('woocommerce.cardupdate.return', [
            'wc_shop_token' => (string) $shop->wc_shop_token,
            'status' => $status,
        ]);
    }
}
