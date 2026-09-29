<?php

namespace App\Filament\Search;

use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Filament\Resources\PaymentLedgerResource\Pages\ViewPayment;
use App\Filament\Resources\SubscriptionContractResource\Pages\ViewSubscriptionContract;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The top bar's search box: customers, subscriptions and orders of the shop this
 * session works on, grouped, each result linking to the screen that already
 * shows it.
 *
 * TENANCY. Every query runs on a BelongsToShop model through its global scope —
 * never withoutGlobalScopes() — so another shop's rows cannot be matched. With
 * no shop bound (a platform admin who has not entered one) the provider answers
 * with an EMPTY result set before touching the database: it fails closed, and it
 * does not lean on the scope's own fail-closed behaviour to get there.
 *
 * COST. A fixed number of queries per keystroke, whatever the shop's size: each
 * group is one bounded query (two where both rails hold rows), products are
 * eager-loaded, and customers are grouped in PHP from one capped read — the
 * same derivation the Customers list uses, so a result opens the same page.
 */
final class TenantGlobalSearchProvider implements GlobalSearchProvider
{
    // === CONSTANTS ===
    /** Fewer characters than this match half the shop; the box waits instead. */
    public const MIN_LENGTH = 2;

    /** Results per group — a jump list, not a report. */
    public const GROUP_LIMIT = 5;

    /** Plan rows read to find GROUP_LIMIT distinct customers (one person may own several plans). */
    public const CUSTOMER_SCAN = 40;

    /** A search term longer than this is not something anyone typed to find a record. */
    public const MAX_LENGTH = 100;

    /** Group labels (translation keys), in display order. */
    public const GROUP_CUSTOMERS = 'nav.search.group.customers';

    public const GROUP_SUBSCRIPTIONS = 'nav.search.group.subscriptions';

    public const GROUP_ORDERS = 'nav.search.group.orders';

    /** Order references a merchant types with a leading hash ("#1042"). */
    public const ORDER_PREFIX = '#';

    public const PLAN_ORDER_COLUMNS = ['shopify_order_id', 'external_order_id'];

    public const LEDGER_ORDER_COLUMNS = ['child_order_id', 'parent_order_id', 'shopify_order_id'];

    /** Separator between the facts on a result's second line. */
    public const DETAIL_GLUE = ' · ';

    public function getResults(string $query): ?GlobalSearchResults
    {
        $term = mb_substr(trim($query), 0, self::MAX_LENGTH);

        // Too short to mean anything: no dropdown at all (null), not "no results".
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return null;
        }

        $results = GlobalSearchResults::make();

        // FAIL CLOSED: no bound shop, nothing to search.
        if (! Tenant::check()) {
            return $results;
        }

        foreach ([
            self::GROUP_CUSTOMERS => $this->customers($term),
            self::GROUP_SUBSCRIPTIONS => $this->subscriptions($term),
            self::GROUP_ORDERS => $this->orders($term),
        ] as $group => $items) {
            if ($items !== []) {
                $results->category(__($group), $items);
            }
        }

        return $results;
    }

    /** @return list<GlobalSearchResult> */
    private function customers(string $term): array
    {
        $like = '%'.$term.'%';
        $op = $this->likeOperator(new InstallmentPlan);

        $found = [];

        InstallmentPlan::query()
            ->where(fn (Builder $w) => $w
                ->where('customer_name', $op, $like)
                ->orWhere('customer_email', $op, $like)
                ->orWhere('customer_phone', $op, $like)
                // The store's own customer reference, as the Customers list searches it.
                ->orWhere('shopify_customer_id', $op, $like)
                ->orWhere('external_customer_id', $op, $like))
            ->latest('id')
            ->limit(self::CUSTOMER_SCAN)
            ->get(['id', 'shopify_customer_id', 'external_customer_id', 'customer_id', 'customer_name', 'customer_email', 'customer_phone'])
            ->groupBy(fn (InstallmentPlan $p): string => Customers::customerKey($p))
            ->reject(fn (Collection $group, string $key): bool => $key === '')
            ->each(function (Collection $group, string $key) use (&$found): void {
                $named = $group->first(fn (InstallmentPlan $p): bool => trim((string) $p->customer_name) !== '') ?? $group->first();
                $phone = $group->first(fn (InstallmentPlan $p): bool => trim((string) $p->customer_phone) !== '')?->customer_phone;

                $found[$key] = new GlobalSearchResult(
                    title: $named->customerLabel(),
                    url: CustomerDetail::getUrl(['customer' => $key]),
                    details: $this->details([$named->customer_email, $phone]),
                );
            });

        // Shopify-rail shoppers exist only as contracts; keyed the way the
        // Customers list keys them (the gid's numeric tail).
        if (count($found) < self::GROUP_LIMIT) {
            $contractOp = $this->likeOperator(new SubscriptionContract);

            SubscriptionContract::query()
                ->whereNotNull('shopify_customer_gid')
                ->where(fn (Builder $w) => $w
                    ->where('customer_name', $contractOp, $like)
                    ->orWhere('customer_email', $contractOp, $like))
                ->latest('id')
                ->limit(self::CUSTOMER_SCAN)
                ->get(['id', 'shopify_customer_gid', 'customer_name', 'customer_email'])
                ->each(function (SubscriptionContract $c) use (&$found): void {
                    $key = basename((string) $c->shopify_customer_gid);
                    if ($key === '' || isset($found[$key])) {
                        return;
                    }

                    $found[$key] = new GlobalSearchResult(
                        title: trim((string) $c->customer_name) ?: (string) $c->customer_email,
                        url: CustomerDetail::getUrl(['customer' => $key]),
                        details: $this->details([$c->customer_email]),
                    );
                });
        }

        return array_values(array_slice($found, 0, self::GROUP_LIMIT, true));
    }

    /** @return list<GlobalSearchResult> */
    private function subscriptions(string $term): array
    {
        $like = '%'.$term.'%';
        $op = $this->likeOperator(new InstallmentPlan);
        $numericId = ctype_digit(ltrim($term, self::ORDER_PREFIX)) ? (int) ltrim($term, self::ORDER_PREFIX) : null;

        $items = InstallmentPlan::query()
            ->with('product')
            ->where(function (Builder $w) use ($op, $like, $numericId): void {
                $w->where('public_id', $op, $like)
                    ->orWhere('customer_name', $op, $like)
                    ->orWhere('customer_email', $op, $like);

                if ($numericId !== null) {
                    $w->orWhere('id', $numericId);
                }
            })
            ->latest('id')
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn (InstallmentPlan $plan): GlobalSearchResult => new GlobalSearchResult(
                title: $plan->customerLabel(),
                url: ViewSubscription::getUrl(['plan' => $plan->getKey()]),
                details: $this->details([$plan->productTitle(), $this->planStatusLabel($plan)]),
            ))
            ->all();

        $room = self::GROUP_LIMIT - count($items);

        if ($room > 0) {
            $contractOp = $this->likeOperator(new SubscriptionContract);

            SubscriptionContract::query()
                ->where(fn (Builder $w) => $w
                    ->where('customer_name', $contractOp, $like)
                    ->orWhere('customer_email', $contractOp, $like)
                    ->orWhere('shopify_gid', $contractOp, $like))
                ->latest('id')
                ->limit($room)
                ->get()
                ->each(function (SubscriptionContract $c) use (&$items): void {
                    $items[] = new GlobalSearchResult(
                        title: trim((string) $c->customer_name) ?: (trim((string) $c->customer_email) ?: __('shopify_subscriptions.detail.untitled')),
                        url: ViewSubscriptionContract::getUrl(['contract' => $c->getKey()]),
                        details: $this->details([__('nav.shopify_subscriptions'), __('shopify_subscriptions.status.'.$c->status)]),
                    );
                });
        }

        return $items;
    }

    /**
     * Orders by their number: a checkout order is the plan it started (the plan's
     * page is where it lives), a renewal order is the payment that created it.
     *
     * @return list<GlobalSearchResult>
     */
    private function orders(string $term): array
    {
        $number = ltrim($term, self::ORDER_PREFIX);

        if (mb_strlen($number) < self::MIN_LENGTH) {
            return [];
        }

        $like = '%'.$number.'%';
        $found = [];

        $planOp = $this->likeOperator(new InstallmentPlan);

        InstallmentPlan::query()
            ->where(fn (Builder $w) => $this->anyColumnLike($w, self::PLAN_ORDER_COLUMNS, $planOp, $like))
            ->latest('id')
            ->limit(self::GROUP_LIMIT)
            ->get(['id', 'shopify_order_id', 'external_order_id', 'customer_name', 'customer_email', 'shopify_customer_id', 'external_customer_id', 'total_amount', 'installment_amount', 'currency'])
            ->each(function (InstallmentPlan $plan) use (&$found): void {
                $order = (string) ($plan->external_order_id ?: $plan->shopify_order_id);
                $found[$order] ??= new GlobalSearchResult(
                    title: __('nav.search.order', ['number' => basename($order)]),
                    url: ViewSubscription::getUrl(['plan' => $plan->getKey()]),
                    details: $this->details([$plan->customerLabel()]),
                );
            });

        if (count($found) < self::GROUP_LIMIT) {
            $ledgerOp = $this->likeOperator(new PaymentLedger);

            PaymentLedger::query()
                ->where(fn (Builder $w) => $this->anyColumnLike($w, self::LEDGER_ORDER_COLUMNS, $ledgerOp, $like))
                ->latest('id')
                ->limit(self::GROUP_LIMIT)
                ->get(['id', 'child_order_id', 'parent_order_id', 'shopify_order_id', 'customer_name', 'customer_email', 'amount', 'currency'])
                ->each(function (PaymentLedger $row) use (&$found, $number): void {
                    $order = $this->matchingOrder($row, $number);
                    if ($order === null || isset($found[$order])) {
                        return;
                    }

                    $found[$order] = new GlobalSearchResult(
                        title: __('nav.search.order', ['number' => basename($order)]),
                        url: ViewPayment::getUrl(['payment' => $row->getKey()]),
                        details: $this->details([
                            trim((string) $row->customer_name) ?: $row->customer_email,
                            Money::format((float) $row->amount),
                        ]),
                    );
                });
        }

        return array_values(array_slice($found, 0, self::GROUP_LIMIT, true));
    }

    /** The ledger column that matched, so the title names the order the merchant typed. */
    private function matchingOrder(PaymentLedger $row, string $number): ?string
    {
        foreach (self::LEDGER_ORDER_COLUMNS as $column) {
            $value = (string) ($row->{$column} ?? '');
            if ($value !== '' && mb_stripos($value, $number) !== false) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<string> $columns */
    private function anyColumnLike(Builder $w, array $columns, string $op, string $like): Builder
    {
        foreach ($columns as $column) {
            $w->orWhere($column, $op, $like);
        }

        return $w;
    }

    /**
     * Case-insensitive matching on every engine: Postgres' LIKE is case-sensitive,
     * so a merchant typing "dana" would miss "Dana" in production and not locally.
     */
    private function likeOperator(object $model): string
    {
        return $model->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function planStatusLabel(InstallmentPlan $plan): string
    {
        $status = $plan->status instanceof PlanStatus ? $plan->status->value : (string) $plan->status;

        return __('billing.status.'.$status);
    }

    /**
     * One muted line under the title: the non-empty facts, joined.
     *
     * @param  list<?string>  $parts
     * @return list<string>
     */
    private function details(array $parts): array
    {
        $parts = array_values(array_filter(
            array_map(static fn ($p): string => trim((string) $p), $parts),
            static fn (string $p): bool => $p !== '',
        ));

        return $parts === [] ? [] : [implode(self::DETAIL_GLUE, $parts)];
    }
}
