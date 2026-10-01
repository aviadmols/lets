<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;

/**
 * One Analytics screen (a section's sub-tab). The Analytics page owns the
 * shell — header, tabs, period, compare, filter chips, URL state — and asks
 * the screen for three things only: which Blade partial to draw, the data that
 * partial needs, and (optionally) rows for the Export button.
 *
 * ONE FILE SET PER SCREEN (docs/analytics/building-a-screen.md):
 *   app/Filament/Pages/Analytics/Screens/<Section><Sub>.php      ← this class
 *   resources/views/filament/pages/analytics/<section>/<sub>.blade.php
 *   app/Domain/Analytics/<Section>/…Query.php                    ← the numbers
 *   lang/{en,he}/analytics/<section>_<sub>.php                    ← its copy
 *
 * A screen is a plain object, NOT a Livewire component: its toggles call the
 * page's generic setGrain()/setOption() actions, so every bit of state lives
 * in the page's URL and a screen never has to know about Livewire.
 */
abstract class AnalyticsScreen
{
    // === CONSTANTS ===
    /** Filter chips this screen offers (Filters::DIMENSIONS), in order. */
    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    /**
     * Screen-specific toggles kept in the URL (?o[key]=value): key => allowed
     * values, the FIRST being the default. e.g. ['unit' => ['count', 'revenue']].
     * Read with $context->option('unit', static::OPTIONS['unit']).
     */
    public const OPTIONS = [];

    /** The approved sketch board this screen implements (for the next agent). */
    public const SKETCH = '';

    /** The Blade partial (dot notation) drawn inside the shell. */
    abstract public function view(): string;

    /**
     * Everything the partial needs, already formatted and translated. Query
     * classes compute; this method shapes; the Blade only draws.
     *
     * @return array<string, mixed>
     */
    abstract public function data(Context $context): array;

    /** @return list<string> */
    public function filters(): array
    {
        return static::FILTERS;
    }

    /** @return array<string, list<string>> */
    public function options(): array
    {
        return static::OPTIONS;
    }

    /**
     * Rows for the Export button (CSV), or null when this screen has nothing to
     * export yet (the button renders disabled).
     *
     * @return array{headers: list<string>, rows: list<list<string|int|float|null>>}|null
     */
    public function export(Context $context): ?array
    {
        return null;
    }

    /** Does this screen offer a CSV at all? (It overrides export().) Cheap — computes nothing. */
    public function exportable(): bool
    {
        return ! $this->isPlaceholder()
            && (new \ReflectionMethod($this, 'export'))->getDeclaringClass()->getName() !== self::class;
    }

    public function isPlaceholder(): bool
    {
        return false;
    }
}
