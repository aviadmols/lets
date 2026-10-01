<?php

namespace App\Livewire\Analytics;

use App\Domain\Analytics\Cancellations\RiskQuery;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\AnalyticsDownload;
use App\Domain\Analytics\Support\Frequency;
use App\Filament\Resources\SubscriptionContractResource;
use App\Filament\Resources\SubscriptionResource;
use App\Support\Tenant;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cancellations › Risk analysis — the live table (search, level + card filters,
 * sortable columns, pagination, CSV of exactly what is filtered — streamed
 * through a signed GET, AnalyticsDownload).
 *
 * A nested Livewire component because a screen class is plain and its URL
 * options are closed lists; free-text search needs its own state. Every value
 * coming back from the browser is re-validated before RiskQuery reads it, the
 * shell's filter chips arrive as a #[Locked] prop (re-sanitised by
 * Filters::fromInput), and everything runs under the tenant the panel's
 * persistent middleware binds — no tenant, no rows.
 */
class RiskTable extends Component
{
    // === CONSTANTS ===
    public const VIEW = 'filament.pages.analytics.cancellations.risk-table';

    public const LANG = 'analytics/cancellations_risk.';

    public const PER_PAGE = 25;

    public const LEVEL_PILLS = [RiskQuery::LEVEL_HIGH => 'bad', RiskQuery::LEVEL_MEDIUM => 'warn', RiskQuery::LEVEL_LOW => 'neutral'];

    public const LEVEL_KEYS = [RiskQuery::LEVEL_HIGH => 'high', RiskQuery::LEVEL_MEDIUM => 'medium', RiskQuery::LEVEL_LOW => 'low'];

    public const CARD_PILLS = [
        RiskQuery::CARD_VALID => 'good', RiskQuery::CARD_EXPIRING => 'warn', RiskQuery::CARD_EXPIRED => 'bad',
        RiskQuery::CARD_REVOKED => 'bad', RiskQuery::CARD_NONE => 'neutral',
    ];

    public const CARD_FILTERS = [RiskQuery::CARD_EXPIRED, RiskQuery::CARD_EXPIRING, RiskQuery::CARD_VALID, RiskQuery::CARD_NONE];

    /** CSV columns, in order (labels: col.<key>). */
    public const CSV_COLUMNS = ['subscription', 'risk', 'status', 'created', 'customer', 'email', 'price', 'orders', 'success', 'streak', 'card', 'expiry', 'interval'];

    /** Success % below these colours red / amber (the sketch's legend). */
    public const SUCCESS_BAD = 70;

    public const SUCCESS_WARN = 90;

    /** @var array<string, list<string>> the shell's filter chips (URL shape) */
    #[Locked]
    public array $filters = [];

    #[Url(as: 'rq')]
    public string $search = '';

    #[Url(as: 'rl')]
    public string $level = '';

    #[Url(as: 'rc')]
    public string $card = '';

    #[Url(as: 'rs')]
    public string $sort = 'risk';

    #[Url(as: 'rd')]
    public string $dir = 'desc';

    #[Url(as: 'rp')]
    public int $page = 1;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'level', 'card'], true)) {
            $this->page = 1;
        }
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, RiskQuery::SORTS, true)) {
            return;
        }
        $this->dir = $this->sort === $column && $this->dir === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * CSV of every row the current search + filters select, in the current
     * order: a redirect to a short-lived signed GET for this shop, streamed by
     * AnalyticsDownloadController — never a Livewire download.
     */
    public function export(): void
    {
        [$search, $level, $card, $sort, $dir] = $this->state();
        $url = AnalyticsDownload::url(AnalyticsDownload::KIND_RISK, [
            'f' => $this->filters, 'q' => $search, 'level' => $level, 'card' => $card, 'sort' => $sort, 'dir' => $dir,
        ]);
        if ($url !== null) {
            $this->redirect($url);
        }
    }

    /** @return list<string> the CSV header (the screen's Export and the table's share it) */
    public static function csvHeaders(): array
    {
        return array_map(static fn (string $k): string => __(self::LANG.'col.'.$k), self::CSV_COLUMNS);
    }

    /** @param array<string, mixed> $r a RiskQuery row @return list<string|int> */
    public static function csvRow(array $r): array
    {
        return [
            $r['ref'] !== '' ? $r['ref'] : '#'.$r['id'],
            __(self::LANG.'level.'.self::LEVEL_KEYS[$r['risk']]),
            $r['status'],
            substr($r['created_at'], 0, 10),
            $r['name'],
            $r['email'],
            number_format($r['price'], 2, '.', ''),
            $r['orders'],
            $r['success'] === null ? '' : number_format($r['success'], 1, '.', ''),
            $r['streak'],
            __(self::LANG.'card.'.$r['card']),
            $r['card_exp'] ?? '',
            Frequency::label($r['freq']),
        ];
    }

    /**
     * Search, level, card, sort and direction from untrusted input (the
     * component's own props, or a download link's parameters), each snapped
     * onto its closed list.
     *
     * @param  array<string, mixed>  $p  keys q, level, card, sort, dir
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public static function stateFrom(array $p): array
    {
        $text = static fn (string $k): string => is_string($p[$k] ?? null) ? $p[$k] : '';

        return [
            mb_substr(trim($text('q')), 0, RiskQuery::MAX_SEARCH),
            array_key_exists($text('level'), RiskQuery::LEVELS) ? $text('level') : '',
            in_array($text('card'), self::CARD_FILTERS, true) ? $text('card') : '',
            in_array($text('sort'), RiskQuery::SORTS, true) ? $text('sort') : 'risk',
            in_array($text('dir'), RiskQuery::DIRECTIONS, true) ? $text('dir') : 'desc',
        ];
    }

    public function render(): View
    {
        [$search, $level, $card, $sort, $dir] = $this->state();
        $result = Tenant::check()
            ? $this->query()->page($search, $level, $card, $sort, $dir, $this->page, self::PER_PAGE)
            : ['rows' => [], 'total' => 0];

        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($this->page > $pages) {
            $this->page = $pages;
        }

        return view(self::VIEW, [
            'rows' => array_map(fn (array $r): array => $this->shape($r), $result['rows']),
            'total' => $result['total'],
            'pages' => $pages,
            'from' => $result['total'] === 0 ? 0 : ($this->page - 1) * self::PER_PAGE + 1,
            'to' => min($result['total'], $this->page * self::PER_PAGE),
            'levels' => ['' => __(self::LANG.'filter.all_levels')] + array_combine(array_keys(RiskQuery::LEVELS), array_map(static fn (string $k): string => __(self::LANG.'level.'.$k), array_keys(RiskQuery::LEVELS))),
            'cards' => ['' => __(self::LANG.'filter.all_cards')] + array_combine(self::CARD_FILTERS, array_map(static fn (string $k): string => __(self::LANG.'card.'.$k), self::CARD_FILTERS)),
            'sortable' => ['created' => 'created', 'orders' => 'orders', 'success' => 'success', 'risk' => 'risk'],
        ]);
    }

    private function query(): RiskQuery
    {
        return new RiskQuery(Filters::fromInput($this->filters));
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} validated state */
    private function state(): array
    {
        $this->page = max(1, $this->page);

        return self::stateFrom(['q' => $this->search, 'level' => $this->level, 'card' => $this->card, 'sort' => $this->sort, 'dir' => $this->dir]);
    }

    /** @return array<string, mixed> one row, formatted for the view */
    private function shape(array $r): array
    {
        $locale = app()->getLocale();
        $success = $r['success'];

        return [
            'ref' => $r['rail'] === RiskQuery::RAIL_PLAN ? '#'.$r['id'] : '#C'.$r['id'],
            'url' => $r['rail'] === RiskQuery::RAIL_PLAN
                ? SubscriptionResource::getUrl('view', ['plan' => $r['id']])
                : SubscriptionContractResource::getUrl('view', ['contract' => $r['id']]),
            'risk' => ['pill' => self::LEVEL_PILLS[$r['risk']], 'text' => __(self::LANG.'level.'.self::LEVEL_KEYS[$r['risk']])],
            'status' => __(self::LANG.'status.'.strtolower($r['status'])),
            'status_tone' => in_array($r['status'], RiskQuery::LAPSED, true) ? 'bad' : (strtolower($r['status']) === 'paused' ? 'warn' : 'good'),
            'created' => CarbonImmutable::parse($r['created_at'])->locale($locale)->translatedFormat('j M Y'),
            'name' => $r['name'] !== '' ? $r['name'] : '—',
            'email' => $r['email'],
            'price' => ChartFormat::money($r['price']),
            'orders' => ChartFormat::value($r['orders']),
            'success' => $success === null ? '—' : ChartFormat::value($success, ChartFormat::PERCENT),
            'success_tone' => $success === null ? null : ($success < self::SUCCESS_BAD ? 'bad' : ($success < self::SUCCESS_WARN ? 'warn' : null)),
            'streak' => ChartFormat::value($r['streak']),
            'card' => ['pill' => self::CARD_PILLS[$r['card']], 'text' => __(self::LANG.'card.'.$r['card'])],
            'expiry' => $r['card_exp'] ?? '—',
            'interval' => Frequency::label($r['freq']),
        ];
    }
}
