# Building an Analytics screen

The Analytics page (`app/Filament/Pages/Analytics.php`) is a **shell**: header (date range, compare,
Export), section tabs, sub-tab pills, filter chips, and URL state. Each screen is its own file set. The
reference screen is **Subscribers › Overview** — copy its shape.

Sources of truth: the sketch boards (`…/scratchpad/lets-analytics-sketch/project/*.dc.html`, mocks with
inline styles — read them, never copy the styles), `spec.md`, and [data-map.md](data-map.md) (what is
tracked; if your metric is ✖ there, render the not-tracked state).

## 1. The registry — do not edit it

`app/Filament/Pages/Analytics/ScreenRegistry.php` already lists every screen of the sketch, each pointing
at its **own** class in `app/Filament/Pages/Analytics/Screens/`. Until built, those classes extend
`PlaceholderScreen` and render "Coming in this build". To build one, **rewrite that one class file**.
Two people building two screens never touch the same file.

| Section › sub | Class | Sketch |
|---|---|---|
| subscribers › overview | `SubscribersOverview` (**built**) | Main / MainHe |
| subscribers › acquisition · order_funnel · revenue · lifetime_value | `SubscribersAcquisition` · `SubscribersOrderFunnel` · `SubscribersRevenue` · `SubscribersLifetimeValue` | Acquisition · OrderFunnel · Revenue · LifetimeValue |
| cohorts › overview | `Cohorts` | Cohorts |
| payments › overview · recovery · failures · upcoming | `PaymentsOverview` · `PaymentsRecovery` · `PaymentsFailures` · `PaymentsUpcoming` | PaymentsOverview · PaymentsRecovery · PaymentsFailures · UpcomingPayments |
| products › overview · revenue · churn | `ProductsOverview` · `ProductsRevenue` · `ProductsChurn` | Products · (ChartGrammar) · ProductsChurn |
| upsells › added · sold | `UpsellsAdded` · `UpsellsSold` | Upsells |
| cancellations › overview · saves · order_wise · risk | `CancellationsOverview` · `CancellationsSaves` · `CancellationsOrderWise` · `CancellationsRisk` | Cancellations · Saves · OrderWiseChurn · RiskAnalysis |
| forecast › overview | `Forecast` | Forecast |
| reports › reports · exports | `ReportsLibrary` · `ReportsExports` | Reports |

## 2. One file set per screen

```
app/Filament/Pages/Analytics/Screens/<Section><Sub>.php             screen class (shapes props)
resources/views/filament/pages/analytics/<section>/<sub>.blade.php  partial (draws only)
app/Domain/Analytics/<Section>/<Something>Query.php                 numbers (SQL, cached)
lang/en/analytics/<section>_<sub>.php + lang/he/analytics/<section>_<sub>.php   copy
tests/Feature/Analytics/<Section><Sub>Test.php                      tests
```

### Screen class

```php
final class PaymentsOverview extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'PaymentsOverview.dc.html';
    public const FILTERS = [Filters::COUNTRY];                 // chips this screen offers ([] = none)
    public const OPTIONS = ['unit' => ['count', 'revenue']];   // ?o[unit]=…, first = default

    public function view(): string { return 'filament.pages.analytics.payments.overview'; }

    public function data(Context $context): array { /* call the query, shape props, __() every label */ }

    public function export(Context $context): ?array { return ['headers' => [...], 'rows' => [...]]; } // optional
}
```

`Context` gives you `$context->period` (`start()`, `end()`, `comparison()`, `earliest()`, `label()`,
`days()`), `$context->filters` (`applyToPlans($q)`, `applyToContracts($q)`, `includesContracts()`),
`$context->grain('<chart_id>', ?preferred)` and `$context->option('unit', self::OPTIONS['unit'])`.
Toggles call the page's generic actions — `setGrain(chartId, grain)` and `setOption(key, value)` — so a
screen never needs Livewire state of its own, and every view is a link.

`SubscribersOverview::compareCaption($period)` gives the "vs previous 30 days" caption for KPI cards and
`SubscribersOverview::grainOptions($period)` the toggle options.

### Query class conventions

- **Tenant law.** Start every query from a `BelongsToShop` model (`InstallmentPlan::query()`,
  `PaymentLedger::query()`, `ActivityEvent::query()`…). Joined tables: pin `joined.shop_id = base.shop_id`
  in the join. Never `withoutGlobalScopes()`. No tenant bound ⇒ the scope returns nothing.
- **Aggregate in SQL.** `selectRaw … groupBy`; never load all plans. Fetching *events in a window*
  (movements, failures) is fine — that is proportional to activity, not to the book.
- **Portable SQL.** Anything dialect-specific goes through `App\Domain\Analytics\Support\Sql`
  (`day()`, `month()`, `jsonText()`, `planCustomerKey()`, `planMrr()`, `planFrequencyKey()`,
  `contractQuantity()`…). Add a method there rather than branching in a query class. Tests run SQLite,
  production runs Postgres. Quote the contract column `"interval"`.
- **Time series.** Group by `Sql::day(col)` in SQL, then `Granularity::rollUp($period, $byDay)` /
  `dayIndex()` / `buckets()` in PHP — one query shape for daily, weekly and monthly.
- **Compare.** Run the same aggregate for `$period->comparison()`; `Support\Delta::percent()` for counts
  and money, `Delta::points()` for rates.
- **Cache.** Wrap the SQL in `AnalyticsCache::remember('<section>.<name>', $period, $filters, fn () => …)`
  (5 minutes, keyed by shop + locale + period + filters). Cache numbers, not view HTML.
- **MRR / frequency / selling plan** — use `Support\Mrr`, `Support\Frequency`, `Subscribers\ActiveBook`;
  never re-derive them.
- The query returns numbers; the screen class formats (`ChartFormat::value/money/signed`) and translates.

## 3. Components (`resources/views/components/rc/chart/*`)

All take already-translated strings and plain data; geometry comes from
`App\Support\Ui\Charts\ChartGeometry`. Tones: `s1` accent · `s2` teal · `s3` amber · `s4` red · `s5` violet ·
`s6` gray · `ink` (lines). Additions use s1/s2/s5, reductions s3/s4/s6, red only for failures/churn.

| Component | Key props |
|---|---|
| `x-rc.chart.card` | `title`, `subtitle`, `link`, `linkLabel`, `flush` (tables); slots `actions`, `note` |
| `x-rc.chart.kpi` | `label`, `value` (formatted), `delta`, `unit` (`percent`\|`points`), `goodUp`, `compare`, `empty` (`not_tracked`\|`no_data`) |
| `x-rc.chart.delta` | `delta`, `unit`, `goodUp` — green/red by goodness, not direction |
| `x-rc.chart.combo` | `title`, `labels`, `bars` [{key,label,tone,values,sign?:-1}], `line` {label,tone,values,format?}, `mode` (`stacked`\|`grouped`), `format` (`number`\|`money`\|`percent`), `size` (`sm`\|`md`\|`lg`), `sharedAxis`, `caps` (labels over bars), `legend`, `id` |
| `x-rc.chart.bars` | combo without a line (simple, stacked, grouped) |
| `x-rc.chart.line` | `title`, `labels`, `series` [{key,label,tone,values,dashed?,area?}], `format`, `size`, `fromZero` |
| `x-rc.chart.donut` | `title`, `slices` [{label,value,tone,display?}], `centre`, `caption`, `format` |
| `x-rc.chart.hbars` | `title`, `rows` [{label,value,display?,tone?}], `format` |
| `x-rc.chart.funnel` | `title`, `stages` [{label,value,tone?,leaks?:[{label,value}]}] |
| `x-rc.chart.heatmap` | `title`, `columns`, `rows` [{label,size,cells:[pct\|null],display?}], `sizeLabel`, `rowLabel` |
| `x-rc.chart.table` | `columns` [{key,label,numeric?,strong?}], `rows` (cell = string or `['pill'=>tone,'text'=>…]`), `caption`, `empty` |
| `x-rc.chart.toggle` | `options` [value=>label], `active`, `action` (`setGrain`\|`setOption`), `target`, `label` |
| `x-rc.chart.legend` | `items` [{label,tone,shape: square\|line\|dashed}] |
| `x-rc.chart.numbers` | `rows` [{label,value\|null,kind: group\|total\|row,tone?,delta?,unit?,goodUp?}] — `null` value = not tracked |
| `x-rc.chart.pill` | `tone` (`good`\|`bad`\|`warn`\|`neutral`\|`info`), `label` |
| `x-rc.chart.empty` | `variant` (`no_data`\|`not_tracked`), `title`, `body`, `compact` |

Layout classes: `.rc-an-grid` (4 columns), `--2`, `--3`, `--main-side` (2fr 1fr), `.rc-an-span-2`;
`.rc-stat-grid`, `.rc-an-kpi-row` + `.rc-an-mini*` for KPI rows inside a card. Every chart carries an
`aria-label`, an SVG `<title>` and a visually-hidden data table. Charts mirror in RTL by CSS.

**Never** write `style="…"`, a Tailwind arbitrary value, or a hex colour in a view — the conventions test
fails the build. New colours/sizes go into `theme.css` as `--rc-*` tokens, classes into
`components/analytics.css`; rebuild with `node build-theme.mjs`.

## 4. The animation contract

Pure CSS in `components/analytics.css`, ≤ ~900 ms, ease-out, off under `prefers-reduced-motion`:
bars grow from the zero line (`.rc-chart__stack--pos/--neg`, staggered per column), lines draw themselves
(`pathLength="1"`), areas/dots/dashed lines fade in after, donut slices sweep, KPI values rise, hbars and
funnel stages grow, heatmap rows fade in.

It replays because **animated nodes are new nodes**: every chart component sets
`wire:key="chart-<id>-<hash of its data>"`, so when a toggle or filter changes a chart's data Livewire's
morph replaces that node (and only that node), and `wire:navigate` / a tab switch swaps in fresh markup
(`.rc-an-body` is keyed by section + tab). If you hand-roll markup, key it by its data the same way —
and give two charts with the same title distinct `id`s.

## 5. i18n

Shared shell + component copy: `lang/{en,he}/analytics.php` (`__('analytics.…')`). Your screen's copy:
`lang/{en,he}/analytics/<section>_<sub>.php`, read as `__('analytics/<section>_<sub>.key')`. Keys must
mirror EN↔HE exactly (`AnalyticsConventionsTest` checks every file in `lang/*/analytics/`). Hebrew:
**מנויים** = subscribers (people), **מינויים** = subscriptions (plans) — as the sketch does.
Movement labels exist at both levels in `analytics.movement.{subscriber,subscription}.*`.

## 6. Tests expected per screen

1. Tenant isolation for every query: build the same rows in a second shop and assert they are never
   counted (see `SubscribersOverviewQueryTest::test_another_shops_rows_are_never_counted`).
2. The numbers on a small hand-built fixture (both rails where relevant), incl. compare deltas.
3. The screen renders for an empty shop and with data (`Livewire::test(Analytics::class)->call('go', '<section>', '<sub>')`).
4. Anything not tracked renders the not-tracked state, not a zero.

Fixtures: `Tests\Feature\Analytics\Concerns\BuildsSubscriptions`. Run
`C:\Users\user\.config\herd\bin\php84\php.exe -d memory_limit=2G vendor/bin/phpunit tests/Feature/Analytics`.

## 7. Local preview

`.env` points at the LIVE database. Always prefix `APP_ENV=local` (→ `.env.local`, SQLite at
`storage/preview/preview.sqlite`). Seed: `APP_ENV=local php artisan db:seed --class=DemoShopSeeder` then
`--class=AnalyticsDemoSeeder` (13 months of plans, movements and ledger; refuses to run unless local +
SQLite). Serve: `APP_ENV=local php artisan serve --port=8010` → `/admin/analytics?section=…&tab=…`;
add `&locale=he` for RTL.
