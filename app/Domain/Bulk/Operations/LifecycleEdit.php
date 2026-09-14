<?php

namespace App\Domain\Bulk\Operations;

use App\Domain\Bulk\BulkEditContext;
use App\Domain\Bulk\BulkOperation;
use App\Domain\Bulk\BulkOutcome;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Exceptions\IllegalTransitionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for the bulk edits that MOVE STATE — pause, resume.
 *
 * These are the operations that cannot be a set-based UPDATE, and the reason is
 * the whole reason this class is separate from ColumnEdit: `status` has a guarded
 * state machine, the machine lives on the MODEL, and a query-builder update goes
 * straight past it. So each row here is moved one at a time, through the same
 * SubscriptionLifecycleService the detail page calls — row-locked, legality-checked,
 * and audited by transitionTo() itself.
 *
 * It is slower per row, and it is supposed to be. Pausing four thousand
 * subscriptions is four thousand legal transitions, not one statement; the price is
 * that no bulk edit can ever put a subscription into a state its own machine
 * forbids, and every one of them carries its own Timeline row naming the run.
 *
 * A row that is no longer in a legal source state is SKIPPED, not forced. The
 * eligibility query already excluded it, but a plan can be cancelled by a customer
 * between the count and the chunk, and losing that race must mean "left alone" —
 * never an exception that fails the whole chunk, and never a resurrection.
 */
abstract class LifecycleEdit implements BulkOperation
{
    // === CONSTANTS ===
    /**
     * Rows per committed chunk — deliberately a fifth of ColumnEdit's.
     *
     * Each row opens a transaction and holds a row lock, all inside the runner's
     * own transaction, so the chunk is a long-held lock set. Smaller chunks commit
     * sooner, which keeps the charge scheduler's own `lockForUpdate` on a plan
     * waiting for milliseconds rather than seconds.
     */
    public const CHUNK = 100;

    /**
     * The reason string written into each transition's audit context. Machine
     * shaped on purpose: it is greppable across activity_events, and it names the
     * run rather than paraphrasing a merchant's intent.
     */
    public const REASON_PREFIX = 'bulk_edit:';

    public function __construct(protected readonly SubscriptionLifecycleService $lifecycle) {}

    /** The statuses this operation may legally move OUT of. @return list<string> */
    abstract protected function sourceStatuses(): array;

    /** Perform the one-row move. Throws IllegalTransitionException on a lost race. */
    abstract protected function move(InstallmentPlan $plan, string $reason): void;

    public function chunkSize(): int
    {
        return self::CHUNK;
    }

    public function eligible(Builder $query, array $params): Builder
    {
        return $query->whereIn('status', $this->sourceStatuses());
    }

    /** Nothing to configure: the verb IS the parameter. */
    public function normalise(array $params): array
    {
        return [];
    }

    public function apply(Collection $plans, array $params, BulkEditContext $context): BulkOutcome
    {
        $reason = self::REASON_PREFIX.$context->runId;

        $changed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($plans as $plan) {
            // Re-read the status we hold rather than trusting the query that found
            // it: between the count and this line a customer may have cancelled.
            if (! in_array($plan->status?->value, $this->sourceStatuses(), true)) {
                $skipped++;

                continue;
            }

            try {
                $this->move($plan, $reason);
                $changed++;
            } catch (IllegalTransitionException) {
                // The row moved under us. Left alone, counted, and not an error.
                $skipped++;
            } catch (Throwable $e) {
                $failed++;

                Log::warning('bulk_edit.lifecycle_row_failed', [
                    'bulk_edit_id' => $context->runId,
                    'shop_id' => $context->shopId,
                    'plan_id' => $plan->getKey(),
                    'operation' => $this->key(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return new BulkOutcome(changed: $changed, skipped: $skipped, failed: $failed);
    }

    /** The before/after for the preview, read off the canonical status catalogue. */
    public function preview(InstallmentPlan $plan, array $params): array
    {
        return [
            'before' => __('billing.status.'.($plan->status?->value ?? '')),
            'after' => __('billing.status.'.$this->targetStatus()->value),
        ];
    }

    abstract protected function targetStatus(): PlanStatus;
}
