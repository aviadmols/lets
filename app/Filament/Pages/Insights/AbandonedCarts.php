<?php

namespace App\Filament\Pages\Insights;

use App\Domain\Insights\AbandonedCheckouts;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Pages\Insights\Concerns\InsightsScreen;
use App\Support\CsvCell;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * People who started buying a subscription and did not finish — by day, who came back
 * and bought anyway, and a list (filterable to "did not come back") the merchant can
 * export for the dates on screen and call.
 */
class AbandonedCarts extends Page
{
    use InsightsScreen;
    use ShopScopedScreen;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string $view = 'filament.pages.insights.abandoned-carts';

    protected static ?string $slug = 'abandoned-carts';

    protected static ?int $navigationSort = 40;

    public const WHO = ['all', 'lost', 'back'];

    /** Above this many days the chart groups by month on its own. */
    public const DAILY_LIMIT = 120;

    /** Excel on a Hebrew Windows needs the BOM to read UTF-8. */
    private const BOM = "\xEF\xBB\xBF";

    #[Url(as: 'who')]
    public string $who = 'all';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.abandoned.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('insights.abandoned.nav');
    }

    public function setWho(string $who): void
    {
        $this->who = in_array($who, self::WHO, true) ? $who : 'all';
    }

    /** @return array<string, mixed> */
    public function screen(): array
    {
        $w = $this->window();
        $rows = (new AbandonedCheckouts)->between($w['from'], $w['to']);
        $back = array_values(array_filter($rows, static fn (array $r): bool => $r['came_back']));
        $lost = array_values(array_filter($rows, static fn (array $r): bool => ! $r['came_back']));
        $grain = $this->windowDays() > self::DAILY_LIMIT ? 'month' : 'day';

        return [
            'total' => count($rows),
            'back' => count($back),
            'lost' => count($lost),
            'lost_money' => round(array_sum(array_column($lost, 'amount')), 2),
            'chart' => $this->barPayload($this->buckets($rows, ['n' => ''], $grain), 'n', fn (array $b): array => [
                $this->longLabel($b['key']),
                __('insights.abandoned.tip', ['count' => (int) $b['n']]),
            ]),
            'list' => $this->listed($rows),
        ];
    }

    /** The CSV of exactly the list on screen: the window's dates AND the who-filter. */
    public function export(): StreamedResponse
    {
        $w = $this->window();
        $rows = $this->listed((new AbandonedCheckouts)->between($w['from'], $w['to']));
        $t = static fn (string $k): string => (string) __('insights.abandoned.csv.'.$k);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, self::BOM);
        fputcsv($handle, CsvCell::neutraliseRow([$t('name'), $t('email'), $t('phone'), $t('date'), $t('time'), $t('product'), $t('amount'), $t('came_back')]), ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($handle, CsvCell::neutraliseRow([
                $r['name'], $r['email'], $r['phone'], Carbon::parse($r['day'])->format('d/m/Y'), $r['at'],
                $r['product'], (string) $r['amount'], $r['came_back'] ? $t('yes') : $t('no'),
            ]), ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $name = 'abandoned-carts-'.($this->who !== 'all' ? $this->who.'-' : '').$w['from']->format('Y-m-d').'-'.$w['to']->format('Y-m-d').'.csv';

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function listed(array $rows): array
    {
        return array_values(match ($this->who) {
            'lost' => array_filter($rows, static fn (array $r): bool => ! $r['came_back']),
            'back' => array_filter($rows, static fn (array $r): bool => $r['came_back']),
            default => $rows,
        });
    }
}
