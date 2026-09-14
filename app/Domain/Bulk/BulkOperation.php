<?php

namespace App\Domain\Bulk;

use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ONE verb a bulk edit can perform on a set of subscriptions.
 *
 * The contract is deliberately narrow, because the runner is the thing that must
 * stay boring: it walks the matched set in id order, hands each chunk to an
 * operation, and commits rows + audit + cursor in one transaction. Everything an
 * operation knows about its own change lives behind these six methods, so adding a
 * verb is one class and a registry line — never a branch in the runner, and never
 * another screen.
 *
 * THE TWO RULES EVERY IMPLEMENTATION OBEYS
 *
 * 1. eligible() is a WALL, not a hint. It must express exactly what the
 *    single-subscription screen would allow, so a bulk edit can never do what the
 *    detail page refuses — a cancelled plan does not get a new charge date in
 *    either place. The runner applies it to the query AND the operation re-reads
 *    each row, because a plan can be cancelled between the count and the chunk.
 *
 * 2. apply() must be safe to run AGAIN on the same chunk. A chunk that is killed
 *    mid-flight rolls back whole (the runner's transaction) and is retried from the
 *    committed cursor, so "shift every date by 7 days" must never shift a row
 *    twice. Rolling back is what buys that; not relying on the row's current value
 *    for anything the operation itself already wrote is what keeps it true.
 */
interface BulkOperation
{
    /** Stable machine key — stored on the run row, so it must never change. */
    public function key(): string;

    /**
     * Validate and normalise the merchant's parameters.
     *
     * @param  array<string, mixed>  $params  raw, from the screen
     * @return array<string, mixed> the canonical stored shape
     *
     * @throws InvalidBulkEdit when the parameters cannot produce a safe change
     */
    public function normalise(array $params): array;

    /**
     * Narrow a query to the subscriptions this operation may legally touch.
     *
     * @param  array<string, mixed>  $params  normalised
     */
    public function eligible(Builder $query, array $params): Builder;

    /** Rows per committed chunk — smaller for per-row work that holds locks. */
    public function chunkSize(): int;

    /**
     * Apply to one loaded chunk. Called INSIDE the runner's transaction.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @param  array<string, mixed>  $params  normalised
     */
    public function apply(Collection $plans, array $params, BulkEditContext $context): BulkOutcome;

    /**
     * One human line naming the change — the receipt's headline.
     *
     * @param  array<string, mixed>  $params  normalised
     */
    public function describe(array $params): string;

    /**
     * What this operation would do to ONE subscription, for the preview table.
     *
     * @param  array<string, mixed>  $params  normalised
     * @return array{before: string, after: string}
     */
    public function preview(InstallmentPlan $plan, array $params): array;
}
