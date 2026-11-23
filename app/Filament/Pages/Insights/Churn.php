<?php

namespace App\Filament\Pages\Insights;

use App\Domain\Insights\ActiveBookSummary;
use App\Domain\Insights\Departures;
use App\Domain\Insights\GrowthMath;
use App\Domain\Insights\InsightsClock;
use App\Domain\Insights\PaidSubscriptions;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Pages\Insights\Concerns\InsightsScreen;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Who left, when, why, and what they were paying — and how many keep paying month after
 * month. Two kinds of leaving are kept apart (Departures): cancelled, and stopped paying.
 * Nothing before the shop's start day counts (InsightsClock::start).
 */
class Churn extends Page
{
    use InsightsScreen;
    use ShopScopedScreen;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-user-minus';

    protected static string $view = 'filament.pages.insights.churn';

    protected static ?string $slug = 'churn';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.churn.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('insights.churn.nav');
    }

    /** @return array<string, mixed> */
    public function screen(): array
    {
        $start = $this->startDay();
        $w = $this->window();
        $from = $w['from']->max($start);

        $all = (new Departures)->between($start, InsightsClock::today());
        $rows = array_values(array_filter($all, static fn (array $r): bool => $r['day'] >= $from->format('Y-m-d') && $r['day'] <= $w['to']->format('Y-m-d')));
        $quit = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === Departures::KIND_CANCELLED));
        $lapsed = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === Departures::KIND_STOPPED_PAYING));
        $mrr = static fn (array $list): float => round(array_sum(array_column($list, 'mrr')), 2);
        $book = (new ActiveBookSummary)->get()['count'] + count($rows);

        $reasons = [];
        foreach ($quit as $r) {
            $label = $this->reasonLabel($r['reason']);
            $reasons[$label] = ($reasons[$label] ?? 0) + 1;
        }
        arsort($reasons);

        $group = array_values(array_filter((new PaidSubscriptions)->all(true), static fn (array $p): bool => $p['day'] >= $start->format('Y-m-d')));

        return [
            'total' => count($rows),
            'quit' => count($quit),
            'lapsed' => count($lapsed),
            'lost' => $mrr($rows),
            'quit_mrr' => $mrr($quit),
            'lapsed_mrr' => $mrr($lapsed),
            'book' => $book,
            'rate' => self::percent(count($rows), $book),
            'chart' => $this->barPayload($this->buckets($all, ['n' => '', 'mrr' => 'mrr']), 'n', fn (array $b): array => [
                $this->longLabel($b['key']),
                __('insights.churn.tip', ['count' => (int) $b['n'], 'amount' => self::money((float) $b['mrr'])]),
            ]),
            'reasons' => $reasons,
            'ladder' => GrowthMath::ladder($group, InsightsClock::today(), (int) config('insights.ladder_grace_days'), (int) config('insights.ladder_months')),
            'group' => count($group),
            'imported' => count(array_filter($group, static fn (array $p): bool => $p['imported'])),
            'start' => $start,
            'list' => array_reverse($rows),
        ];
    }

    public function reasonLabel(?string $key): string
    {
        return match (true) {
            $key === null => (string) __('insights.churn.reason_none'),
            str_starts_with($key, 'sys:') => (string) __('insights.churn.reason_sys.'.substr($key, 4)),
            default => $key,
        };
    }
}
