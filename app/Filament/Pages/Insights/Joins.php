<?php

namespace App\Filament\Pages\Insights;

use App\Domain\Insights\PaidSubscriptions;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Pages\Insights\Concerns\InsightsScreen;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * How many new subscribers joined — per day or per month, over any window.
 *
 * Only people who joined THROUGH THE SITE and paid count (PaidSubscriptions, imported
 * members excluded): an import is not joining, and counting it made the first day a
 * spike of a thousand. The side tiles and the table follow the chosen window too.
 */
class Joins extends Page
{
    use InsightsScreen;
    use ShopScopedScreen;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static string $view = 'filament.pages.insights.joins';

    protected static ?string $slug = 'joins';

    protected static ?int $navigationSort = 10;

    /** The tile "last N days of the window". */
    public const RECENT_DAYS = 7;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.joins.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('insights.joins.nav');
    }

    /** @return array<string, mixed> everything the view draws */
    public function screen(): array
    {
        $rows = (new PaidSubscriptions)->all(false);
        $w = $this->window();
        $windowRows = array_values(array_filter($rows, static fn (array $r): bool => $r['day'] >= $w['from']->format('Y-m-d') && $r['day'] <= $w['to']->format('Y-m-d')));

        // Which subscription they joined — in the chosen window only, most joined first.
        $byProduct = [];
        foreach ($windowRows as $r) {
            $title = $r['product'] !== '' ? $r['product'] : '—';
            $byProduct[$title] = ($byProduct[$title] ?? 0) + 1;
        }
        arsort($byProduct);
        usort($windowRows, static fn (array $a, array $b): int => strcmp($b['day'], $a['day']));
        $days = $this->windowDays();
        $inWindow = static fn (string $a, string $b): int => count(array_filter($rows, static fn (array $r): bool => $r['day'] >= $a && $r['day'] <= $b));

        $total = $inWindow($w['from']->format('Y-m-d'), $w['to']->format('Y-m-d'));
        $prevFrom = $w['from']->subDays($days);
        $firstJoin = $rows === [] ? null : min(array_column($rows, 'day'));
        // Compared only with a period the site was already selling through, entirely.
        $fair = $firstJoin !== null && $prevFrom->format('Y-m-d') >= $firstJoin;
        $prev = $inWindow($prevFrom->format('Y-m-d'), $w['from']->subDay()->format('Y-m-d'));

        $buckets = $this->buckets($rows, ['n' => '']);
        $byDay = $this->buckets($rows, ['n' => ''], 'day');
        usort($byDay, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
        $best = $byDay[0] ?? null;
        $recentFrom = $w['to']->subDays(self::RECENT_DAYS - 1)->max($w['from']);

        return [
            'total' => $total,
            'average' => round($total / max(1, $days), 1),
            'previous' => $fair && $prev > 0 ? $prev : null,
            'mood' => ! $fair || $prev === 0 ? null : ($total > $prev ? 'up' : ($total < $prev ? 'down' : 'flat')),
            'since' => $firstJoin,
            'best' => $best !== null && $best['n'] > 0 ? ['day' => $best['key'], 'count' => (int) $best['n']] : null,
            'recent' => $inWindow($recentFrom->format('Y-m-d'), $w['to']->format('Y-m-d')),
            'chart' => $this->barPayload($buckets, 'n', fn (array $b): array => [
                $this->longLabel($b['key']),
                __('insights.joins.tip', ['count' => (int) $b['n']]),
            ]),
            'table' => array_reverse(array_values(array_filter($buckets, static fn (array $b): bool => $b['n'] > 0))),
            'by_product' => $byProduct,
            'people' => $windowRows,
        ];
    }
}
