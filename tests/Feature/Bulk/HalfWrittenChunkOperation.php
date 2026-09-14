<?php

namespace Tests\Feature\Bulk;

use App\Domain\Bulk\BulkEditContext;
use App\Domain\Bulk\BulkOperation;
use App\Domain\Bulk\BulkOutcome;
use App\Domain\Bulk\Operations\SetNextChargeDate;
use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A date operation that writes its chunk and then dies.
 *
 * It exists to prove the one property the whole design rests on: the runner's
 * transaction. A chunk that half-landed and was then interrupted must leave NO
 * trace — no moved dates, no audit rows, and a cursor that has not advanced — or a
 * retry would redo work that had already happened, which for a shift means
 * shifting twice.
 *
 * A DECORATOR rather than a subclass, because the real operations are final and a
 * test is not a reason to open them. Every method delegates, so the behaviour under
 * test is the production behaviour; only the ending is different. The test binds it
 * over SetNextChargeDate in the container, so the registry hands the runner this
 * without the production whitelist knowing it exists.
 */
final class HalfWrittenChunkOperation implements BulkOperation
{
    // === CONSTANTS ===
    public const MESSAGE = 'worker died mid-chunk';

    public function __construct(private readonly SetNextChargeDate $inner = new SetNextChargeDate) {}

    public function apply(Collection $plans, array $params, BulkEditContext $context): BulkOutcome
    {
        // The rows and their audit land first — exactly as a real chunk would —
        // and THEN the process falls over.
        $this->inner->apply($plans, $params, $context);

        throw new RuntimeException(self::MESSAGE);
    }

    public function key(): string
    {
        return $this->inner->key();
    }

    public function normalise(array $params): array
    {
        return $this->inner->normalise($params);
    }

    public function eligible(Builder $query, array $params): Builder
    {
        return $this->inner->eligible($query, $params);
    }

    public function chunkSize(): int
    {
        return $this->inner->chunkSize();
    }

    public function describe(array $params): string
    {
        return $this->inner->describe($params);
    }

    public function preview(InstallmentPlan $plan, array $params): array
    {
        return $this->inner->preview($plan, $params);
    }
}
