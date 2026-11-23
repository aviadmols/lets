<?php

namespace App\Filament\Pages\Insights\Concerns;

use App\Domain\Insights\InsightsClock;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Url;

/**
 * What every merchant analytics screen shares: the window the merchant picked (a fixed
 * range back from today, or their own dates), the day/month switch, and the bar payload
 * the chart script draws. Range state lives in the URL, so a screen can be shared,
 * bookmarked and reloaded as it was.
 */
trait InsightsScreen
{
    // === CONSTANTS === (trait constants need PHP 8.2+; kept as static data below)

    /** @var list<string> */
    public static array $ranges = ['7', '30', '90', '365', 'custom'];

    #[Url(as: 'range')]
    public string $range = '30';

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'by')]
    public string $grain = 'day';

    public function setRange(string $range): void
    {
        $this->range = in_array($range, self::$ranges, true) ? $range : '30';
        if ($this->range === 'custom' && ($this->from === null || $this->to === null)) {
            $this->from = InsightsClock::today()->subDays(29)->format(InsightsClock::DATE);
            $this->to = InsightsClock::today()->format(InsightsClock::DATE);
        }
    }

    public function setGrain(string $grain): void
    {
        $this->grain = $grain === 'month' ? 'month' : 'day';
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    public function window(): array
    {
        $today = InsightsClock::today();

        if ($this->range === 'custom' && $this->validDate($this->from) && $this->validDate($this->to)) {
            $a = CarbonImmutable::parse($this->from, InsightsClock::TIMEZONE)->startOfDay();
            $b = CarbonImmutable::parse($this->to, InsightsClock::TIMEZONE)->startOfDay();
            [$a, $b] = $a->lte($b) ? [$a, $b] : [$b, $a];

            return ['from' => $a, 'to' => $b->gt($today) ? $today : $b];
        }

        $days = in_array($this->range, ['7', '30', '90', '365'], true) ? (int) $this->range : 30;

        return ['from' => $today->subDays($days - 1), 'to' => $today];
    }

    public function windowDays(): int
    {
        $w = $this->window();

        return (int) $w['from']->diffInDays($w['to']) + 1;
    }

    /** "in the last 30 days" / "between 01/10 and 15/10" */
    public function windowWords(): string
    {
        $w = $this->window();
        $today = InsightsClock::today();

        if ($this->range !== 'custom' && $w['to']->equalTo($today)) {
            return (string) __('insights.window.last', ['days' => $this->windowDays()]);
        }

        return (string) __('insights.window.between', ['from' => $w['from']->format('d/m/Y'), 'to' => $w['to']->format('d/m/Y')]);
    }

    /**
     * Rows with a `day` key → one bucket per day (or month) of the window, zeros included:
     * a quiet day must read as zero, not vanish from the chart.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $sum  output key => row key to add up ('' = count rows)
     * @return list<array<string, mixed>>
     */
    public function buckets(array $rows, array $sum, ?string $grain = null): array
    {
        $grain ??= $this->grain;
        $w = $this->window();
        $buckets = [];

        $cursor = $grain === 'month' ? $w['from']->startOfMonth() : $w['from'];
        while ($cursor->lte($w['to'])) {
            $key = $grain === 'month' ? $cursor->format('Y-m') : $cursor->format(InsightsClock::DATE);
            $buckets[$key] = array_merge(['key' => $key], array_fill_keys(array_keys($sum), 0));
            $cursor = $grain === 'month' ? $cursor->addMonth() : $cursor->addDay();
        }

        $a = $w['from']->format(InsightsClock::DATE);
        $b = $w['to']->format(InsightsClock::DATE);
        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day < $a || $day > $b) {
                continue;
            }
            $key = $grain === 'month' ? substr($day, 0, 7) : $day;
            if (! isset($buckets[$key])) {
                continue;
            }
            foreach ($sum as $out => $field) {
                $buckets[$key][$out] += $field === '' ? 1 : (float) ($row[$field] ?? 0);
            }
        }

        return array_values($buckets);
    }

    /** A bucket key as the axis writes it: "14/11" or "Nov 26". */
    public function axisLabel(string $key): string
    {
        return strlen($key) === 7
            ? CarbonImmutable::parse($key.'-01')->locale(App::getLocale())->translatedFormat('M y')
            : CarbonImmutable::parse($key)->format('d/m');
    }

    /** A bucket key as a tooltip writes it: "Thursday, 14/11/2026" or "November 2026". */
    public function longLabel(string $key): string
    {
        $date = CarbonImmutable::parse(strlen($key) === 7 ? $key.'-01' : $key)->locale(App::getLocale());

        return strlen($key) === 7 ? $date->translatedFormat('F Y') : $date->translatedFormat('l, d/m/Y');
    }

    /**
     * The payload rcInsights.bar() draws.
     *
     * @param  list<array<string, mixed>>  $buckets
     * @param  callable(array<string, mixed>): list<string>  $tip
     * @return array{points: list<array{label: string, value: float, tip: list<string>}>, tone: string}
     */
    public function barPayload(array $buckets, string $valueKey, callable $tip, string $tone = 'hero'): array
    {
        return [
            'tone' => $tone,
            'points' => array_map(fn (array $b): array => [
                'label' => $this->axisLabel((string) $b['key']),
                'value' => (float) $b[$valueKey],
                'tip' => array_values(array_map('strval', $tip($b))),
            ], $buckets),
        ];
    }

    /** Shekels as the merchant reads them: whole, grouped. */
    public static function money(float $amount): string
    {
        return '₪'.number_format($amount, 0, '.', ',');
    }

    public static function percent(int $part, int $whole): string
    {
        return $whole > 0 ? (string) round($part / $whole * 100).'%' : '—';
    }

    /** The bound shop's start-of-numbers day (config insights.start_dates / first charge). */
    public function startDay(): CarbonImmutable
    {
        $shop = Tenant::current();

        return $shop !== null ? InsightsClock::start($shop) : InsightsClock::today();
    }

    private function validDate(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
