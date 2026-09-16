<?php

namespace App\Domain\Installments;

use App\Domain\Installments\Models\CardUpdateLink;
use App\Models\ActivityEvent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantLoyaltySettings;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusPageStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\PayPlus\PayPlusPageOptions;
use App\Services\WooCommerce\Orders\WooDepositTokenResolver;
use Illuminate\Support\Collection;
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

    /** A due cycle whose slot is in one of these was refused — see plansOwingOn(). */
    private const OWED_SLOT_STATUSES = [PaymentStatus::FAILED, PaymentStatus::RETRY_SCHEDULED];

    /**
     * Why a SUCCESSFUL PayPlus page did not become the plan's card. Each is a line
     * on the plan's Timeline — before these existed, all three were a log line
     * nobody reads, and a customer who did everything right looked like one who
     * never tried.
     */
    public const NOT_SAVED_NO_TOKEN = 'no_token';

    /** The merchant revoked the link before the page came back — see applyCallback(). */
    public const NOT_SAVED_LINK_REVOKED = 'link_revoked';

    /** Where the page request id sits in the callback, for the IPN fallback. */
    private const PAGE_REQUEST_PATHS = [
        'transaction.payment_page_request_uid',
        'data.transaction.payment_page_request_uid',
        'payment_page_request_uid',
        'transaction.page_request_uid',
        'page_request_uid',
    ];

    /** How deep the no-token log maps the callback's keys (names only, never values). */
    private const SHAPE_DEPTH = 3;

    public function __construct(private readonly WooDepositTokenResolver $tokens) {}

    /** Can this plan offer the button at all? (Shop side of the answer.) */
    public static function availableFor(Shop $shop, InstallmentPlan $plan): bool
    {
        // callbackToken(), not wc_shop_token: the rail is PayPlus's, not
        // WooCommerce's, and a Shopify shop charging through PayPlus needs it
        // just as much. The old column is the fallback inside that method.
        return $shop->hasPayplusConnection()
            && $shop->callbackToken() !== null
            && ! in_array($plan->status, self::TERMINAL, true);
    }

    /**
     * Mint the hosted re-vault page for this plan's owner, or null on refusal.
     * Moves no money by intent (see the class doc) and changes no state — the
     * link is the whole outcome, and an unclicked link expires on PayPlus's side.
     */
    public function mintPage(Shop $shop, InstallmentPlan $plan, ?CardUpdateLink $link = null): ?string
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
                // The link id rides along so the callback can stamp the RIGHT
                // link complete — a merchant who sent two reminders has two open
                // links, and "the newest one" would be a guess.
                'more_info' => self::moreInfoFor($plan, $link),
                'customer' => array_filter([
                    'customer_name' => trim((string) ($plan->customer_name ?? '')) ?: null,
                    'email' => trim((string) ($plan->customer_email ?? '')) ?: null,
                    'phone' => trim((string) ($plan->customer_phone ?? '')) ?: null,
                ]),
                'refURL_success' => $this->returnUrl($shop, 'success'),
                'refURL_failure' => $this->returnUrl($shop, 'failure'),
                'refURL_cancel' => $this->returnUrl($shop, 'cancel'),
                'refURL_callback' => route('payplus.cardupdate.callback', [
                    'callback_token' => (string) $shop->callbackToken(),
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
    public function applyCallback(
        Shop $shop,
        string $planPublicId,
        array $payload,
        ?int $linkId = null,
    ): ?InstallmentPaymentMethod {
        $plan = InstallmentPlan::query()->where('public_id', $planPublicId)->first();
        if ($plan === null) {
            return null;
        }

        /*
         * A LINK THE MERCHANT REVOKED does not attach a card. Revoking is what a
         * merchant does after "I sent that to the wrong person" — and the page it
         * led to may still be completed afterwards, by that wrong person, with
         * THEIR card. Attaching it would bill a stranger for this subscription.
         */
        $link = $linkId !== null
            ? CardUpdateLink::query()->whereKey($linkId)->where('plan_id', $plan->getKey())->first()
            : null;

        if ($link !== null && $link->revoked_at !== null && $link->completed_at === null) {
            $this->recordNotSaved($shop, $plan, self::NOT_SAVED_LINK_REVOKED, $linkId);

            return null;
        }

        $token = $this->resolveToken($shop, $payload);
        if ($token === null) {
            Log::warning('installments.card_update.no_token_in_callback', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                // Key names only, so the next real callback shows where the token
                // lives without a single value reaching the log.
                'shape' => self::shape($payload),
            ]);
            $this->recordNotSaved($shop, $plan, self::NOT_SAVED_NO_TOKEN, $linkId);

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

        // The merchant's status line says "card updated" because the card WAS
        // updated — not because somebody opened a page.
        $this->completeLink($plan, $linkId);

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

        // A plan that OWES a cycle was waiting for exactly this. Ask for the owed
        // cycle now, with the new card, instead of leaving it until somebody
        // notices. Queued rather than inline: this runs inside a gateway
        // callback, and a charge belongs on the worker with the ledger, the row
        // lock and the retry ladder. A callback delivered twice cannot charge
        // twice: the job is unique per plan, and RepeatChargeGuard refuses a
        // second charge within the day on any path.
        foreach ($this->plansOwingOn($method) as $held) {
            ChargeJob::dispatch(
                (int) $shop->getKey(),
                (int) $held->getKey(),
                ($held->isRecurring() ? PaymentType::RECURRING : PaymentType::INSTALLMENT)->value,
            );
        }

        return $method;
    }

    /**
     * The customer reached PayPlus and the card was REFUSED there. Worth a line:
     * "they tried and the bank said no" is a different phone call from "they
     * never opened it".
     */
    public function recordFailedAttempt(Shop $shop, string $planPublicId, ?int $linkId, string $statusCode): void
    {
        $plan = InstallmentPlan::query()->where('public_id', $planPublicId)->first();
        if ($plan === null) {
            return;
        }

        Timeline::record(
            kind: Timeline::KIND_CARD_UPDATE_FAILED,
            details: array_filter(['link_id' => $linkId, 'status_code' => $statusCode]),
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_CUSTOMER,
            shopId: (int) $shop->getKey(),
        );
    }

    /**
     * The new card, from the callback — or, when the callback carried none, from
     * PayPlus's own record of the page (the IPN), which returns the full
     * transaction. Null only when neither holds a token.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function resolveToken(Shop $shop, array $payload): ?array
    {
        $token = $this->tokens->resolveFromOrder($shop, [WooDepositTokenResolver::WRAP_KEY => $payload]);
        if (($token['payplus_card_token_uid'] ?? null) !== null) {
            return $token;
        }

        $pageRequestUid = '';
        foreach (self::PAGE_REQUEST_PATHS as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && $value !== '') {
                $pageRequestUid = $value;
                break;
            }
        }

        if ($pageRequestUid === '') {
            return null;
        }

        $status = PayPlusPageStatus::for($shop)->status($pageRequestUid);
        if (! $status['approved']) {
            return null;
        }

        $pulled = $this->tokens->resolveFromOrder($shop, [WooDepositTokenResolver::WRAP_KEY => $status['body']]);

        return ($pulled['payplus_card_token_uid'] ?? null) !== null ? $pulled : null;
    }

    /** The page succeeded at PayPlus and the card is NOT on the plan — said on the plan. */
    private function recordNotSaved(Shop $shop, InstallmentPlan $plan, string $reason, ?int $linkId): void
    {
        Timeline::record(
            kind: Timeline::KIND_CARD_UPDATE_NOT_SAVED,
            details: array_filter(['reason' => $reason, 'link_id' => $linkId]),
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_SYSTEM,
            shopId: (int) $shop->getKey(),
        );
    }

    /**
     * The payload's key names, nested, with every value dropped.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function shape(array $payload, int $depth = self::SHAPE_DEPTH): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $out[(string) $key] = is_array($value) && $depth > 1 && ! array_is_list($value)
                ? self::shape($value, $depth - 1)
                : get_debug_type($value);
        }

        return $out;
    }

    /**
     * Every plan now billing on this card that owes a cycle — the one the
     * customer updated, and any sibling repoint() carried along.
     *
     * OWES means one of three things, and it used to mean only the first:
     *   - HELD: collection gave up and paused it on the unpaid cycle;
     *   - STILL BEING CHASED: the cycle is due and its slot is failed or waiting
     *     for tomorrow's retry. That is the usual state of a customer who fixes
     *     their card quickly — and they were left unbilled until the retry
     *     ladder came round again, a day later, or not at all;
     *   - STOPPED: the plan is `failed` and nothing is asking it for money. The
     *     CSV importer files a member whose source said past_due exactly there,
     *     with no charge date, no slot and no hold stamp — invisible to both
     *     tests above. Plan 995 (16/09): the customer saved a new card, nothing
     *     was charged, and an admin had to press the button an hour later.
     *
     * A plan whose next cycle is simply in the future owes nothing, and is not
     * charged early.
     *
     * @return Collection<int, InstallmentPlan>
     */
    private function plansOwingOn(InstallmentPaymentMethod $method): Collection
    {
        return InstallmentPlan::query()
            ->with('latestPayment')
            ->where('payment_method_id', $method->getKey())
            ->whereNotIn('status', array_map(static fn (PlanStatus $s): string => $s->value, self::TERMINAL))
            ->get()
            ->filter(static function (InstallmentPlan $plan): bool {
                if ($plan->payment_failed_at !== null) {
                    return true;
                }

                // STOPPED — unless its next cycle is still ahead, which means it was
                // already paid up to then and charging now would bill that cycle
                // early: the exact double-charge the RepeatChargeGuard exists for,
                // but a month apart, where the guard's day cannot see it.
                if ($plan->status === PlanStatus::FAILED) {
                    return $plan->next_charge_at === null || $plan->next_charge_at->lte(now());
                }

                $due = $plan->next_charge_at !== null && $plan->next_charge_at->lte(now());
                $slot = $plan->latestPayment?->status;

                return $due && in_array($slot, self::OWED_SLOT_STATUSES, true);
            })
            ->values();
    }

    /**
     * The correlation marker: `cardupd:{public_id}` as it always was, plus the
     * link id when the page was minted from one.
     *
     * The plan id stays FIRST and the prefix unchanged, so a callback for a page
     * minted before this existed still parses — PayPlus can be holding a page
     * URL from days ago.
     */
    public static function moreInfoFor(InstallmentPlan $plan, ?CardUpdateLink $link = null): string
    {
        $marker = self::MORE_INFO_PREFIX.$plan->public_id;

        return $link !== null ? $marker.':'.$link->getKey() : $marker;
    }

    /**
     * Split an echoed marker back into its parts.
     *
     * @return array{public_id: string, link_id: ?int}
     */
    public static function parseMoreInfo(string $moreInfo): array
    {
        $rest = substr($moreInfo, strlen(self::MORE_INFO_PREFIX));
        $parts = explode(':', $rest, 2);

        $linkId = isset($parts[1]) && ctype_digit(trim($parts[1])) ? (int) trim($parts[1]) : null;

        return ['public_id' => (string) ($parts[0] ?? ''), 'link_id' => $linkId];
    }

    // === Internals ===

    /**
     * Stamp the link that produced this update. Scoped to the PLAN as well as
     * the id, so a marker naming somebody else's link cannot close it.
     */
    private function completeLink(InstallmentPlan $plan, ?int $linkId): void
    {
        if ($linkId === null) {
            return;
        }

        CardUpdateLink::query()
            ->whereKey($linkId)
            ->where('plan_id', $plan->getKey())
            ->first()
            ?->markCompleted();
    }

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
        return route('payplus.cardupdate.return', [
            'callback_token' => (string) $shop->callbackToken(),
            'status' => $status,
            // The landing must speak the language the ACCOUNT spoke — decided
            // at mint time, when the tenant is bound, and carried in the URL so
            // the return request (which arrives with no session, no tenant)
            // does not have to rediscover it.
            'lang' => self::shopperLocale(),
        ]);
    }

    /**
     * The language this shop's personal area is written in — the portal
     * appearance choice, falling through AUTO to the club page's language, the
     * same ladder ResolvesShopperLocale climbs when no request locale exists.
     */
    public static function shopperLocale(): string
    {
        try {
            $choice = MerchantPortalAppearance::current()->pageLocale();

            if ($choice !== MerchantPortalAppearance::LOCALE_AUTO) {
                return $choice;
            }

            return MerchantLoyaltySettings::current()->pageLocale();
        } catch (Throwable) {
            return 'he';
        }
    }
}
