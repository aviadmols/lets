<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Support\Frequency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The filter chips of an Analytics screen, as one immutable value: products,
 * selling plans, frequencies. Read from the URL (?f[products][]=…), sanitised
 * here, applied by every query class through applyToPlans()/applyToContracts()
 * so "filtered by Book club" means the same rows on every card of a screen.
 *
 * COUNTRY is deliberately absent: LETS serves Israeli merchants, and no table
 * stores a customer country we could filter by (docs/analytics/data-map.md).
 * The chip renders disabled with that explanation instead of filtering on a
 * guess.
 *
 * Contracts (the Shopify-Payments rail) carry no selling-plan or product link
 * we key on, so a product or selling-plan filter EXCLUDES them rather than
 * pretending they match; a frequency filter applies to both rails.
 */
final class Filters
{
    // === CONSTANTS ===
    public const PRODUCTS = 'products';

    public const PLANS = 'plans';

    public const FREQUENCIES = 'frequencies';

    public const COUNTRY = 'country';

    /** Dimensions a screen may offer as chips, in chip order. */
    public const DIMENSIONS = [self::COUNTRY, self::PRODUCTS, self::PLANS, self::FREQUENCIES];

    /** Dimensions that actually filter (country is shown, never applied). */
    public const APPLIED = [self::PRODUCTS, self::PLANS, self::FREQUENCIES];

    /** No more values per chip than this — a URL is not a list of every id. */
    public const MAX_VALUES = 50;

    /** @param array<string, list<string>> $values */
    private function __construct(private readonly array $values) {}

    public static function none(): self
    {
        return new self([]);
    }

    /** @param mixed $input the raw ?f[...] query value */
    public static function fromInput(mixed $input): self
    {
        $input = is_array($input) ? $input : [];
        $values = [];

        foreach (self::APPLIED as $dimension) {
            $raw = $input[$dimension] ?? [];
            $raw = is_array($raw) ? $raw : [$raw];
            $clean = [];
            foreach ($raw as $value) {
                $value = trim((string) $value);
                if ($value === '' || strlen($value) > 64) {
                    continue;
                }
                if ($dimension === self::PLANS && ! ctype_digit($value)) {
                    continue;
                }
                if ($dimension === self::FREQUENCIES && ! Frequency::isValid($value)) {
                    continue;
                }
                $clean[$value] = $value;
            }
            if ($clean !== []) {
                $list = array_values($clean);
                sort($list);
                $values[$dimension] = array_slice($list, 0, self::MAX_VALUES);
            }
        }

        return new self($values);
    }

    /** @return list<string> */
    public function get(string $dimension): array
    {
        return $this->values[$dimension] ?? [];
    }

    public function has(string $dimension): bool
    {
        return $this->get($dimension) !== [];
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** @return array<string, list<string>> the URL shape */
    public function toArray(): array
    {
        return $this->values;
    }

    /** Stable cache key fragment. */
    public function key(): string
    {
        return $this->values === [] ? 'all' : substr(sha1((string) json_encode($this->values)), 0, 12);
    }

    /** False when a product/plan filter is set — contracts cannot match those. */
    public function includesContracts(): bool
    {
        return ! $this->has(self::PRODUCTS) && ! $this->has(self::PLANS);
    }

    /** Narrow an installment_plans query (Eloquent or base) to the chips. */
    public function applyToPlans(Builder|QueryBuilder $query, string $table = 'installment_plans'): Builder|QueryBuilder
    {
        if ($this->has(self::PRODUCTS)) {
            $ids = $this->get(self::PRODUCTS);
            $query->where(fn ($q) => $q
                ->whereIn("{$table}.external_product_id", $ids)
                ->orWhereIn("{$table}.shopify_product_id", $ids));
        }
        if ($this->has(self::PLANS)) {
            $query->whereIn("{$table}.product_subscription_plan_id", array_map('intval', $this->get(self::PLANS)));
        }
        if ($this->has(self::FREQUENCIES)) {
            $query->where(function ($q) use ($table): void {
                $matched = false;
                foreach ($this->get(self::FREQUENCIES) as $key) {
                    foreach (Frequency::planPairs($key) as [$frequency, $count]) {
                        $matched = true;
                        $q->orWhere(fn ($w) => $w
                            ->where("{$table}.billing_frequency", $frequency)
                            ->where(fn ($c) => $count === 1
                                ? $c->whereNull("{$table}.interval_count")->orWhere("{$table}.interval_count", '<=', 1)
                                : $c->where("{$table}.interval_count", $count)));
                    }
                }
                if (! $matched) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        return $query;
    }

    /**
     * Narrow a subscription_contracts query. Callers must check
     * includesContracts() first — this only applies the frequency chip.
     */
    public function applyToContracts(Builder|QueryBuilder $query, string $table = 'subscription_contracts'): Builder|QueryBuilder
    {
        if ($this->has(self::FREQUENCIES)) {
            $query->where(function ($q) use ($table): void {
                $matched = false;
                foreach ($this->get(self::FREQUENCIES) as $key) {
                    foreach (Frequency::contractPairs($key) as [$interval, $count]) {
                        $matched = true;
                        $q->orWhere(fn ($w) => $w
                            ->whereRaw("UPPER({$table}.\"interval\") = ?", [$interval])
                            ->where(fn ($c) => $count === 1
                                ? $c->whereNull("{$table}.interval_count")->orWhere("{$table}.interval_count", '<=', 1)
                                : $c->where("{$table}.interval_count", $count)));
                    }
                }
                if (! $matched) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        return $query;
    }
}
