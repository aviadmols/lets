<?php

namespace App\Domain\Bulk;

/**
 * What a bulk edit WOULD do — the answer the merchant confirms, computed without
 * writing anything.
 *
 * Two counts, kept separate, because their difference is the most useful number on
 * the screen. MATCHED is how many subscriptions the filter found; ELIGIBLE is how
 * many of those the chosen verb may legally touch. "4,120 match · 3,980 can be
 * changed" tells a merchant that a hundred and forty of them are cancelled or on
 * the wrong plan kind, before they click — where a single number would have let
 * them discover it afterwards, in the counters, with no way to tell which hundred
 * and forty.
 *
 * The ELIGIBLE count is the one the confirmation is taken against, because it is
 * the one that describes work about to happen.
 */
final class BulkEditPlan
{
    // === CONSTANTS ===
    /** An empty plan — nothing matched, nothing to confirm. */
    public const NONE = 0;

    /**
     * @param  list<array{customer: string, product: string, status: string, before: string, after: string}>  $sample
     * @param  array<string, string>  $criteriaLines  label key => value
     */
    public function __construct(
        public readonly int $matched,
        public readonly int $eligible,
        public readonly array $sample,
        public readonly string $summary,
        public readonly array $criteriaLines,
        public readonly bool $unfiltered,
    ) {}

    /** Nothing the verb can act on → the screen must not offer to run it. */
    public function isEmpty(): bool
    {
        return $this->eligible <= self::NONE;
    }

    /** Matched but not eligible — worth saying out loud, not hiding. */
    public function ineligible(): int
    {
        return max(0, $this->matched - $this->eligible);
    }
}
