<?php

namespace App\Domain\Customers;

use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHICH subscriptions belong to one customer — defined once, for every screen
 * that has to answer it.
 *
 * There is no customers table: a customer is whatever their plans say they are,
 * and the rails disagree about where to write it. The WooCommerce subscribe path
 * fills external_customer_id and often no Shopify id at all; the Shopify order
 * sync fills shopify_customer_id; a guest checkout has neither and is reachable
 * only by the address they typed. A screen that reads one column shows half a
 * person — which is exactly what the customer page did before this existed.
 *
 * So: any id column carrying the reference, plus every email those plans are
 * known by. A BLANK email never merges anyone — a blank is not an identity, and
 * joining on it would collapse every guest in the shop into one customer.
 *
 * Tenant-scoped by InstallmentPlan's BelongsToShop global scope; this class
 * never names a shop_id.
 */
final class CustomerPlans
{
    // === CONSTANTS ===
    /** The columns a rail may have written the customer's reference into. */
    public const REF_COLUMNS = ['shopify_customer_id', 'external_customer_id', 'customer_id'];

    /** A query for every plan belonging to this customer, newest first. */
    public static function query(string $reference): Builder
    {
        $reference = trim($reference);

        if ($reference === '') {
            // Never "everyone". A missing reference is a bug upstream, and the
            // fail-closed answer is the only safe one on a screen that shows
            // somebody's purchase history.
            return InstallmentPlan::query()->whereRaw('1 = 0');
        }

        $emails = self::emailsFor($reference);

        return InstallmentPlan::query()
            ->where(function (Builder $q) use ($reference, $emails): void {
                self::byReference($q, $reference);

                foreach ($emails as $email) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->latest('id');
    }

    /**
     * Every address this customer is known by: the emails on the plans that name
     * their reference, plus the reference itself when it IS an email (a guest).
     *
     * @return list<string> lowercased, non-empty, unique
     */
    public static function emailsFor(string $reference): array
    {
        return InstallmentPlan::query()
            ->where(fn (Builder $q) => self::byReference($q, $reference))
            ->pluck('customer_email')
            ->push(filter_var($reference, FILTER_VALIDATE_EMAIL) !== false ? $reference : null)
            ->map(static fn ($email): string => mb_strtolower(trim((string) $email)))
            ->filter(static fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** The reference on any id column a rail might have used. */
    private static function byReference(Builder $query, string $reference): Builder
    {
        foreach (self::REF_COLUMNS as $i => $column) {
            $i === 0
                ? $query->where($column, $reference)
                : $query->orWhere($column, $reference);
        }

        return $query;
    }
}
