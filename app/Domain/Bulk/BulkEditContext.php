<?php

namespace App\Domain\Bulk;

use App\Domain\Bulk\Models\BulkSubscriptionEdit;

/**
 * The three facts an operation needs about the run it is part of, and nothing else.
 *
 * A readonly context rather than the run row itself, for one reason: an operation
 * must not be able to write to the run. The runner owns the counters, the cursor
 * and the status — an operation that could touch them could report its own success,
 * which is exactly the thing a receipt exists to prevent.
 *
 * `actor` is frozen on the run row at request time and carried here because the
 * worker cannot resolve it: by the time a chunk is committed the request that
 * asked for it is long gone, and PlatformContext would honestly answer "system".
 * A mass edit attributed to "system" would hide the person who asked.
 */
final class BulkEditContext
{
    // === CONSTANTS ===
    /** Details key stamped into every audit row a bulk edit writes. */
    public const AUDIT_KEY = 'bulk_edit_id';

    public function __construct(
        public readonly int $runId,
        public readonly int $shopId,
        public readonly ?string $actor = null,
    ) {}

    public static function for(BulkSubscriptionEdit $run): self
    {
        return new self(
            runId: (int) $run->getKey(),
            shopId: (int) $run->shop_id,
            actor: $run->actor,
        );
    }

    /**
     * A details bag with this run stamped into it, so every row a bulk edit
     * touched can be traced back to the one click that asked for it.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function stamp(array $details): array
    {
        return $details + [self::AUDIT_KEY => $this->runId];
    }
}
