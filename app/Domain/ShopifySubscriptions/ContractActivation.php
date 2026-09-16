<?php

namespace App\Domain\ShopifySubscriptions;

use App\Domain\Installments\PlanActivation;
use App\Domain\Mail\MailPolicy;
use App\Domain\ShopifySubscriptions\Jobs\SendContractActivationLinkJob;
use App\Mail\ContractActivationMail;
use App\Mail\Support\CampaignMailer;
use App\Models\MerchantMailSettings;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Activation links on the Shopify Payments rail — PlanActivation's twin for a contract.
 *
 * Shopify's checkout takes the first cycle and creates the contract as it always does. When
 * the contract's selling plan belongs to a product plan with `requires_activation`, the
 * contract is HELD the moment its create webhook arrives: marked here, paused at Shopify,
 * and its customer emailed a link. Confirming the link sets the next charge one cycle from
 * THAT day and starts it again.
 *
 * THE MONEY WALL IS LOCAL. Shopify does not bill a contract by itself — LETS asks, cycle by
 * cycle — so the hold is `awaiting_activation_at`, read by the scanner, by isBillable() and
 * so by every attempt. The pause at Shopify is what the store and the customer's account
 * see; if Shopify refuses it, nothing is charged either way.
 *
 * The link, the page and the email are the PayPlus rail's: a signed URL over the mirror id
 * and a per-contract nonce, a GET that changes nothing (mail scanners), a POST that starts.
 */
final class ContractActivation
{
    // === CONSTANTS ===
    public const ROUTE_SHOW = 'contract.activation.show';

    public const ROUTE_ACTIVATE = 'contract.activation.activate';

    /** The only topic that may hold a contract: one Shopify has just created. */
    public const TOPIC_CREATE = 'subscription_contracts/create';

    public function __construct(private readonly ContractActionService $actions) {}

    /** Was this contract sold by one of our plans that asks its customer to start it? */
    public static function required(SubscriptionContract $contract): bool
    {
        $sellingPlans = collect((array) ($contract->lines ?? []))
            ->map(fn ($line): string => is_array($line) ? (string) ($line['selling_plan_id'] ?? '') : '')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $sellingPlans !== [] && ProductSubscriptionPlan::query()
            ->where('shop_id', (int) $contract->shop_id)
            ->whereIn('shopify_selling_plan_gid', $sellingPlans)
            ->where('requires_activation', true)
            ->exists();
    }

    /** Does any of this shop's product plans ask its customers to start the subscription? */
    public static function shopUsesActivation(Shop $shop): bool
    {
        return ProductSubscriptionPlan::query()
            ->where('shop_id', (int) $shop->getKey())
            ->where('requires_activation', true)
            ->exists();
    }

    /**
     * Hold a just-created contract for its customer, when its plan asks for that. Once only:
     * the claim is a conditional write, so a redelivered webhook (or two at once) holds and
     * emails it a single time, and a contract already started is never held again.
     */
    public function holdIfRequired(Shop $shop, SubscriptionContract $contract): bool
    {
        if (! self::required($contract)) {
            return false;
        }

        $claimed = SubscriptionContract::query()
            ->whereKey($contract->getKey())
            ->whereNull('awaiting_activation_at')
            ->whereNull('activated_at')
            ->update([
                'awaiting_activation_at' => now(),
                'activation_nonce' => Str::random(PlanActivation::NONCE_LENGTH),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $contract->refresh();

        Timeline::record(
            kind: PlanActivation::KIND_AWAITING,
            details: ['contract_gid' => (string) $contract->shopify_gid],
            shopId: (int) $shop->getKey(),
        );

        // Nothing bills it from the write above. This is what the store and the customer see.
        $paused = $this->actions->pauseForActivation($shop, $contract);
        if (! $paused['ok']) {
            Log::warning('shopify_subscriptions.activation_pause_failed', [
                'shop_id' => $shop->getKey(), 'contract_id' => $contract->getKey(), 'reason' => $paused['reason'],
            ]);
        }

        SendContractActivationLinkJob::dispatch((int) $shop->getKey(), (int) $contract->getKey());

        return true;
    }

    /**
     * Start a held contract: next charge one cycle from today, running again at Shopify, hold
     * lifted. The hold is lifted LAST, and only once Shopify took both changes — a failure
     * part-way leaves the contract unbillable and the customer can press again. A contract
     * that is not waiting comes back as it is.
     *
     * @return array{ok: bool, reason: ?string, contract: SubscriptionContract}
     */
    public function activate(Shop $shop, SubscriptionContract $contract, ?string $actor = null): array
    {
        $contract = $contract->fresh() ?? $contract;

        if (! $contract->awaitsActivation()) {
            return ['ok' => true, 'reason' => null, 'contract' => $contract];
        }

        // Counted from the DAY, like the PayPlus rail: due on that date, billed that morning.
        $next = ContractActionService::addInterval(Carbon::now()->startOfDay(), (string) $contract->interval, (int) $contract->interval_count);

        $started = $this->actions->startForActivation($shop, $contract, $next);

        if (! $started['ok']) {
            Log::warning('shopify_subscriptions.activation_start_failed', [
                'shop_id' => $shop->getKey(), 'contract_id' => $contract->getKey(), 'reason' => $started['reason'],
            ]);

            return ['ok' => false, 'reason' => $started['reason'], 'contract' => $contract];
        }

        $lifted = SubscriptionContract::query()
            ->whereKey($contract->getKey())
            ->whereNull('activated_at')
            ->update(['activated_at' => now()]);

        if ($lifted === 1) {
            Timeline::record(
                kind: PlanActivation::KIND_ACTIVATED,
                details: ['contract_gid' => (string) $contract->shopify_gid, 'next_charge_at' => $next->toDateString()],
                actor: $actor,
                shopId: (int) $shop->getKey(),
            );
        }

        return ['ok' => true, 'reason' => null, 'contract' => $contract->fresh() ?? $contract];
    }

    /** The contract's link. Mints the nonce if it has none. */
    public function url(SubscriptionContract $contract): string
    {
        return URL::signedRoute(self::ROUTE_SHOW, $this->parameters($contract));
    }

    /** The landing page's form target: the same contract and nonce, signed separately. */
    public function activateUrl(SubscriptionContract $contract): string
    {
        return URL::signedRoute(self::ROUTE_ACTIVATE, $this->parameters($contract));
    }

    /** Kill every link sent so far. The next one asked for carries a new nonce. */
    public function revoke(SubscriptionContract $contract): void
    {
        $contract->forceFill(['activation_nonce' => Str::random(PlanActivation::NONCE_LENGTH)])->save();

        Timeline::record(
            kind: PlanActivation::KIND_LINK_REVOKED,
            details: ['contract_gid' => (string) $contract->shopify_gid],
            shopId: (int) $contract->shop_id,
        );
    }

    /**
     * The shop and contract behind a signed link, or null. The audited cross-tenant read: a
     * customer arrives with nothing but the link, matched on the id and its CURRENT nonce.
     *
     * @return array{0: Shop, 1: SubscriptionContract}|null
     */
    public function resolve(int $contractId, string $nonce): ?array
    {
        $contract = SubscriptionContract::acrossAllTenants()->whereKey($contractId)->first();
        $shop = $contract !== null ? Shop::query()->find((int) $contract->shop_id) : null;
        $stored = (string) ($contract?->activation_nonce ?? '');

        if ($contract === null || ! $shop instanceof Shop || ! $shop->isLive() || $stored === '' || ! hash_equals($stored, $nonce)) {
            return null;
        }

        return [$shop, $contract];
    }

    /** Email the link. False with no address, a contract no longer waiting, or a failed send. */
    public function send(Shop $shop, SubscriptionContract $contract): bool
    {
        $to = trim((string) ($contract->customer_email ?? ''));

        if ($to === '' || ! $contract->awaitsActivation()) {
            return false;
        }

        if (! app(MailPolicy::class)->allowsAndLogs($shop, MerchantMailSettings::TEMPLATE_PLAN_ACTIVATION, ['contract_id' => $contract->getKey()])) {
            return false;
        }

        try {
            CampaignMailer::for($shop)->to($to)->send(new ContractActivationMail($shop, $contract, $this->url($contract)));
        } catch (Throwable $e) {
            Log::warning('shopify_subscriptions.activation_email_failed', [
                'shop_id' => $shop->getKey(), 'contract_id' => $contract->getKey(), 'error' => $e->getMessage(),
            ]);

            return false;
        }

        Timeline::record(
            kind: PlanActivation::KIND_LINK_SENT,
            details: ['contract_gid' => (string) $contract->shopify_gid, 'sent_to' => $to],
            shopId: (int) $shop->getKey(),
        );

        return true;
    }

    /** @return array{contract: int, nonce: string} */
    private function parameters(SubscriptionContract $contract): array
    {
        $nonce = (string) ($contract->activation_nonce ?? '');

        if ($nonce === '') {
            $nonce = Str::random(PlanActivation::NONCE_LENGTH);
            $contract->forceFill(['activation_nonce' => $nonce])->save();
        }

        return ['contract' => (int) $contract->getKey(), 'nonce' => $nonce];
    }
}
