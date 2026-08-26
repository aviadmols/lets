<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * `$productIds` are LOCAL Product ids narrowing the rule to the subscribers of
     * those products; empty means every product. `$emails` narrows further to the
     * SPECIFIC people named (matched on the same lowercased email every other
     * block trusts); empty means everyone the rule reaches. When a campaign is
     * given and neither is passed, the campaign's own choices apply — so the
     * preview, the generator and the "would receive now" count can never disagree
     * about who a saved campaign is for.
     *
     * @param  array<int, int>  $productIds
     * @param  array<int, string>  $emails
     * @return Collection<int, array{
     *     source_type: string, source_id: int, label: string, email: ?string,
     *     rail: string, cycles: int, already_gifted: bool
     * }>
     */
    public function qualifying(int $minCycles, ?GiftCampaign $campaign = null, array $productIds = [], array $emails = []): Collection
    {
        $minCycles = max(self::MIN_THRESHOLD, $minCycles);

        if ($productIds === [] && $campaign !== null) {
            $productIds = $campaign->sourceProductIds();
        }

        if ($emails === [] && $campaign !== null) {
            $emails = $campaign->sourceEmails();
        }

        $emails = array_values(array_filter(array_map(
            static fn ($email): string => mb_strtolower(trim((string) $email)),
            $emails,
        ), static fn (string $email): bool => $email !== ''));

        $externalIds = $this->externalIds($productIds);

        // The merchant picked products, and not one of them is in this shop's
        // catalog. Matching nothing is the honest answer — matching EVERYONE would
        // gift the whole subscriber base off a filter that silently evaporated.
        if ($productIds !== [] && $externalIds === []) {
            return collect();
        }

        $rows = $this->fromPlans($minCycles, $externalIds)
            ->concat($this->fromContracts($minCycles, $externalIds));

        // The named-people filter. A row with NO email cannot be one of the
        // people the merchant named, so it is excluded — the opposite of the
        // unfiltered case, where anonymity keeps a row in.
        if ($emails !== []) {
            $rows = $rows->filter(static fn (array $row): bool => $row['email'] !== null
                && in_array(mb_strtolower($row['email']), $emails, true))->values();
        }

        $rows = $this->dedupeByEmail($rows);

        if ($campaign !== null) {
            $rows = $this->flagAlreadyGifted($rows, $campaign);
        }

        return $rows->sortByDesc('cycles')->values();
    }

    /**
     * The catalog products' platform ids. Tenant-scoped, so an id belonging to
     * another shop resolves to nothing rather than to their product.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    private function externalIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereKey($productIds)
            ->pluck('external_id')
            ->map(static fn ($id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    /**
     * The PayPlus rail. `whereHas(..., '>=', $n)` rather than withCount + having:
     * Postgres cannot reference a SELECT alias in HAVING, and production is
     * Postgres. The extra withCount is display only.
     *
     * @param  array<int, string>  $externalIds  empty = every product
     * @return Collection<int, array<string, mixed>>
     */
    private function fromPlans(int $minCycles, array $externalIds): Collection
    {
        return InstallmentPlan::query()
            ->when($externalIds !== [], fn ($q) => $q->where(function ($q) use ($externalIds): void {
                // One column per platform: WooCommerce fills external_product_id,
                // the Shopify-via-PayPlus rail fills shopify_product_id. Grouped so
                // the OR cannot escape and widen the cycle threshold beside it.
                $q->whereIn('external_product_id', $externalIds)
                    ->orWhereIn('shopify_product_id', $externalIds);
            }))
            ->where('plan_kind', PlanKind::RECURRING->value)
            // Gifts reward CURRENT subscribers: someone who cancelled last year met
            // the threshold once, but they are not who this campaign is thanking.
            ->where('status', PlanStatus::ACTIVE->value)
            ->withCount(['payments as succeeded_cycles' => fn ($q) => $q->where('status', PaymentStatus::SUCCEEDED->value)])
            ->orderBy('id')
            ->get()
            // The threshold is applied HERE, not as a whereHas, because a migrated
            // member's paid cycles are not rows in our payments table — they are the
            // count their old system reported, kept in meta (see importedCycles()).
            // A whereHas alone answered "zero" for every imported subscriber, so a
            // campaign asking for one paid cycle matched none of the 1,154 members a
            // store had just brought in. Their loyalty predates us; it still counts.
            ->map(fn (InstallmentPlan $plan): array => [
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => (int) $plan->getKey(),
                'label' => $plan->customerLabel(),
                'email' => $this->clean($plan->customer_email),
                'rail' => 'payplus',
                'cycles' => (int) $plan->succeeded_cycles + $plan->importedCycles(),
                'already_gifted' => false,
            ])
            ->filter(static fn (array $row): bool => $row['cycles'] >= $minCycles)
            ->values();
    }

    /**
     * The Shopify-Payments rail. A cycle counts only when Shopify told us the
     * attempt SUCCEEDED — an attempt whose transport died stays `requested` and is
     * deliberately not counted as a paid cycle.
     *
     * The product filter is applied in PHP against the mirrored lines: `lines` is a
     * JSON column whose product ids sit inside an array of objects, and every
     * database this runs on spells that query differently. The set is already
     * loaded, so this costs nothing extra.
     *
     * @param  array<int, string>  $externalIds  empty = every product
     * @return Collection<int, array<string, mixed>>
     */
    private function fromContracts(int $minCycles, array $externalIds): Collection
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
            ->filter(fn (SubscriptionContract $contract): bool => $this->contractSells($contract, $externalIds))
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
     * Does this contract renew one of the chosen products?
     *
     * Contracts mirrored before the line query carried product ids have none, and
     * they are EXCLUDED rather than assumed to match: a merchant who narrowed the
     * campaign to one product must not be handed subscribers we cannot place. Re-run
     * `subscriptions:backfill-contracts` and they come back.
     *
     * @param  array<int, string>  $externalIds  empty = every product
     */
    private function contractSells(SubscriptionContract $contract, array $externalIds): bool
    {
        if ($externalIds === []) {
            return true;
        }

        foreach ((array) ($contract->lines ?? []) as $line) {
            $gid = (string) (is_array($line) ? ($line['product_id'] ?? '') : '');
            if ($gid === '') {
                continue;
            }

            // Stored as a gid ("gid://shopify/Product/123"); the catalog holds the
            // bare numeric id.
            $numeric = str_contains($gid, '/') ? (string) Str::afterLast($gid, '/') : $gid;

            if (in_array($numeric, $externalIds, true) || in_array($gid, $externalIds, true)) {
                return true;
            }
        }

        return false;
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
