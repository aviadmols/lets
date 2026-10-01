<?php

namespace App\Livewire\Analytics;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Payments\UpcomingPaymentsQuery;
use App\Filament\Pages\Analytics\Screens\PaymentsUpcoming;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Upcoming payments TABLE (Analytics › Payments › Upcoming payments):
 * search (subscription id / customer name / email), sort (date / risk /
 * amount) and pagination — the free-text and paging state a screen option
 * cannot carry. Mounted by the screen's partial with the window (days) and
 * the filter chips; keyed by both, so a changed window or chip mounts a fresh
 * table on page 1.
 *
 * Tenant law: every row comes from UpcomingPaymentsQuery, which starts from
 * BelongsToShop models — with no shop bound (the persistent BindTenantFromUser
 * middleware binds it on every Livewire update) it returns nothing.
 */
class UpcomingPaymentsTable extends Component
{
    // === CONSTANTS ===
    public const VIEW = 'filament.pages.analytics.payments.upcoming-table';

    public const LANG = 'analytics/payments_upcoming.';

    public const MAX_SEARCH = 80;

    public const RISK_PILLS = ['high' => 'bad', 'medium' => 'warn', 'low' => 'good'];

    public const METHOD_PILLS = [
        UpcomingPaymentsQuery::METHOD_VALID => 'good',
        UpcomingPaymentsQuery::METHOD_EXPIRING => 'warn',
        UpcomingPaymentsQuery::METHOD_EXPIRED => 'bad',
        UpcomingPaymentsQuery::METHOD_MISSING => 'bad',
    ];

    public const STATUS_PILLS = [
        'succeeded' => 'good',
        'refunded' => 'info',
        'failed' => 'bad',
        'retry_scheduled' => 'warn',
        'pending' => 'neutral',
    ];

    /** The window, from the screen's closed ?o[days] list — never from the browser. */
    #[Locked]
    public int $days = 7;

    /** @var array<string, list<string>> the shell's filter chips (re-sanitised by Filters::fromInput) */
    #[Locked]
    public array $filters = [];

    public string $search = '';

    public string $sort = UpcomingPaymentsQuery::SORT_DATE;

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, self::MAX_SEARCH);
        $this->page = 1;
    }

    public function sortBy(string $sort): void
    {
        $this->sort = in_array($sort, UpcomingPaymentsQuery::SORTS, true) ? $sort : UpcomingPaymentsQuery::SORT_DATE;
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $days = in_array((string) $this->days, UpcomingPaymentsQuery::DAYS, true) ? $this->days : 7;
        $query = new UpcomingPaymentsQuery(Filters::fromInput($this->filters), $days);
        $result = $query->page($this->search, $this->sort, $this->page);
        $this->page = $result['page'];

        return view(self::VIEW, [
            'rows' => array_map($this->row(...), $result['rows']),
            'total' => $result['total'],
            'pages' => $result['pages'],
            'from' => $result['total'] === 0 ? 0 : ($result['page'] - 1) * UpcomingPaymentsQuery::PER_PAGE + 1,
            'to' => min($result['total'], $result['page'] * UpcomingPaymentsQuery::PER_PAGE),
            'sorts' => array_combine(UpcomingPaymentsQuery::SORTS, array_map(
                static fn (string $s): string => __(self::LANG.'sort.'.$s),
                UpcomingPaymentsQuery::SORTS,
            )),
        ]);
    }

    /** @return array<string, mixed> one formatted row */
    private function row(object $r): array
    {
        $risk = UpcomingPaymentsQuery::riskKey((int) $r->risk);
        $status = $r->last_status === null ? null : (string) $r->last_status;
        $due = CarbonImmutable::parse((string) $r->due);
        $method = __(self::LANG.'method.'.$r->method);
        if ($r->method === UpcomingPaymentsQuery::METHOD_EXPIRING && $r->card_exp) {
            $method = __(self::LANG.'method.expiring_on', ['date' => substr((string) $r->card_exp, 5, 2).'/'.substr((string) $r->card_exp, 0, 4)]);
        }

        return [
            'key' => $r->source.'-'.$r->id,
            'ref' => '#'.$r->ref,
            'url' => PaymentsUpcoming::rowUrl($r),
            'risk' => ['pill' => self::RISK_PILLS[$risk], 'text' => __(self::LANG.'risk.'.$risk)],
            'customer' => (string) ($r->customer ?: $r->email ?: '—'),
            'email' => $r->customer ? (string) $r->email : '',
            'method' => ['pill' => self::METHOD_PILLS[$r->method] ?? 'neutral', 'text' => $method],
            'date' => $due->locale(app()->getLocale())->translatedFormat('j M Y'),
            'date_url' => PaymentsUpcoming::dayUrl($due->format('Y-m-d')),
            'amount' => ChartFormat::money((float) $r->amount),
            'retries' => $r->retries_left === null ? '—' : ChartFormat::value(max(0, (int) $r->retries_left)),
            'status' => $status === null ? null : ['pill' => self::STATUS_PILLS[$status] ?? 'neutral', 'text' => __(self::LANG.'status.'.(in_array($status, array_column(LedgerStatus::cases(), 'value'), true) ? $status : 'pending'))],
            'error' => $r->last_error ? (string) $r->last_error : '—',
            'source' => __('analytics/payments_overview.source.'.$r->source),
        ];
    }
}
