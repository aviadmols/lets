<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Support\Collection;

/**
 * Who has earned a gift: every ACTIVE subscriber with at least N successfully
 * charged cycles, across BOTH rails.
 *
 * "Charged cycles" is deliberately the count of SUCCEEDED money movements, not the
 * subscription's age and not its sequence number. A failed attempt is not a cycle
 * the customer paid for, and a plan that has been retried three times has not
 * earned three gifts' worth of loyalty.
 *
 * Tenant-bound by the models' BelongsToShop scope — this class never names a
 * shop_id, and the caller (the admin page) is what binds the tenant.
 */
final class GiftEligibility
{
    // === CONSTANTS ===
    /** Below this the campaign would gift a first-time buyer; the UI enforces it too. */
    public const MIN_THRESHOLD = 1;

    /**
     * Everyone who qualifies, as display rows.
     *
     * @return Collection<int, array{
     *     source_type: string, source_id: int, label: string, email: ?string,
     *     rail: string, cycles: int, already_gifted: bool
     * }>
     */
    public function qualifying(int $minCycles, ?GiftCampaign $campaign = null): Collection
    {
        $minCycles = max(self::MIN_THRESHOLD, $minCycles);

        $rows = $this->fromPlans($minCycles)->concat($this->fromContracts($minCycles));

        $rows = $this->dedupeByEmail($rows);

        if ($campaign !== null) {
            $rows = $this->flagAlreadyGifted($rows, $campaign);
        }

        return $rows->sortByDesc('cycles')->values();
    }

    /**
     * The PayPlus rail. `whereHas(..., '>=', $n)` rather than withCount + having:
     * Postgres cannot reference a SELECT alias in HAVING, and production is
     * Postgres. The extra withCount is display only.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fromPlans(int $minCycles): Collection
    {
        return InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            // Gifts reward CURRENT subscribers: someone who cancelled last year met
            // the threshold once, but they are not who this campaign is thanking.
            ->where('status', PlanStatus::ACTIVE->value)
            ->whereHas(
                'payments',
                fn ($q) => $q->where('status', PaymentStatus::SUCCEEDED->value),
                '>=',
                $minCycles,
            )
            ->withCount(['payments as succeeded_cycles' => fn ($q) => $q->where('status', PaymentStatus::SUCCEEDED->value)])
            ->orderBy('id')
            ->get()
            ->map(fn (InstallmentPlan $plan): array => [
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => (int) $plan->getKey(),
                'label' => $plan->customerLabel(),
                'email' => $this->clean($plan->customer_email),
                'rail' => 'payplus',
                'cycles' => (int) $plan->succeeded_cycles,
                'already_gifted' => false,
            ]);
    }

    /**
     * The Shopify-Payments rail. A cycle counts only when Shopify told us the
     * attempt SUCCEEDED — an attempt whose transport died stays `requested` and is
     * deliberately not counted as a paid cycle.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fromContracts(int $minCycles): Collection
    {
        return SubscriptionContract::query()
            ->where('status', SubscriptionContract::STATUS_ACTIVE)
            ->whereHas(
                'billingAttempts',
                fn ($q) => $q->where('status', SubscriptionBillingAttempt::STATUS_SUCCEEDED),
                '>=',
                $minCycles,
            )
            ->withCount(['billingAttempts as succeeded_cycles' => fn ($q) => $q->where('status', SubscriptionBillingAttempt::STATUS_SUCCEEDED)])
            ->orderBy('id')
            ->get()
            ->map(fn (SubscriptionContract $contract): array => [
                'source_type' => GiftRecipient::SOURCE_CONTRACT,
                'source_id' => (int) $contract->getKey(),
                'label' => (string) ($contract->customer_name
                    ?: $contract->customer_email
                    ?: __('common.none')),
                'email' => $this->clean($contract->customer_email),
                'rail' => 'shopify',
                'cycles' => (int) $contract->succeeded_cycles,
                'already_gifted' => false,
            ]);
    }

    /**
     * One gift per PERSON, not per subscription. A customer with two subscriptions
     * that both qualify is one human who should receive one package; the oldest
     * subscription wins so the choice is deterministic across previews.
     *
     * Rows without an email cannot be matched to anyone and stay as they are —
     * guessing that two anonymous subscriptions are the same person would silently
     * deny someone their gift.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function dedupeByEmail(Collection $rows): Collection
    {
        $seen = [];

        return $rows->filter(function (array $row) use (&$seen): bool {
            $email = $row['email'] !== null ? mb_strtolower($row['email']) : null;
            if ($email === null) {
                return true;
            }
            if (isset($seen[$email])) {
                return false;
            }
            $seen[$email] = true;

            return true;
        })->values();
    }

    /**
     * Mark rows this campaign has already enrolled, so the preview can say
     * "already gifted" instead of quietly promising a second package.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function flagAlreadyGifted(Collection $rows, GiftCampaign $campaign): Collection
    {
        $existing = GiftRecipient::query()
            ->where('gift_campaign_id', $campaign->getKey())
            ->get(['source_type', 'source_id'])
            ->map(fn (GiftRecipient $r): string => $r->source_type.':'.$r->source_id)
            ->flip();

        return $rows->map(function (array $row) use ($existing): array {
            $row['already_gifted'] = $existing->has($row['source_type'].':'.$row['source_id']);

            return $row;
        });
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
