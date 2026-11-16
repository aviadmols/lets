{{--
    Analytics › Cohorts (spec §2). Retention reads as CHURN — where people leave —
    every other metric keeps the heatmap.
    Draws only: App\Filament\Pages\Analytics\Screens\Cohorts::data() shapes every prop.
    TOKENS: .rc-an-card + rc.chart.heatmap/hbars/line/kpi/toggle/empty; controls partial (.rc-an-btn .rc-an-menu*);
            .rc-an-insight* .rc-churn* .rc-churn-legend* (components/analytics-cohorts.css)
    Vars: everything Cohorts::data() returns, plus $context.
--}}
@php $l = 'analytics/cohorts_overview.'; @endphp

@if($churn)
    {{-- Controls --}}
    <div class="rc-row rc-row--between">
        <span class="rc-an-mini__label">{{ $title }}</span>
        <div class="rc-an-card__actions">
            @include('filament.pages.analytics.cohorts.controls')
        </div>
    </div>

    {{-- The sentence the screen opens with --}}
    @if($churn['insight'])
        <div class="rc-an-insight" role="note">
            <x-heroicon-o-light-bulb class="rc-an-insight__icon" />
            <div class="rc-an-insight__text">
                <strong class="rc-an-insight__title">{{ $churn['insight']['title'] }}</strong>
                <span class="rc-an-insight__body">{{ $churn['insight']['body'] }}</span>
            </div>
        </div>
    @endif

    {{-- Still active after 1 / 2 / 3 months, and who left --}}
    <div class="rc-an-grid">
        @foreach($churn['kpis'] as $kpi)
            <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :compare="$kpi['note']" />
        @endforeach
    </div>

    {{-- When they leave + the curve --}}
    <div class="rc-an-grid rc-an-grid--2">
        <x-rc.chart.card :title="__($l.'churn.drops.title')" :subtitle="__($l.'churn.drops.subtitle')">
            <x-rc.chart.hbars id="cohort-drops" :title="__($l.'churn.drops.title')" :rows="$churn['drops']" tone="s3" />
            <x-slot name="note">{{ __($l.'churn.drops.note') }}</x-slot>
        </x-rc.chart.card>

        <x-rc.chart.card :title="__($l.'churn.curve.title')" :subtitle="__($l.'churn.curve.subtitle')">
            <x-rc.chart.line id="cohort-curve" :title="__($l.'churn.curve.title')" format="percent" :legend="false"
                             :labels="$churn['curve']['labels']" :series="$churn['curve']['series']" />
            <x-slot name="note">{{ __($l.'churn.curve.note') }}</x-slot>
        </x-rc.chart.card>
    </div>

    {{-- The table: who stayed, and how many left, per cell --}}
    <x-rc.chart.card flush :title="__($l.'churn.table.title')" :subtitle="__($l.'churn.table.subtitle')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="[
                    \App\Filament\Pages\Analytics\Screens\Cohorts::EMPTY_HIDE => __($l.'churn.table.hide_empty'),
                    \App\Filament\Pages\Analytics\Screens\Cohorts::EMPTY_SHOW => __($l.'churn.table.show_empty'),
                ]" :active="$empty" action="setOption" target="empty" :label="__($l.'churn.table.hide_empty')" />
        </x-slot>

        <div class="rc-an-table__wrap">
            <table class="rc-churn">
                <caption class="rc-sr-only">{{ __($l.'churn.table.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __($l.'churn.table.joined') }}</th>
                        <th scope="col" class="rc-num">{{ $churn['table']['people'] }}</th>
                        @foreach($churn['table']['columns'] as $col)<th scope="col">{{ $col }}</th>@endforeach
                        <th scope="col" class="rc-num">{{ __($l.'churn.table.churned') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($churn['table']['rows'] as $row)
                        <tr>
                            <th scope="row" class="rc-churn__cohort">
                                {{ $row['label'] }}
                                @if($row['flag'])<span class="rc-churn__flag">{{ $row['flag'] }}</span>@endif
                            </th>
                            <td class="rc-num rc-iso">{{ $row['size'] }}</td>
                            @foreach($row['cells'] as $cell)
                                <td>
                                    <span @class(['rc-churn__cell', 'rc-churn__cell--'.$cell['band']])>
                                        <span class="rc-churn__main rc-iso">{{ $cell['main'] }}</span>
                                        <span class="rc-churn__left">{{ $cell['left'] }}</span>
                                    </span>
                                </td>
                            @endforeach
                            <td class="rc-num">
                                <span class="rc-iso">{{ $row['churned'] }}</span>
                                @if($row['churned_pct'])<span class="rc-churn__pct rc-iso">({{ $row['churned_pct'] }})</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-slot name="note">
            <span class="rc-churn-legend">
                @foreach(['high', 'mid', 'low', 'small'] as $band)
                    <span class="rc-churn-legend__item"><span @class(['rc-churn-legend__swatch', 'rc-churn__cell--'.$band])></span>{{ __($l.'churn.table.legend.'.$band) }}</span>
                @endforeach
                <span class="rc-churn-legend__item">{{ __($l.'churn.table.legend.empty') }}</span>
            </span>
            {{ $formula }}
        </x-slot>
    </x-rc.chart.card>
@else
    <x-rc.chart.card :title="$title" :subtitle="__($l.'subtitle')">
        <x-slot name="actions">
            @include('filament.pages.analytics.cohorts.controls')
        </x-slot>

        @if(! $has_data)
            <x-rc.chart.empty variant="no_data" :title="__($l.'empty.title')" :body="__($l.'empty.body')" />
        @else
            <x-rc.chart.heatmap id="cohorts" :title="$title" :columns="$heat['columns']" :rows="$heat['rows']"
                                :size-label="$size_label" />
        @endif

        <x-slot name="note">
            {{ $formula }}@if($cumulative) {{ __($l.'cumulative_note') }}@endif
            @if($contracts_note) {{ $contracts_note }}@endif
        </x-slot>
    </x-rc.chart.card>
@endif
