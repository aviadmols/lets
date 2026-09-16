<?php

namespace App\Domain\Installments;

use App\Domain\Installments\Jobs\SendPlanActivationLinkJob;
use App\Domain\Mail\MailPolicy;
use App\Mail\PlanActivationMail;
use App\Mail\Support\CampaignMailer;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * A subscription the customer starts themselves.
 *
 * The product's subscription plan says `requires_activation`. The checkout is unchanged —
 * the first cycle is paid and the card vaulted — but the plan is held at
 * `awaiting_activation` with NO charge date, and the customer is emailed a link. Opening
 * it and confirming starts the subscription: active, and the next charge one cycle from
 * THAT day. The cycle they paid for at checkout is the one that begins when they activate.
 *
 * THE LINK is a signed URL (APP_KEY) carrying the plan's public id and a per-plan nonce
 * kept in its meta. Signed, so it cannot be forged or edited; the nonce, so the merchant
 * can revoke it (a new nonce kills every link sent before) without a table of tokens.
 * It does not expire: a subscription somebody paid for must not be lost to a date.
 *
 * NOTHING HAPPENS ON GET. Mail scanners open every link in an email before the person
 * does, so the link shows a page with a button, and only that POST activates.
 */
final class PlanActivation
{
    // === CONSTANTS ===
    public const META_NONCE = 'activation_nonce';

    public const ROUTE_SHOW = 'plan.activation.show';

    public const ROUTE_ACTIVATE = 'plan.activation.activate';

    public const NONCE_LENGTH = 40;

    public const KIND_AWAITING = 'subscription_awaiting_activation';

    public const KIND_ACTIVATED = 'subscription_activated';

    public const KIND_LINK_SENT = 'activation_link_sent';

    public const KIND_LINK_REVOKED = 'activation_link_revoked';

    /**
     * Does this plan's product ask its customer to start it? Read off the template the plan
     * was born from, at the moment the checkout is paid.
     */
    public static function required(InstallmentPlan $plan): bool
    {
        return $plan->isRecurring() && (bool) $plan->template?->requires_activation;
    }

    /** The plan's link. Mints the nonce the first time it is asked for. */
    public function url(InstallmentPlan $plan): string
    {
        return URL::signedRoute(self::ROUTE_SHOW, $this->parameters($plan));
    }

    /** The form target on the landing page: the same plan and nonce, signed separately. */
    public function activateUrl(InstallmentPlan $plan): string
    {
        return URL::signedRoute(self::ROUTE_ACTIVATE, $this->parameters($plan));
    }

    /** Kill every link sent so far. The next one asked for carries a new nonce. */
    public function revoke(InstallmentPlan $plan): void
    {
        $this->storeNonce($plan, Str::random(self::NONCE_LENGTH));

        Timeline::record(kind: self::KIND_LINK_REVOKED, planId: (int) $plan->getKey(), shopId: (int) $plan->shop_id);
    }

    /**
     * The shop and plan behind a signed link, or null. The signature was checked by the
     * route; this is the audited cross-tenant read (a customer arrives with nothing but the
     * link), matched on the plan's public id and its CURRENT nonce.
     *
     * @return array{0: Shop, 1: InstallmentPlan}|null
     */
    public function resolve(string $publicId, string $nonce): ?array
    {
        $plan = InstallmentPlan::acrossAllTenants()->where('public_id', $publicId)->first();
        $shop = $plan !== null ? Shop::query()->find((int) $plan->shop_id) : null;
        $stored = (string) (($plan?->meta ?? [])[self::META_NONCE] ?? '');

        if ($plan === null || ! $shop instanceof Shop || ! $shop->isLive() || $stored === '' || ! hash_equals($stored, $nonce)) {
            return null;
        }

        return [$shop, $plan];
    }

    /**
     * Start the subscription: active, next charge one cycle from today. Idempotent — a
     * second click, or the merchant pressing the button after the customer did, changes
     * nothing and returns the plan as it is.
     */
    public function activate(InstallmentPlan $plan, ?string $actor = null): InstallmentPlan
    {
        return DB::transaction(function () use ($plan, $actor): InstallmentPlan {
            $fresh = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if ($fresh->status !== PlanStatus::AWAITING_ACTIVATION) {
                return $fresh;
            }

            // Counted from the DAY, like every other charge date in the engine: the plan is
            // due on that date and the scheduler bills it that morning.
            $interval = max(1, (int) ($fresh->interval_count ?: 1));
            $today = CarbonImmutable::now()->startOfDay();
            $next = $fresh->billing_frequency !== null
                ? CarbonImmutable::parse($fresh->billing_frequency->addTo($today, $interval))
                : $today->addMonthNoOverflow();

            $fresh->forceFill(['next_charge_at' => $next])->save();
            $fresh->transitionTo(PlanStatus::ACTIVE, ['action' => 'activated']);

            Timeline::record(
                kind: self::KIND_ACTIVATED,
                details: ['next_charge_at' => $next->toDateString()],
                planId: (int) $fresh->getKey(),
                actor: $actor,
                shopId: (int) $fresh->shop_id,
            );

            return $fresh;
        });
    }

    /**
     * Hold a just-paid plan for its customer instead of starting it. Called inside the
     * checkout activation transaction; the email is queued for after the commit.
     */
    public function hold(InstallmentPlan $plan): void
    {
        $plan->forceFill(['next_charge_at' => null])->save();
        $plan->transitionTo(PlanStatus::AWAITING_ACTIVATION, ['action' => 'paid_awaiting_activation']);
        $this->nonce($plan);

        Timeline::record(kind: self::KIND_AWAITING, planId: (int) $plan->getKey(), shopId: (int) $plan->shop_id);

        $shopId = (int) $plan->shop_id;
        $planId = (int) $plan->getKey();
        DB::afterCommit(static fn () => SendPlanActivationLinkJob::dispatch($shopId, $planId));
    }

    /** Email the link to the customer. False when there is no address or the send failed. */
    public function send(Shop $shop, InstallmentPlan $plan): bool
    {
        $to = trim((string) ($plan->customer_email ?? ''));

        if ($to === '' || $plan->status !== PlanStatus::AWAITING_ACTIVATION) {
            return false;
        }

        if (! app(MailPolicy::class)->allowsAndLogs($shop, MerchantMailSettings::TEMPLATE_PLAN_ACTIVATION, ['plan_id' => $plan->getKey()])) {
            return false;
        }

        try {
            // Per-shop mailer built at RUN time, as every customer email is.
            CampaignMailer::for($shop)->to($to)->send(new PlanActivationMail($shop, $plan, $this->url($plan)));
        } catch (Throwable $e) {
            Log::warning('installments.activation.email_failed', [
                'shop_id' => $shop->getKey(), 'plan_id' => $plan->getKey(), 'error' => $e->getMessage(),
            ]);

            return false;
        }

        Timeline::record(
            kind: self::KIND_LINK_SENT,
            details: ['sent_to' => $to],
            planId: (int) $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );

        return true;
    }

    /** @return array{plan: string, nonce: string} */
    private function parameters(InstallmentPlan $plan): array
    {
        return ['plan' => (string) $plan->public_id, 'nonce' => $this->nonce($plan)];
    }

    private function nonce(InstallmentPlan $plan): string
    {
        $stored = (string) (($plan->meta ?? [])[self::META_NONCE] ?? '');

        return $stored !== '' ? $stored : $this->storeNonce($plan, Str::random(self::NONCE_LENGTH));
    }

    private function storeNonce(InstallmentPlan $plan, string $nonce): string
    {
        $meta = (array) ($plan->meta ?? []);
        $meta[self::META_NONCE] = $nonce;
        $plan->forceFill(['meta' => $meta])->save();

        return $nonce;
    }
}
