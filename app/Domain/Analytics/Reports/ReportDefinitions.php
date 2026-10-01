<?php

namespace App\Domain\Analytics\Reports;

use App\Domain\Analytics\Cancellations\ActiveBreakdown;
use App\Domain\Analytics\Cancellations\CancellationLog;
use App\Domain\Analytics\Cancellations\CancellationLabels;
use App\Domain\Analytics\Cancellations\CancellationReasons;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Support\Frequency;
use App\Domain\Analytics\Support\Sql;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The CSV reports of Analytics › Reports that LETS has data for. Each method
 * returns ['columns' => list<column key>, 'rows' => iterable<list<scalar>>];
 * ReportRunner translates the headers, neutralises formulas and streams.
 *
 * Tenant law: every query starts from a BelongsToShop model under the bound
 * shop; joins pin shop_id. Logs are read in chunks / cursors, never all at once.
 * Timeline details are read KEY BY KEY (from, to, action, reason) — a row's
 * other details (e.g. an invoice_url) never reach a file.
 *
 * "Subscriptions" is not here: it reuses SubscriptionExporter (the file the
 * importer reads back) — see ReportRunner.
 */
final class ReportDefinitions
{
    // === CONSTANTS ===
    public const CHUNK = 500;

    public const UPCOMING_DAYS = 30;

    public const EXCLUDED_CONTEXTS = ['upsell'];

    public const DATE = 'Y-m-d H:i';

    public function __construct(private readonly Period $period, private readonly Filters $filters) {}

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function subscribers(): array
    {
        $plans = $this->filters->applyToPlans(InstallmentPlan::query()
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->whereIn('installment_plans.status', ActiveBook::PLAN_ACTIVE))
            ->selectRaw(Sql::planCustomerKey().' as k, installment_plans.customer_name as name, installment_plans.customer_email as email, '.Sql::planMrr().' as mrr')
            ->toBase();
        if ($this->filters->includesContracts()) {
            $plans->unionAll($this->filters->applyToContracts(SubscriptionContract::query()
                ->whereIn('subscription_contracts.status', ActiveBook::CONTRACT_ACTIVE))
                ->selectRaw(Sql::contractCustomerKey().' as k, subscription_contracts.customer_name as name, subscription_contracts.customer_email as email, '.Sql::contractMrr().' as mrr')
                ->toBase());
        }

        $rows = DB::query()->fromSub($plans, 'u')
            ->selectRaw('MAX(name) as name, MAX(email) as email, COUNT(*) as subs, COALESCE(SUM(mrr), 0) as mrr')
            ->groupBy('k')->orderByDesc('mrr')->get();

        return [
            'columns' => ['customer', 'email', 'active_subscriptions', 'mrr'],
            'rows' => $rows->map(fn ($r): array => [(string) $r->name, (string) $r->email, (int) $r->subs, $this->money($r->mrr)]),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function subscriberSummary(): array
    {
        $rows = (new ActiveBook($this->filters))->byFrequency();

        return [
            'columns' => ['frequency', 'subscribers', 'subscriptions', 'mrr'],
            'rows' => array_map(fn (array $r): array => [$r['label'], $r['subscribers'], $r['subscriptions'], $this->money($r['mrr'])], $rows),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function subscriptionsSummary(): array
    {
        $rows = $this->filters->applyToPlans(InstallmentPlan::query()->where('installment_plans.plan_kind', PlanKind::RECURRING->value))
            ->selectRaw('installment_plans.status as status, COUNT(*) as n, COALESCE(SUM('.Sql::planMrr().'), 0) as mrr')
            ->groupBy('installment_plans.status')->orderByDesc('n')->toBase()->get()
            ->map(fn ($r): array => [(string) $r->status, (int) $r->n, $this->money($r->mrr)])->all();

        if ($this->filters->includesContracts()) {
            $contracts = $this->filters->applyToContracts(SubscriptionContract::query())
                ->selectRaw('subscription_contracts.status as status, COUNT(*) as n, COALESCE(SUM('.Sql::contractMrr().'), 0) as mrr')
                ->groupBy('subscription_contracts.status')->toBase()->get();
            foreach ($contracts as $r) {
                $rows[] = ['shopify:'.strtolower((string) $r->status), (int) $r->n, $this->money($r->mrr)];
            }
        }

        return ['columns' => ['status', 'subscriptions', 'mrr'], 'rows' => $rows];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function activityLogs(): array
    {
        $kinds = [Timeline::KIND_STATUS_CHANGED, ...array_keys(MovementLog::CONTRACT_KINDS)];
        $query = ActivityEvent::query()
            ->leftJoin('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'activity_events.plan_id')
                    ->on('installment_plans.shop_id', '=', 'activity_events.shop_id');
            })
            ->whereIn('activity_events.kind', $kinds)
            ->where(fn ($q) => $q->whereNull('activity_events.payment_id')->orWhere('activity_events.kind', '<>', Timeline::KIND_STATUS_CHANGED))
            ->whereBetween('activity_events.created_at', [$this->period->start(), $this->period->end()])
            ->selectRaw(implode(', ', [
                'activity_events.id as id',
                'activity_events.created_at as at',
                'activity_events.kind as kind',
                'activity_events.actor as actor',
                'activity_events.plan_id as plan_id',
                Sql::jsonText('activity_events.details', 'from').' as from_status',
                Sql::jsonText('activity_events.details', 'to').' as to_status',
                Sql::jsonText('activity_events.details', 'action').' as action',
                Sql::jsonText('activity_events.details', 'reason').' as reason',
                Sql::jsonText('activity_events.details', 'contract_gid').' as contract_gid',
                'installment_plans.public_id as ref',
                'installment_plans.customer_name as name',
                'installment_plans.customer_email as email',
            ]))
            ->toBase();

        return [
            'columns' => ['date', 'subscription', 'customer', 'email', 'event', 'from', 'to', 'actor', 'reason'],
            'rows' => (function () use ($query): iterable {
                foreach ($query->lazyById(self::CHUNK, 'activity_events.id', 'id') as $r) {
                    yield [
                        $this->date($r->at),
                        (string) ($r->ref ?: $r->contract_gid ?: ($r->plan_id ? '#'.$r->plan_id : '')),
                        (string) ($r->name ?? ''),
                        (string) ($r->email ?? ''),
                        (string) ($r->action ?: $r->kind),
                        (string) ($r->from_status ?? ''),
                        (string) ($r->to_status ?? ''),
                        (string) ($r->actor ?? ''),
                        CancellationReasons::clean($r->reason),
                    ];
                }
            })(),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function upcomingOrders(): array
    {
        $from = CarbonImmutable::today();
        $to = $from->addDays(self::UPCOMING_DAYS)->endOfDay();

        $plans = $this->filters->applyToPlans(InstallmentPlan::query()
            ->where('installment_plans.status', PlanStatus::ACTIVE->value)
            ->whereNotNull('installment_plans.next_charge_at')
            ->where('installment_plans.next_charge_at', '<=', $to))
            ->orderBy('installment_plans.next_charge_at')
            ->selectRaw('installment_plans.next_charge_at as at, installment_plans.public_id as ref, installment_plans.customer_name as name, installment_plans.customer_email as email, '
                .'installment_plans.installment_amount as amount, installment_plans.currency as currency, installment_plans.plan_kind as kind, installment_plans.no_charge as no_charge, '
                .Sql::planFrequencyKey().' as freq')
            ->toBase();

        $contracts = $this->filters->includesContracts()
            ? $this->filters->applyToContracts(SubscriptionContract::query()
                ->where('subscription_contracts.status', SubscriptionContract::STATUS_ACTIVE)
                ->whereNotNull('subscription_contracts.next_billing_date')
                ->where('subscription_contracts.next_billing_date', '<=', $to))
                ->orderBy('subscription_contracts.next_billing_date')
                ->selectRaw('subscription_contracts.next_billing_date as at, subscription_contracts.shopify_gid as ref, subscription_contracts.customer_name as name, '
                    .'subscription_contracts.customer_email as email, subscription_contracts.amount as amount, subscription_contracts.currency as currency, '
                    .Sql::contractFrequencyKey().' as freq')
                ->toBase()
            : null;

        return [
            'columns' => ['date', 'subscription', 'customer', 'email', 'amount', 'currency', 'frequency', 'kind'],
            'rows' => (function () use ($plans, $contracts): iterable {
                foreach ($plans->cursor() as $r) {
                    yield [$this->date($r->at), (string) $r->ref, (string) $r->name, (string) $r->email,
                        $r->no_charge ? '0.00' : $this->money($r->amount), (string) $r->currency, Frequency::label((string) $r->freq), (string) $r->kind];
                }
                if ($contracts !== null) {
                    foreach ($contracts->cursor() as $r) {
                        yield [$this->date($r->at), (string) $r->ref, (string) $r->name, (string) $r->email,
                            $this->money($r->amount), (string) $r->currency, Frequency::label((string) $r->freq), 'shopify'];
                    }
                }
            })(),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function subscribedProducts(): array
    {
        $by = [];
        foreach ((new ActiveBreakdown($this->filters))->rows() as $r) {
            $key = $r['product'];
            $by[$key] ??= ['title' => $r['product_title'], 'subs' => 0, 'mrr' => 0.0];
            $by[$key]['subs'] += $r['subscriptions'];
            $by[$key]['mrr'] += $r['mrr'];
        }
        uasort($by, static fn (array $a, array $b): int => $b['subs'] <=> $a['subs']);

        $rows = [];
        foreach ($by as $key => $r) {
            $rows[] = [$this->productLabel($key, $r['title']), $this->productId($key), $r['subs'], $this->money($r['mrr'])];
        }

        return ['columns' => ['product', 'product_id', 'active_subscriptions', 'mrr'], 'rows' => $rows];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function transactionLogs(): array
    {
        $query = PaymentLedger::query()
            ->whereBetween('created_at', [$this->period->start(), $this->period->end()])
            ->select(['id', 'created_at', 'plan_id', 'customer_name', 'customer_email', 'charge_context', 'status', 'amount', 'refunded_amount', 'currency', 'payplus_transaction_uid', 'failure_message'])
            ->toBase();

        return [
            'columns' => ['date', 'transaction', 'subscription', 'customer', 'email', 'context', 'status', 'amount', 'refunded', 'currency', 'gateway_reference', 'failure'],
            'rows' => (function () use ($query): iterable {
                foreach ($query->lazyById(self::CHUNK) as $r) {
                    yield [$this->date($r->created_at), (int) $r->id, $r->plan_id ? '#'.$r->plan_id : '', (string) $r->customer_name, (string) $r->customer_email,
                        (string) $r->charge_context, (string) $r->status, $this->money($r->amount), $this->money($r->refunded_amount), (string) $r->currency,
                        (string) $r->payplus_transaction_uid, (string) $r->failure_message];
                }
            })(),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function transactionSummary(): array
    {
        $rows = PaymentLedger::query()
            ->whereBetween('created_at', [$this->period->start(), $this->period->end()])
            ->selectRaw('charge_context, status, COUNT(*) as n, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(refunded_amount), 0) as refunded')
            ->groupBy('charge_context', 'status')->orderBy('charge_context')->orderBy('status')->toBase()->get();

        return [
            'columns' => ['context', 'status', 'transactions', 'amount', 'refunded'],
            'rows' => $rows->map(fn ($r): array => [(string) $r->charge_context, (string) $r->status, (int) $r->n, $this->money($r->amount), $this->money($r->refunded)]),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function subscriptionSales(): array
    {
        $day = Sql::day('created_at');
        $rows = PaymentLedger::query()
            ->whereBetween('created_at', [$this->period->start(), $this->period->end()])
            ->whereNotIn('charge_context', self::EXCLUDED_CONTEXTS)
            ->whereIn('status', [LedgerStatus::SUCCEEDED->value, LedgerStatus::REFUNDED->value])
            ->selectRaw("{$day} as d, COUNT(*) as n, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(refunded_amount), 0) as refunded")
            ->groupByRaw($day)->orderBy('d')->toBase()->get();

        return [
            'columns' => ['day', 'charges', 'amount', 'refunded', 'net'],
            'rows' => $rows->map(fn ($r): array => [(string) $r->d, (int) $r->n, $this->money($r->amount), $this->money($r->refunded), $this->money((float) $r->amount - (float) $r->refunded)]),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function productSales(): array
    {
        $product = "COALESCE(NULLIF(installment_plans.external_product_id, ''), NULLIF(installment_plans.shopify_product_id, ''), '".CancellationLog::PRODUCT_UNKNOWN."')";
        $title = 'MAX(COALESCE((SELECT MAX(pr.title) FROM products pr WHERE pr.shop_id = installment_plans.shop_id AND pr.external_id = '.$product.'), '
            .Sql::jsonText('installment_plans.meta', 'item_title').'))';

        $query = PaymentLedger::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'payment_ledger.plan_id')->on('installment_plans.shop_id', '=', 'payment_ledger.shop_id');
            })
            ->whereBetween('payment_ledger.created_at', [$this->period->start(), $this->period->end()])
            ->whereNotIn('payment_ledger.charge_context', self::EXCLUDED_CONTEXTS)
            ->whereIn('payment_ledger.status', [LedgerStatus::SUCCEEDED->value, LedgerStatus::REFUNDED->value]);

        $rows = $this->filters->applyToPlans($query)
            ->selectRaw("{$product} as product, {$title} as title, COUNT(*) as n, COALESCE(SUM(payment_ledger.amount), 0) as amount, COALESCE(SUM(payment_ledger.refunded_amount), 0) as refunded")
            ->groupByRaw($product)->orderByDesc('amount')->toBase()->get();

        return [
            'columns' => ['product', 'product_id', 'charges', 'amount', 'refunded', 'net'],
            'rows' => $rows->map(fn ($r): array => [$this->productLabel((string) $r->product, (string) ($r->title ?? '')), $this->productId((string) $r->product), (int) $r->n,
                $this->money($r->amount), $this->money($r->refunded), $this->money((float) $r->amount - (float) $r->refunded)]),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function productAcquisition(): array
    {
        $product = "COALESCE(NULLIF(installment_plans.external_product_id, ''), NULLIF(installment_plans.shopify_product_id, ''), '".CancellationLog::PRODUCT_UNKNOWN."')";
        $title = 'MAX(COALESCE((SELECT MAX(pr.title) FROM products pr WHERE pr.shop_id = installment_plans.shop_id AND pr.external_id = '.$product.'), '
            .Sql::jsonText('installment_plans.meta', 'item_title').'))';

        $rows = $this->filters->applyToPlans(InstallmentPlan::query()
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->whereBetween('installment_plans.created_at', [$this->period->start(), $this->period->end()])
            ->whereNotIn('installment_plans.status', MovementLog::PRE_ACTIVE))
            ->selectRaw("{$product} as product, {$title} as title, COUNT(*) as n, COALESCE(SUM(".Sql::planMrr().'), 0) as mrr')
            ->groupByRaw($product)->orderByDesc('n')->toBase()->get();

        return [
            'columns' => ['product', 'product_id', 'new_subscriptions', 'mrr'],
            'rows' => $rows->map(fn ($r): array => [$this->productLabel((string) $r->product, (string) ($r->title ?? '')), $this->productId((string) $r->product), (int) $r->n, $this->money($r->mrr)]),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function productUpsells(): array
    {
        $rows = $this->upsellRows();

        return [
            'columns' => ['offer', 'product_id', 'impressions', 'accepted', 'declined', 'acceptance_rate'],
            'rows' => $rows->map(fn ($r): array => [(string) $r->title, (string) $r->product, (int) $r->impressions, (int) $r->accepted, (int) $r->declined,
                (int) $r->impressions > 0 ? number_format((int) $r->accepted / (int) $r->impressions * 100, 1, '.', '') : '']),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function productUpsellRevenue(): array
    {
        $rows = $this->upsellRows();

        return [
            'columns' => ['offer', 'product_id', 'charges_succeeded', 'charges_failed', 'revenue'],
            'rows' => $rows->map(fn ($r): array => [(string) $r->title, (string) $r->product, (int) $r->charged, (int) $r->failed, $this->money($r->revenue)]),
        ];
    }

    /** @return array{columns: list<string>, rows: iterable<list<mixed>>} */
    public function cancellationLogs(): array
    {
        $rows = (new CancellationLog($this->filters))->between($this->period->start(), $this->period->end());

        return [
            'columns' => ['date', 'subscription', 'customer', 'email', 'product', 'frequency', 'mrr', 'completed_orders', 'channel', 'reason'],
            'rows' => array_map(fn (array $r): array => [
                substr($r['at'], 0, 16), $r['ref'] !== '' ? $r['ref'] : $r['sub'], $r['name'], $r['email'],
                CancellationLabels::product($r['product'], $r['product_title']), Frequency::label($r['freq']), $this->money($r['mrr']), $r['orders'],
                CancellationLabels::channel($r['channel']),
                CancellationLabels::reason($r['reason_key'], $r['reason']),
            ], $rows),
        ];
    }

    // === Helpers ===

    private function upsellRows()
    {
        return UpsellOfferEvent::query()
            ->leftJoin('upsell_flow_offers as o', function ($join): void {
                $join->on('o.id', '=', 'upsell_offer_events.offer_id')->on('o.shop_id', '=', 'upsell_offer_events.shop_id');
            })
            ->whereBetween('upsell_offer_events.occurred_at', [$this->period->start(), $this->period->end()])
            ->selectRaw(implode(', ', [
                'upsell_offer_events.offer_id as offer_id',
                'MAX(o.offer_title) as title',
                'MAX(o.offer_product_gid) as product',
                "SUM(CASE WHEN upsell_offer_events.event_type = 'impression' THEN 1 ELSE 0 END) as impressions",
                "SUM(CASE WHEN upsell_offer_events.event_type = 'accepted' THEN 1 ELSE 0 END) as accepted",
                "SUM(CASE WHEN upsell_offer_events.event_type = 'declined' THEN 1 ELSE 0 END) as declined",
                "SUM(CASE WHEN upsell_offer_events.event_type = 'charge_succeeded' THEN 1 ELSE 0 END) as charged",
                "SUM(CASE WHEN upsell_offer_events.event_type = 'charge_failed' THEN 1 ELSE 0 END) as failed",
                "COALESCE(SUM(CASE WHEN upsell_offer_events.event_type = 'charge_succeeded' THEN upsell_offer_events.revenue_amount ELSE 0 END), 0) as revenue",
            ]))
            ->groupBy('upsell_offer_events.offer_id')
            ->orderByDesc('revenue')
            ->toBase()->get();
    }

    private function productLabel(string $key, ?string $title): string
    {
        return CancellationLabels::product($key, (string) $title);
    }

    private function productId(string $key): string
    {
        return in_array($key, [CancellationLog::PRODUCT_CONTRACTS, CancellationLog::PRODUCT_UNKNOWN], true) ? '' : $key;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function date(mixed $value): string
    {
        return $value ? CarbonImmutable::parse((string) $value)->format(self::DATE) : '';
    }
}
