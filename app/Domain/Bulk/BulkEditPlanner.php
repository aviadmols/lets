<?php

namespace App\Domain\Bulk;

use App\Models\InstallmentPlan;

/**
 * Counts and shows what a bulk edit would do. WRITES NOTHING.
 *
 * The same split the import screen uses, and for the same reason: a merchant
 * should find out that their filter matches forty thousand subscriptions instead
 * of the four hundred they meant while nothing has happened yet — not afterwards,
 * with forty thousand charge dates moved and no record of what they used to be.
 *
 * It is the only thing on the screen before the run, and it is built from the SAME
 * criteria object and the SAME operation the worker will use, so the number the
 * merchant confirms is the number the worker will walk. A preview computed a
 * different way would be a second implementation of the target set, and the two
 * would drift.
 */
final class BulkEditPlanner
{
    // === CONSTANTS ===
    /**
     * Rows shown as before → after.
     *
     * Ten, because the sample is there to catch a misread parameter ("I meant
     * October, that says November") and ten rows catch that as well as a thousand
     * would — while a thousand rows of preview is a page nobody scrolls and a
     * query nobody needed.
     */
    public const SAMPLE = 10;

    /** Longest customer/product label in the sample table before it is trimmed. */
    public const LABEL_MAX = 60;

    /**
     * @param  array<string, mixed>  $params  raw; normalised here so the preview is
     *                                        computed from exactly what would run
     *
     * @throws InvalidBulkEdit when the parameters could not produce a safe change
     */
    public function plan(SubscriptionCriteria $criteria, BulkOperation $operation, array $params): BulkEditPlan
    {
        $normalised = $operation->normalise($params);

        // Two counts off the same criteria: everything it found, and the subset the
        // verb may act on. Both are tenant-scoped by InstallmentPlan's global scope.
        $matched = $criteria->apply()->count();
        $eligible = $operation->eligible($criteria->apply(), $normalised)->count();

        return new BulkEditPlan(
            matched: $matched,
            eligible: $eligible,
            sample: $this->sample($criteria, $operation, $normalised),
            summary: $operation->describe($normalised),
            criteriaLines: $criteria->describe(),
            unfiltered: $criteria->isUnfiltered(),
        );
    }

    /**
     * The first few subscriptions the run would reach, in the order it will reach
     * them (id ascending — the runner's cursor order), each with the change spelled
     * out.
     *
     * @param  array<string, mixed>  $normalised
     * @return list<array{customer: string, product: string, status: string, before: string, after: string}>
     */
    private function sample(SubscriptionCriteria $criteria, BulkOperation $operation, array $normalised): array
    {
        $plans = $operation->eligible($criteria->apply(), $normalised)
            // Eager-loaded because productTitle() falls back to the synced catalog
            // when a plan's own meta carries no title — without this the sample is
            // one extra query per row.
            ->with('product')
            ->orderBy('id')
            ->limit(self::SAMPLE)
            ->get();

        return $plans->map(function (InstallmentPlan $plan) use ($operation, $normalised): array {
            $change = $operation->preview($plan, $normalised);

            return [
                'customer' => $this->trim($plan->customerLabel()),
                'product' => $this->trim((string) ($plan->productTitle() ?? '')),
                'status' => __('billing.status.'.($plan->status?->value ?? '')),
                'before' => (string) $change['before'],
                'after' => (string) $change['after'],
            ];
        })->all();
    }

    private function trim(string $value): string
    {
        $clean = trim($value);

        if ($clean === '') {
            return '—';
        }

        return mb_strlen($clean) > self::LABEL_MAX
            ? mb_substr($clean, 0, self::LABEL_MAX).'…'
            : $clean;
    }
}
