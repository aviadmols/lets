<?php

namespace App\Console\Commands;

use App\Domain\Billing\ConsentCeiling;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Record a MERCHANT-ATTESTED consent for every live subscription that has none.
 *
 * The charge engine charges no saved card without a stored consent. Some plans
 * reached a card without one (a merchant rescuing an abandoned checkout through
 * a card-update link, before that page asked for agreement). The merchant
 * states that these customers did agree, and this records that statement.
 *
 * WHAT IT WRITES IS WHAT HAPPENED. ceiling_source = merchant_attested, with who
 * attested (approved_by) and why (approval_reason). It is never presented as the
 * customer's own click.
 *
 * IT MOVES MONEY. A plan with a card whose date has passed is charged by the
 * next scheduler run, minutes after this commits. So it shows the list first,
 * marking every plan that will be charged and for how much, and writes nothing
 * without --commit.
 */
final class AttestMissingConsents extends Command
{
    // === CONSTANTS ===
    protected $signature = 'lets:consents:attest-missing
        {--shop= : the shop id}
        {--reason= : why the merchant attests these consents (required with --commit)}
        {--by= : who attests and who ran it, recorded as approved_by (required with --commit)}
        {--with-card-only : only plans that hold a saved card}
        {--chargeable-only : only plans in a chargeable status (not paused, failed or awaiting activation)}
        {--only= : only these plan ids (comma-separated)}
        {--commit : actually record them (default is a preview)}';

    protected $description = 'Record merchant-attested consents for live subscriptions that have none';

    /** Statuses whose plans will never be charged again. */
    private const ENDED = [PlanStatus::CANCELLED, PlanStatus::COMPLETED, PlanStatus::DRAFT];

    /** Terms version stamped on an attested row, before the shop's own version. */
    private const VERSION_PREFIX = 'attested';

    /** The scheduler's early window, for "charged right away". */
    private const SOON_HOURS = 1;

    public function handle(): int
    {
        $shop = Shop::query()->find((int) $this->option('shop'));

        if ($shop === null) {
            $this->error('Pass --shop=<id> of an existing shop.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $reason = trim((string) $this->option('reason'));

        $by = trim((string) $this->option('by'));

        if ($commit && ($reason === '' || $by === '')) {
            $this->error('--commit needs --reason and --by: the attestation must say why, and who stands behind it.');

            return self::FAILURE;
        }

        return Tenant::run($shop, function () use ($commit, $reason, $by): int {
            $plans = $this->plansWithoutConsent();

            if ($plans->isEmpty()) {
                $this->info('Every live subscription in this shop has a stored consent.');

                return self::SUCCESS;
            }

            $this->table(
                ['Plan', 'Status', 'Customer', 'Email', 'Card', 'Next charge', 'Amount', 'Charged right away?'],
                $plans->map(fn (InstallmentPlan $p): array => [
                    $p->getKey(),
                    $p->status instanceof PlanStatus ? $p->status->value : (string) $p->status,
                    (string) $p->customer_name,
                    (string) $p->customer_email,
                    $p->payment_method_id !== null ? 'yes' : 'no',
                    $p->next_charge_at?->format('Y-m-d H:i') ?? '—',
                    $p->no_charge ? 'free' : number_format(ConsentCeiling::nextAmount($p), 2),
                    $this->chargedRightAway($p) ? 'YES' : '',
                ])->all(),
            );

            $soon = $plans->filter(fn (InstallmentPlan $p): bool => $this->chargedRightAway($p));
            $this->line(sprintf(
                '%d subscription(s) without consent; %d will be CHARGED by the next scheduler run once this commits.',
                $plans->count(),
                $soon->count(),
            ));

            if (! $commit) {
                $this->comment('Preview only. Nothing was written. Re-run with --commit --reason="..." to record them.');

                return self::SUCCESS;
            }

            $plans->each(fn (InstallmentPlan $p) => $this->attest($p, $reason, $by));

            $this->info(sprintf('Recorded %d merchant-attested consent(s).', $plans->count()));

            return self::SUCCESS;
        });
    }

    /** @return Collection<int, InstallmentPlan> */
    private function plansWithoutConsent(): Collection
    {
        $ceiling = app(ConsentCeiling::class);
        $only = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('only')))));

        return InstallmentPlan::query()
            ->whereNotIn('status', array_map(static fn (PlanStatus $s): string => $s->value, self::ENDED))
            ->when($only !== [], fn ($q) => $q->whereKey($only))
            ->when((bool) $this->option('with-card-only'), fn ($q) => $q->whereNotNull('payment_method_id'))
            ->when((bool) $this->option('chargeable-only'), fn ($q) => $q->whereIn('status', array_map(
                static fn ($s): string => $s instanceof PlanStatus ? $s->value : (string) $s,
                PlanStatus::chargeable(),
            )))
            ->orderBy('id')
            ->get()
            ->reject(fn (InstallmentPlan $p): bool => $ceiling->hasConsent($p))
            ->values();
    }

    /** Will the scheduler charge this plan as soon as it has a consent? */
    private function chargedRightAway(InstallmentPlan $plan): bool
    {
        $chargeable = array_map(static fn ($s): string => $s instanceof PlanStatus ? $s->value : (string) $s, PlanStatus::chargeable());
        $status = $plan->status instanceof PlanStatus ? $plan->status->value : (string) $plan->status;

        return ! $plan->no_charge
            && $plan->payment_method_id !== null
            && in_array($status, $chargeable, true)
            && $plan->next_charge_at !== null
            && $plan->next_charge_at->lte(now()->addHours(self::SOON_HOURS));
    }

    private function attest(InstallmentPlan $plan, string $reason, string $actor): void
    {
        $settings = MerchantBillingSettings::current();

        // The status quo as it stands, a next order already queued included
        // (BASELINE), but labelled for what it is.
        $terms = array_merge(
            ConsentCeiling::termsFor($plan, ConsentCeiling::SOURCE_BASELINE),
            ['ceiling_source' => ConsentCeiling::SOURCE_MERCHANT_ATTESTED],
        );

        CustomerConsent::query()->create([
            'shop_id' => (int) $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'consent_context' => ConsentCeiling::contextFor($plan),
            'customer_id' => $plan->customer_id,
            'shopify_customer_id' => $plan->shopify_customer_id,
            'customer_email' => $plan->customer_email,
            // NULL on purpose: the customer accepted nothing on a screen at a
            // moment we know. Who attested, and why, are approved_by and
            // approval_reason; when, the row's own created_at.
            'accepted_at' => null,
            'accepted_terms_version' => self::VERSION_PREFIX.':'.$settings->termsVersion(),
            'cancellation_policy_snapshot' => $settings->cancellationPolicyText(),
            'billing_amount_description' => sprintf('%.2f %s per billing cycle', $terms['consented_amount'], (string) $plan->currency),
            'billing_frequency_description' => (string) ($plan->billing_frequency?->value ?? ''),
            'approved_by' => $actor,
            'approval_reason' => mb_substr($reason, 0, 1000),
        ] + $terms);

        Timeline::record(
            kind: Timeline::KIND_CONSENT_RECORDED,
            details: [
                'source' => ConsentCeiling::SOURCE_MERCHANT_ATTESTED,
                'amount' => $terms['consented_amount'],
                'frequency' => $terms['consented_frequency'],
                'reason' => mb_substr($reason, 0, 1000),
            ],
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_SYSTEM,
            shopId: (int) $plan->shop_id,
        );
    }
}
