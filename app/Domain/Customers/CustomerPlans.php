<?php

namespace App\Domain\Customers;

use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
    /**
     * String columns a rail may have written the customer's reference into. Both
     * are varchar and hold whatever the platform calls a customer — a numeric
     * WooCommerce user id, a Shopify id, or the UUID an imported member came
     * with.
     */
    public const REF_COLUMNS = ['shopify_customer_id', 'external_customer_id'];

    /**
     * The numeric one, kept apart because it is an unsignedBigInteger.
     *
     * Comparing it to a non-numeric reference is not merely fruitless — Postgres
     * refuses to read "2883bb96-31e5-…" as a bigint and aborts the whole query,
     * which is how the customer page 500'd for every imported member (their
     * reference IS a UUID). A reference that is not digits can never match this
     * column, so it is simply not asked.
     */
    public const NUMERIC_REF_COLUMN = 'customer_id';

    /**
     * References that look like an id and are not an identity.
     *
     * `0` is WooCommerce's OWN value for "no user": every guest checkout in the
     * shop writes it, so matching on it merged four different people — four
     * different email addresses — into one customer, and the merchant opened a
     * shopper's page to find somebody else's subscriptions listed as theirs.
     *
     * The docblock above already says a blank email never merges anyone. This is
     * the same law: `0` is a blank wearing a number's clothes. A guest is
     * reachable by the address they typed, and by nothing else.
     */
    public const NON_IDENTIFYING_REFS = ['0'];

    /** Can this reference stand for ONE person? */
    public static function identifies(string $reference): bool
    {
        $reference = trim($reference);

        return $reference !== '' && ! in_array($reference, self::NON_IDENTIFYING_REFS, true);
    }

    /**
     * Plans grouped into customers: two plans are ONE person when they share an
     * identifying reference OR an email address — and so is anything linked to
     * either of them, transitively.
     *
     * Keying each plan on one field split people in two. A member imported with
     * a UUID reference who later re-subscribed at checkout as a guest (`0`, so
     * keyed by email) came out as two customers with the same email, one holding
     * the cancelled subscription and the other the active one. The customer page
     * already merged them (query() follows the emails); the list and the search
     * did not.
     *
     * A blank email and a non-identifying reference never link anyone — the same
     * law as query(): they are not identities.
     *
     * @param  Collection<int, InstallmentPlan>  $plans  in display order (newest first)
     * @return Collection<string, Collection<int, InstallmentPlan>> keyed by key()
     */
    public static function group(Collection $plans): Collection
    {
        $plans = $plans->values();
        $parent = [];
        $owner = [];

        $root = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };

        foreach ($plans as $i => $plan) {
            $parent[$i] = $i;
            $tokens = array_map(static fn (string $r): string => 'ref:'.$r, self::refsOf($plan));
            $email = self::emailOf($plan);
            if ($email !== null) {
                $tokens[] = 'email:'.$email;
            }

            foreach ($tokens as $token) {
                if (! isset($owner[$token])) {
                    $owner[$token] = $i;

                    continue;
                }

                // The lower index stays the root, so a group keeps display order.
                [$a, $b] = [$root($i), $root($owner[$token])];
                if ($a !== $b) {
                    $parent[max($a, $b)] = min($a, $b);
                }
            }
        }

        $members = [];
        foreach ($plans as $i => $plan) {
            $members[$root($i)][] = $plan;
        }

        $groups = [];
        foreach ($members as $group) {
            $group = collect($group);
            $key = self::key($group);
            if ($key === '') {
                continue; // names nobody — left out, never merged into a bucket
            }

            $groups[$key] = isset($groups[$key]) ? $groups[$key]->merge($group) : $group;
        }

        return collect($groups);
    }

    /**
     * The one key a group of plans goes by — the route parameter of its page.
     *
     * A store account number first (a numeric reference: the one the contact
     * card can read and edit), then any other identifying reference, then the
     * email. query() resolves every kind, and follows the emails to the rest.
     *
     * @param  Collection<int, InstallmentPlan>  $plans  newest first
     */
    public static function key(Collection $plans): string
    {
        $refs = $plans->flatMap(static fn (InstallmentPlan $p): array => self::refsOf($p));

        return (string) ($refs->first(static fn (string $r): bool => ctype_digit($r))
            ?? $refs->first()
            ?? $plans->map(static fn (InstallmentPlan $p): ?string => self::emailOf($p))->filter()->first()
            ?? '');
    }

    /**
     * The identifying references a plan carries, from every column a rail may
     * have written one into (see REF_COLUMNS / NUMERIC_REF_COLUMN).
     *
     * @return list<string>
     */
    public static function refsOf(InstallmentPlan $plan): array
    {
        $refs = [];
        foreach ([...self::REF_COLUMNS, self::NUMERIC_REF_COLUMN] as $column) {
            $ref = trim((string) ($plan->getAttributes()[$column] ?? ''));
            if (self::identifies($ref)) {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    /** The plan's email, lowercased; null when blank. */
    public static function emailOf(InstallmentPlan $plan): ?string
    {
        $email = mb_strtolower(trim((string) ($plan->getAttributes()['customer_email'] ?? '')));

        return $email !== '' ? $email : null;
    }

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
        if (! self::identifies($reference)) {
            // Fail CLOSED, exactly as an empty reference does. A guest's `0`
            // matches every other guest, and this is a query that decides whose
            // purchase history somebody is looking at. Their email clause still
            // runs — that is how a guest is found.
            return $query->whereRaw('1 = 0');
        }

        foreach (self::REF_COLUMNS as $i => $column) {
            $i === 0
                ? $query->where($column, $reference)
                : $query->orWhere($column, $reference);
        }

        // Only when it could possibly match — see NUMERIC_REF_COLUMN.
        if (ctype_digit($reference)) {
            $query->orWhere(self::NUMERIC_REF_COLUMN, $reference);
        }

        return $query;
    }
}
