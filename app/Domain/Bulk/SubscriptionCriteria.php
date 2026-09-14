<?php

namespace App\Domain\Bulk;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WHICH subscriptions a bulk edit is aimed at — a serialisable filter, not a list
 * of ids.
 *
 * This is the hinge the whole feature turns on. A bulk edit over forty thousand
 * subscriptions cannot carry its target set in a job payload, and it must not
 * re-derive it from a screen's transient table state either. So the merchant's
 * filter is captured HERE, stored on the run row verbatim, and re-applied — by the
 * preview that counts, by the sample that shows, and by every chunk the worker
 * commits. One definition of "the target", read by all three, which is the only way
 * the number a merchant confirms can be the number that changes.
 *
 * Every field is OPTIONAL and every set field NARROWS. An all-null criteria means
 * "every subscription in this shop", which is a legitimate thing to ask for and is
 * why the screen makes the merchant type the count before it runs.
 *
 * TENANT SAFETY: apply() builds on InstallmentPlan::query(), so the BelongsToShop
 * global scope is the wall — with no tenant bound the query returns nothing rather
 * than everything, and there is no withoutGlobalScope() anywhere in this module.
 *
 * @see BulkOperation for the second half of the target: what an operation may act on.
 */
final class SubscriptionCriteria
{
    // === CONSTANTS ===
    /** Criteria keys, in the order the screen renders them. Also the JSON shape. */
    public const KEYS = [
        'plan_kind', 'statuses', 'external_product_id', 'billing_frequency',
        'interval_count', 'next_charge_from', 'next_charge_until',
        'without_next_charge', 'created_from', 'created_until', 'search',
    ];

    /** Longest customer search we will run — a filter, never a full-text engine. */
    public const MAX_SEARCH = 191;

    /**
     * @param  list<string>  $statuses  plan statuses to include; [] = any status
     */
    public function __construct(
        public readonly ?string $planKind = null,
        public readonly array $statuses = [],
        public readonly ?string $externalProductId = null,
        public readonly ?string $billingFrequency = null,
        public readonly ?int $intervalCount = null,
        public readonly ?string $nextChargeFrom = null,
        public readonly ?string $nextChargeUntil = null,
        public readonly bool $withoutNextCharge = false,
        public readonly ?string $createdFrom = null,
        public readonly ?string $createdUntil = null,
        public readonly ?string $search = null,
    ) {}

    /**
     * Build from untrusted input (the screen's state, or a stored run's JSON).
     *
     * Every value is validated against the canonical enums HERE and not at the
     * query: an unknown plan_kind must fall out as "no constraint" rather than
     * reach the database as a string nobody recognises — and, worse, be echoed
     * back into a count the merchant then confirms.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        // Narrowed to the known keys first: a run row, a seeded URL and a screen's
        // state all arrive here, and nothing outside this list may reach a field.
        $input = array_intersect_key($input, array_flip(self::KEYS));

        return new self(
            planKind: self::enumValue($input['plan_kind'] ?? null, PlanKind::class),
            statuses: self::statusList($input['statuses'] ?? []),
            externalProductId: self::text($input['external_product_id'] ?? null, 191),
            billingFrequency: self::enumValue($input['billing_frequency'] ?? null, BillingFrequency::class),
            intervalCount: self::positiveInt($input['interval_count'] ?? null),
            nextChargeFrom: self::date($input['next_charge_from'] ?? null),
            nextChargeUntil: self::date($input['next_charge_until'] ?? null),
            withoutNextCharge: (bool) ($input['without_next_charge'] ?? false),
            createdFrom: self::date($input['created_from'] ?? null),
            createdUntil: self::date($input['created_until'] ?? null),
            search: self::text($input['search'] ?? null, self::MAX_SEARCH),
        );
    }

    /** @return array<string, mixed> the stored/JSON shape (round-trips fromArray). */
    public function toArray(): array
    {
        return [
            'plan_kind' => $this->planKind,
            'statuses' => $this->statuses,
            'external_product_id' => $this->externalProductId,
            'billing_frequency' => $this->billingFrequency,
            'interval_count' => $this->intervalCount,
            'next_charge_from' => $this->nextChargeFrom,
            'next_charge_until' => $this->nextChargeUntil,
            'without_next_charge' => $this->withoutNextCharge,
            'created_from' => $this->createdFrom,
            'created_until' => $this->createdUntil,
            'search' => $this->search,
        ];
    }

    /** No constraint at all — every subscription in the shop matches. */
    public function isUnfiltered(): bool
    {
        return $this->planKind === null
            && $this->statuses === []
            && $this->externalProductId === null
            && $this->billingFrequency === null
            && $this->intervalCount === null
            && $this->nextChargeFrom === null
            && $this->nextChargeUntil === null
            && $this->withoutNextCharge === false
            && $this->createdFrom === null
            && $this->createdUntil === null
            && $this->search === null;
    }

    /**
     * The target set as a query. Tenant-scoped by the model's global scope.
     *
     * `withoutNextCharge` deliberately OVERRIDES the date range rather than
     * combining with it: "has no next charge date" and "charges between these two
     * dates" are contradictory, and an AND of the two matches nothing — which on a
     * screen reads as a broken filter rather than as a contradictory one.
     */
    public function apply(?Builder $query = null): Builder
    {
        $query ??= InstallmentPlan::query();

        if ($this->planKind !== null) {
            $query->where('plan_kind', $this->planKind);
        }

        if ($this->statuses !== []) {
            $query->whereIn('status', $this->statuses);
        }

        if ($this->externalProductId !== null) {
            $query->where('external_product_id', $this->externalProductId);
        }

        if ($this->billingFrequency !== null) {
            $query->where('billing_frequency', $this->billingFrequency);
        }

        if ($this->intervalCount !== null) {
            $query->where('interval_count', $this->intervalCount);
        }

        if ($this->withoutNextCharge) {
            $query->whereNull('next_charge_at');
        } else {
            if ($this->nextChargeFrom !== null) {
                $query->whereDate('next_charge_at', '>=', $this->nextChargeFrom);
            }
            if ($this->nextChargeUntil !== null) {
                $query->whereDate('next_charge_at', '<=', $this->nextChargeUntil);
            }
        }

        if ($this->createdFrom !== null) {
            $query->whereDate('created_at', '>=', $this->createdFrom);
        }
        if ($this->createdUntil !== null) {
            $query->whereDate('created_at', '<=', $this->createdUntil);
        }

        if ($this->search !== null) {
            // The same identity fallback the list's search box uses: a plan whose
            // customer has no name is still found by their email or store id.
            $term = '%'.$this->search.'%';
            $query->where(fn (Builder $q): Builder => $q
                ->where('customer_name', 'like', $term)
                ->orWhere('customer_email', 'like', $term)
                ->orWhere('external_customer_id', 'like', $term)
                ->orWhere('shopify_customer_id', 'like', $term));
        }

        return $query;
    }

    /**
     * The filter as readable lines for the confirmation screen and the receipt.
     *
     * label key => value, rather than one sentence: the screen renders them as
     * rows, and a merchant checking what they are about to do reads a list far
     * better than a paragraph.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $lines = [];

        if ($this->planKind !== null) {
            $lines['subscriptions.list.col.kind'] = __('billing.plan_kind.'.$this->planKind);
        }

        if ($this->statuses !== []) {
            $lines['subscriptions.list.col.status'] = collect($this->statuses)
                ->map(fn (string $s): string => __('billing.status.'.$s))
                ->implode(', ');
        }

        if ($this->externalProductId !== null) {
            $lines['subscriptions.filter.product'] = $this->productLabel();
        }

        if ($this->billingFrequency !== null) {
            $lines['subscriptions.filter.frequency'] = __('billing.settings.frequency.'.$this->billingFrequency);
        }

        if ($this->intervalCount !== null) {
            $lines['subscriptions.action.frequency.every'] = (string) $this->intervalCount;
        }

        if ($this->withoutNextCharge) {
            $lines['subscriptions.bulk.criteria.no_next_charge'] = __('common.yes');
        } elseif ($this->nextChargeFrom !== null || $this->nextChargeUntil !== null) {
            $lines['subscriptions.filter.charge_date'] = ($this->nextChargeFrom !== null && $this->nextChargeFrom === $this->nextChargeUntil)
                ? $this->nextChargeFrom
                : ($this->nextChargeFrom ?? '…').' → '.($this->nextChargeUntil ?? '…');
        }

        if ($this->createdFrom !== null || $this->createdUntil !== null) {
            $lines['subscriptions.filter.created'] = ($this->createdFrom ?? '…').' → '.($this->createdUntil ?? '…');
        }

        if ($this->search !== null) {
            $lines['subscriptions.list.search_placeholder'] = $this->search;
        }

        return $lines;
    }

    /**
     * The product's catalog name, falling back to its id.
     *
     * An id is what the filter matches on (two plans can spell one product two
     * ways), but an id is not what a merchant recognises on a confirmation screen.
     */
    private function productLabel(): string
    {
        $id = (string) $this->externalProductId;

        $title = InstallmentPlan::query()
            ->where('external_product_id', $id)
            ->with('product')
            ->first()
            ?->productTitle();

        return ($title !== null && $title !== '') ? $title : $id;
    }

    // === Normalisers ===

    /** @param class-string $enum */
    private static function enumValue(mixed $value, string $enum): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $enum::tryFrom($value)?->value;
    }

    /** @return list<string> known plan statuses only, de-duplicated. */
    private static function statusList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $known = array_map(static fn (PlanStatus $s): string => $s->value, PlanStatus::cases());

        return array_values(array_unique(array_filter(
            array_map(static fn ($v): string => is_string($v) ? $v : '', $value),
            static fn (string $v): bool => in_array($v, $known, true),
        )));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = mb_substr(trim($value), 0, $max);

        return $clean === '' ? null : $clean;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /** A date as Y-m-d, or null when it is not a date we can stand behind. */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
