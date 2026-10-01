<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Payments\DeclineReason;
use App\Domain\Analytics\Payments\PaymentsRecoveryQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Payments › Recovery (sketch: PaymentsRecovery.dc.html; spec §3.2).
 * PaymentsRecoveryQuery computes; this class shapes; the partial draws.
 *
 * Every number reads the Timeline's attempt history (PaymentJourneys).
 * Recovered "via card update" = a card_updated event on the plan between the
 * first failure and the success; everything else the retry ladder brought
 * back. A backup card does not exist in LETS — its strategy row says so.
 */
final class PaymentsRecovery extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'PaymentsRecovery.dc.html';

    public const VIEW = 'filament.pages.analytics.payments.recovery';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const LANG = 'analytics/payments_recovery.';

    public const TONE_RETRY = 's1';

    public const TONE_CARD = 's2';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new PaymentsRecoveryQuery($context))->get();
        $r = $q['recovery'];

        return [
            'has_data' => $q['has_data'],
            'first_cycle' => PaymentsOverview::cycle($q['first_cycle']),
            'subsequent_cycle' => PaymentsOverview::cycle($q['subsequent_cycle']),
            'strategies' => $this->strategies($q),
            'split' => [
                'slices' => [
                    ['label' => __(self::LANG.'via.retry'), 'value' => $r['retry'], 'tone' => self::TONE_RETRY],
                    ['label' => __(self::LANG.'via.card_update'), 'value' => $r['card_update'], 'tone' => self::TONE_CARD],
                ],
                'centre' => $r['recovered'] > 0 ? ChartFormat::value(round($r['retry'] / $r['recovered'] * 100), ChartFormat::PERCENT) : '—',
                'caption' => __(self::LANG.'split.caption'),
                'note' => __(self::LANG.'split.note', ['count' => ChartFormat::value($r['recovered'])]),
            ],
            'by_retry' => $this->byRetry($q['by_retry']),
            'trend' => [
                'id' => PaymentsRecoveryQuery::CHART_TREND,
                'labels' => $q['trend']['labels'],
                'series' => [
                    ['key' => 'retry', 'label' => __(self::LANG.'via.retry'), 'tone' => self::TONE_RETRY, 'values' => $q['trend']['retry']],
                    ['key' => 'card_update', 'label' => __(self::LANG.'via.card_update'), 'tone' => self::TONE_CARD, 'values' => $q['trend']['card_update']],
                ],
                'grain' => PaymentsRecoveryQuery::grain($context)->value,
                'grains' => SubscribersOverview::grainOptions($context->period),
            ],
            'by_reason' => $this->byReason($q['by_reason']),
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new PaymentsRecoveryQuery($context))->get();

        return [
            'headers' => [
                __(self::LANG.'reason.reason'), __(self::LANG.'reason.attempts'), __(self::LANG.'reason.recovered'),
                __(self::LANG.'reason.under'), __(self::LANG.'reason.rate'), __(self::LANG.'via.retry'), __(self::LANG.'via.card_update'),
            ],
            'rows' => array_map(static fn (array $r): array => [
                DeclineReason::label($r['reason']), $r['failed'], $r['recovered'], $r['under'], $r['rate'], $r['retry'], $r['card_update'],
            ], $q['by_reason']),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    private function strategies(array $q): array
    {
        $rows = [];
        foreach ($q['strategies'] as $s) {
            $rows[] = [
                'strategy' => __(self::LANG.'strategy.'.$s['key'], ['count' => $q['max_attempts']]),
                'status' => ['pill' => 'good', 'text' => __(self::LANG.'strategy.active')],
                'failed' => ChartFormat::value($s['failed']),
                'under' => ChartFormat::value($s['under']),
                'recovered' => ChartFormat::value($s['recovered']),
                'rate' => PaymentsOverview::pct($s['rate']) ?? '—',
            ];
        }
        $rows[] = [
            'strategy' => __(self::LANG.'strategy.backup_card'),
            'status' => ['pill' => 'neutral', 'text' => __('analytics.empty.not_tracked_title')],
            'failed' => '—', 'under' => '—', 'recovered' => '—', 'rate' => '—',
        ];

        $col = static fn (string $key, bool $numeric = true): array => ['key' => $key, 'label' => __(self::LANG.'strategy.col.'.$key), 'numeric' => $numeric, 'strong' => $key === 'strategy'];

        return [
            'columns' => [$col('strategy', false), $col('status', false), $col('failed'), $col('under'), $col('recovered'), $col('rate')],
            'rows' => $rows,
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function byRetry(array $rows): array
    {
        $col = static fn (string $key, bool $numeric = true): array => ['key' => $key, 'label' => __(self::LANG.'retry.'.$key), 'numeric' => $numeric, 'strong' => $key === 'retry'];

        return [
            'columns' => [$col('retry', false), $col('attempts'), $col('recovered'), $col('rate'), $col('retry_path'), $col('card_update')],
            'rows' => array_map(static fn (array $r): array => [
                'retry' => __(self::LANG.'retry.number', ['n' => $r['retry']]),
                'attempts' => ChartFormat::value($r['attempts']),
                'recovered' => ChartFormat::value($r['recovered']),
                'rate' => PaymentsOverview::pct($r['rate']) ?? '—',
                'retry_path' => ChartFormat::value($r['retry_path']),
                'card_update' => ChartFormat::value($r['card_update']),
            ], $rows),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function byReason(array $rows): array
    {
        $col = static fn (string $key, bool $numeric = true): array => ['key' => $key, 'label' => __(self::LANG.'reason.'.$key), 'numeric' => $numeric, 'strong' => $key === 'reason'];

        return [
            'columns' => [$col('reason', false), $col('attempts'), $col('recovered'), $col('under'), $col('rate'), $col('retry'), $col('card_update')],
            'rows' => array_map(static fn (array $r): array => [
                'reason' => DeclineReason::label($r['reason']),
                'attempts' => ChartFormat::value($r['failed']),
                'recovered' => ChartFormat::value($r['recovered']),
                'under' => ChartFormat::value($r['under']),
                'rate' => PaymentsOverview::pct($r['rate']) ?? '—',
                'retry' => ChartFormat::value($r['retry']),
                'card_update' => ChartFormat::value($r['card_update']),
            ], $rows),
        ];
    }
}
