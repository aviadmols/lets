<?php

namespace App\Filament\Pages\Insights;

use App\Domain\Insights\ActiveBookSummary;
use App\Domain\Insights\ChargeForecast;
use App\Domain\Insights\Departures;
use App\Domain\Insights\GrowthMath;
use App\Domain\Insights\InsightsClock;
use App\Domain\Insights\PaidSubscriptions;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Pages\Insights\Concerns\InsightsScreen;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * What is expected to be charged each coming day and how many subscribers, over a week
 * to six months — and what the book will look like in one and two months if the last
 * month's pace of joining minus leaving holds.
 */
class RevenueForecast extends Page
{
    use InsightsScreen;
    use ShopScopedScreen;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $view = 'filament.pages.insights.revenue-forecast';

    protected static ?string $slug = 'revenue-forecast';

    protected static ?int $navigationSort = 50;

    public const AHEAD = ['7', '30', '90', '180'];

    #[Url(as: 'ahead')]
    public string $ahead = '30';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.forecast.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('insights.forecast.nav');
    }

    public function setAhead(string $ahead): void
    {
        $this->ahead = in_array($ahead, self::AHEAD, true) ? $ahead : '30';
    }

    /** @return array<string, mixed> */
    public function screen(): array
    {
        $days = (int) $this->ahead;
        $today = InsightsClock::today();
        $byDay = (new ChargeForecast)->days($days);

        $points = [];
        for ($i = 0; $i < $days; $i++) {
            $key = $today->addDays($i)->format(InsightsClock::DATE);
            $points[] = ['key' => $key, 'count' => $byDay[$key]['count'] ?? 0, 'amount' => $byDay[$key]['amount'] ?? 0.0];
        }

        $total = round(array_sum(array_column($points, 'amount')), 2);
        $busiest = collect($points)->sortByDesc('amount')->first();
        $start = $this->startDay();
        $active = (new ActiveBookSummary)->get();

        return [
            'total' => $total,
            'charges' => (int) array_sum(array_column($points, 'count')),
            'average' => round($total / max(1, $days), 2),
            'busiest' => $busiest !== null && $busiest['amount'] > 0 ? $busiest : null,
            'chart' => $this->barPayload($points, 'amount', fn (array $p): array => [
                $this->longLabel($p['key']),
                __('insights.forecast.tip', ['amount' => self::money((float) $p['amount']), 'count' => (int) $p['count']]),
            ]),
            'pace' => GrowthMath::pace(
                array_column((new PaidSubscriptions)->all(false), 'day'),
                array_column((new Departures)->between($start, $today), 'day'),
                $active['count'],
                $active['amount'],
                $today,
                $start,
            ),
            'table' => array_values(array_filter($points, static fn (array $p): bool => $p['count'] > 0)),
        ];
    }
}
