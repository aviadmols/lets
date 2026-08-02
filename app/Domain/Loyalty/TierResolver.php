<?php

namespace App\Domain\Loyalty;

use App\Models\LoyaltyTier;
use Illuminate\Support\Collection;

/**
 * Which tier a lifetime-spend figure has earned, and how far the next one is.
 *
 * The rule is deliberately the simplest one a customer can verify for
 * themselves: the highest tier whose threshold they have passed. No decay, no
 * rolling windows — those are policies a merchant would have to explain, and an
 * unexplainable tier is a support ticket.
 */
final class TierResolver
{
    /** @var Collection<int, LoyaltyTier>|null per-request memo of this shop's ladder */
    private ?Collection $memo = null;

    /** @return Collection<int, LoyaltyTier> lowest threshold first (tenant-scoped) */
    public function ladder(): Collection
    {
        return $this->memo ??= LoyaltyTier::query()->ordered()->get();
    }

    /** The tier this spend has earned, or null when the shop defined none. */
    public function tierFor(float $lifetimeSpend): ?LoyaltyTier
    {
        $earned = null;

        foreach ($this->ladder() as $tier) {
            if ($lifetimeSpend + 0.0001 >= $tier->minSpend()) { // float-safe boundary
                $earned = $tier;
            }
        }

        // No threshold passed yet ⇒ the entry tier when it starts at zero, else
        // no tier at all (the customer is a member but below the first rung).
        if ($earned === null) {
            $first = $this->ladder()->first();

            return $first instanceof LoyaltyTier && $first->minSpend() <= 0 ? $first : null;
        }

        return $earned;
    }

    /** The next tier up, or null when they are at the top. */
    public function nextTierAfter(?LoyaltyTier $current): ?LoyaltyTier
    {
        $threshold = $current?->minSpend() ?? -1.0;

        foreach ($this->ladder() as $tier) {
            if ($tier->minSpend() > $threshold) {
                return $tier;
            }
        }

        return null;
    }

    /** The points multiplier in force at a given spend (1.0 when tier-less). */
    public function multiplierFor(float $lifetimeSpend): float
    {
        return $this->tierFor($lifetimeSpend)?->multiplier() ?? 1.0;
    }

    /** Drop the memo — used after the merchant edits the ladder. */
    public function forget(): void
    {
        $this->memo = null;
    }
}
