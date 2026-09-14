<?php

namespace App\Domain\Bulk\Operations;

use App\Domain\Bulk\BulkEditContext;
use App\Domain\Bulk\BulkOperation;
use App\Domain\Bulk\BulkOutcome;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Base for the bulk edits that write plan COLUMNS — a charge date, a cadence.
 *
 * THE WHOLE POINT IS THE SHAPE OF THE WRITE. A subclass answers one question per
 * subscription ("what should this row's columns become, or nothing?") and this
 * class turns a chunk of those answers into a handful of statements: the rows are
 * GROUPED by the value they are getting, so setting one date on five hundred
 * subscriptions is ONE update, and shifting five hundred different dates by a week
 * is one update per distinct resulting date. Forty thousand subscriptions cost
 * roughly two queries per five hundred rows instead of three per row — which is
 * the difference between a bulk edit that finishes and one that times out.
 *
 * A row already sitting on the requested value is SKIPPED, not written. That is
 * what makes re-running the same bulk edit free, and it is also what makes the
 * "changed" number on the receipt mean something.
 *
 * STATUS IS NOT A COLUMN THIS CLASS MAY WRITE. A query-builder update bypasses the
 * model entirely — the guarded state machine, its legality table and its audit row
 * all sit on the model — so `status` (and `shop_id`) are refused outright. A bulk
 * edit that moves state does it per row through the lifecycle services instead.
 *
 * @see LifecycleEdit for that path.
 */
abstract class ColumnEdit implements BulkOperation
{
    // === CONSTANTS ===
    /**
     * Columns no set-based edit may ever write.
     *
     * `status` because the guarded transition is its only legal path; `shop_id`
     * because tenancy is not editable; `id` because it is not data. Enforced as an
     * exception rather than a comment: a future operation that tries is a bug that
     * must not reach a merchant's database.
     */
    public const FORBIDDEN_COLUMNS = ['status', 'shop_id', 'id'];

    /** Rows per committed chunk. Two statements per chunk, so this can be large. */
    public const CHUNK = 500;

    /**
     * What this subscription's columns should become — or NULL to leave it alone.
     *
     * Returning null is how a subclass says "already correct" or "not applicable",
     * and the row is counted as skipped. It is also the re-run guard: a chunk
     * replayed after a rollback finds the rows it already wrote and returns null
     * for them.
     *
     * @param  array<string, mixed>  $params  normalised
     * @return array<string, mixed>|null column => value
     */
    abstract protected function changesFor(InstallmentPlan $plan, array $params): ?array;

    /**
     * The audit bag for one changed row: field => {from, to}, as the
     * single-subscription edit already writes it, so one Timeline reader serves
     * both paths.
     *
     * @param  array<string, mixed>  $changes  what changesFor() returned
     * @return array<string, array{from: mixed, to: mixed}>
     */
    abstract protected function auditFor(InstallmentPlan $plan, array $changes): array;

    public function chunkSize(): int
    {
        return self::CHUNK;
    }

    /**
     * Only live plans get their billing columns rewritten.
     *
     * A completed or cancelled subscription is finished: giving it a charge date
     * would hand the scheduler a plan nobody agreed to pay again. The same gate the
     * detail page applies with `! status->isTerminal()`.
     */
    public function eligible(Builder $query, array $params): Builder
    {
        return $query->whereNotIn('status', [PlanStatus::COMPLETED->value, PlanStatus::CANCELLED->value]);
    }

    public function apply(Collection $plans, array $params, BulkEditContext $context): BulkOutcome
    {
        /** @var array<string, array{changes: array<string, mixed>, ids: list<int>}> $groups */
        $groups = [];
        $audits = [];
        $skipped = 0;

        foreach ($plans as $plan) {
            $changes = $this->changesFor($plan, $params);

            if ($changes === null || $changes === []) {
                $skipped++;

                continue;
            }

            $this->assertWritable($changes);

            // Group by the VALUE being written, so N rows getting the same value
            // cost one statement. The signature is the JSON of the change bag;
            // dates are already strings by the time they get here.
            $signature = json_encode($changes, JSON_THROW_ON_ERROR);

            $groups[$signature]['changes'] = $changes;
            $groups[$signature]['ids'][] = (int) $plan->getKey();

            $audits[] = [
                'kind' => Timeline::KIND_PLAN_EDITED,
                'plan_id' => (int) $plan->getKey(),
                'details' => $context->stamp(['changed' => $this->auditFor($plan, $changes)]),
            ];
        }

        $now = now();

        foreach ($groups as $group) {
            // Tenant-scoped (the global scope is still on this query) AND keyed to
            // the ids we just read — two walls, so a mis-built change bag cannot
            // reach a row this chunk never looked at.
            InstallmentPlan::query()
                ->whereKey($group['ids'])
                ->update($group['changes'] + ['updated_at' => $now]);
        }

        // Throws on failure, unwinding the runner's transaction with it: the rows
        // above and the audit for them land together or not at all.
        Timeline::recordMany($audits, actor: $context->actor, shopId: $context->shopId);

        return new BulkOutcome(changed: count($audits), skipped: $skipped);
    }

    /** @param array<string, mixed> $changes */
    private function assertWritable(array $changes): void
    {
        foreach (array_keys($changes) as $column) {
            if (in_array($column, self::FORBIDDEN_COLUMNS, true)) {
                throw new \LogicException(sprintf(
                    '%s tried to bulk-write the forbidden column "%s".',
                    static::class,
                    $column,
                ));
            }
        }
    }
}
