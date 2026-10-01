<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Payments\PaymentJourneys;
use App\Domain\Analytics\Payments\UpcomingPaymentsQuery;
use App\Filament\Resources\SubscriptionResource;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;

/**
 * Payments › Upcoming payments (sketch: UpcomingPayments.dc.html; spec §3.4).
 *
 * The window (next 7 / 14 / 30 / 60 / 90 days) is a screen option, so it is
 * in the URL like every other toggle. The table itself — search, sort,
 * pagination — is the nested Livewire component
 * App\Livewire\Analytics\UpcomingPaymentsTable: free-text search is not a
 * whitelisted screen option, and paging a list of thousands must not re-run
 * the whole screen. Both read the same UpcomingPaymentsQuery.
 *
 * The per-day drill-down the old Analytics page had is kept: every day with
 * charges links to the Subscriptions list filtered to that next-charge date
 * (tableFilters[next_charge_at]).
 *
 * Not tracked: a separate delivery price (a plan stores one cycle amount), so
 * the table prints the full charge and says so.
 */
final class PaymentsUpcoming extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'UpcomingPayments.dc.html';

    public const VIEW = 'filament.pages.analytics.payments.upcoming';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTIONS = ['days' => UpcomingPaymentsQuery::DAYS];

    public const LANG = 'analytics/payments_upcoming.';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $days = (int) $context->option('days', self::OPTIONS['days']);
        $query = new UpcomingPaymentsQuery($context->filters, $days);
        $s = $query->summary();
        $window = Period::spanLabel($query->from(), $query->until());

        return [
            'days' => $days,
            'day_options' => array_combine(
                UpcomingPaymentsQuery::DAYS,
                array_map(static fn (string $d): string => __(self::LANG.'window.next', ['count' => $d]), UpcomingPaymentsQuery::DAYS),
            ),
            'filters' => $context->filters->toArray(),
            'filters_key' => $context->filters->key(),
            'kpis' => [
                ['label' => __(self::LANG.'kpi.scheduled'), 'value' => ChartFormat::value($s['count']),
                    'caption' => __(self::LANG.'window.next', ['count' => $days]).' · '.$window],
                ['label' => __(self::LANG.'kpi.amount'), 'value' => ChartFormat::money($s['amount']),
                    'caption' => $s['expected'] === null ? null : __(self::LANG.'kpi.expected', [
                        'amount' => ChartFormat::money($s['expected']),
                        'rate' => ChartFormat::value($s['success'], ChartFormat::PERCENT),
                    ])],
                ['label' => __(self::LANG.'kpi.high'), 'value' => ChartFormat::value($s['high']),
                    'caption' => __(self::LANG.'kpi.share', ['rate' => ChartFormat::value(UpcomingPaymentsQuery::share($s['high'], $s['count']), ChartFormat::PERCENT)])],
                ['label' => __(self::LANG.'kpi.medium'), 'value' => ChartFormat::value($s['medium']),
                    'caption' => __(self::LANG.'kpi.share', ['rate' => ChartFormat::value(UpcomingPaymentsQuery::share($s['medium'], $s['count']), ChartFormat::PERCENT)])],
            ],
            'by_day' => self::byDay($query->byDay()),
        ];
    }

    public function export(Context $context): ?array
    {
        $days = (int) $context->option('days', self::OPTIONS['days']);
        $rows = (new UpcomingPaymentsQuery($context->filters, $days))->all();

        return [
            'headers' => array_map(static fn (string $k): string => __(self::LANG.'col.'.$k), [
                'id', 'risk', 'customer', 'email', 'method', 'date', 'amount', 'retries', 'last_status', 'last_error', 'source',
            ]),
            'rows' => array_map(static fn (object $r): array => [
                $r->ref,
                __(self::LANG.'risk.'.UpcomingPaymentsQuery::riskKey((int) $r->risk)),
                $r->customer,
                $r->email,
                __(self::LANG.'method.'.$r->method),
                substr((string) $r->due, 0, 10),
                round((float) $r->amount, 2),
                $r->retries_left,
                $r->last_status === null ? null : __(self::LANG.'status.'.$r->last_status),
                $r->last_error,
                __('analytics/payments_overview.source.'.$r->source),
            ], $rows),
        ];
    }

    /**
     * One row per day with charges + its drill-down link into Subscriptions.
     *
     * @param  list<array{date: string, count: int, amount: float}>  $days
     * @return list<array{label: string, n: int, count: string, amount: string, url: string}>
     */
    public static function byDay(array $days): array
    {
        $locale = app()->getLocale();

        return array_map(static fn (array $d): array => [
            'label' => CarbonImmutable::parse($d['date'])->locale($locale)->translatedFormat('D j M'),
            'n' => $d['count'],
            'count' => ChartFormat::value($d['count']),
            'amount' => ChartFormat::money($d['amount']),
            'url' => self::dayUrl($d['date']),
        ], $days);
    }

    /** The Subscriptions list filtered to one next-charge day (the old page's drill-down). */
    public static function dayUrl(string $date): string
    {
        return SubscriptionResource::getUrl('index', [
            'tableFilters' => ['next_charge_at' => ['from' => $date, 'until' => $date]],
        ]);
    }

    /** A subscription's own page — PayPlus plans only (contracts live in Shopify). */
    public static function rowUrl(object $row): ?string
    {
        return $row->source === PaymentJourneys::SOURCE_PAYPLUS
            ? SubscriptionResource::getUrl('view', ['plan' => $row->id])
            : null;
    }
}
