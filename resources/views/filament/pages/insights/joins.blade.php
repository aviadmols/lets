{{--
    Joins — new subscribers per day / month (App\Filament\Pages\Insights\Joins).
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    $s = $this->screen();
    $money = fn ($v) => \App\Filament\Pages\Insights\Joins::money($v);
@endphp
<x-filament-panels::page>
    <div class="rc-ins">
        <p class="rc-ins-lead">
            @if ($s['mood'])<span class="rc-ins-mood rc-ins-mood--{{ $s['mood'] }}">{{ __('insights.mood.'.$s['mood']) }}</span>@endif
            {{ $s['since'] ? __('insights.joins.lead', ['date' => \Carbon\Carbon::parse($s['since'])->format('d/m/Y')]) : __('insights.joins.lead_empty') }}
        </p>

        <x-rc.insights.controls :range="$range" :grain="$grain" />

        <x-rc.insights.hero
            :eyebrow="__('insights.joins.eyebrow', ['window' => $this->windowWords()])"
            :big="number_format($s['total'])"
            :chart="$s['chart']"
        >
            @if ($s['previous'] !== null)
                {!! __('insights.joins.say_compare', ['previous' => '<b>'.number_format($s['previous']).'</b>', 'average' => e($s['average'])]) !!}
            @else
                {{ __('insights.joins.say', ['average' => $s['average']]) }}
            @endif

            <x-slot:tiles>
                <x-rc.insights.tile icon="heroicon-o-star" :accent="true"
                    :value="$s['best'] ? number_format($s['best']['count']) : '0'"
                    :label="$s['best'] ? __('insights.joins.best_day', ['date' => \Carbon\Carbon::parse($s['best']['day'])->format('d/m')]) : __('insights.joins.best_day_none')" />
                <x-rc.insights.tile icon="heroicon-o-calendar" :value="$s['average']" :label="__('insights.joins.average')" />
                <x-rc.insights.tile icon="heroicon-o-user-plus" :value="number_format($s['recent'])" :label="__('insights.joins.recent', ['days' => \App\Filament\Pages\Insights\Joins::RECENT_DAYS])" />
            </x-slot:tiles>
        </x-rc.insights.hero>

        <div class="rc-ins-two">
        <section class="rc-ins-card">
            <h2 class="rc-ins-card__title">{{ __('insights.joins.products_title') }}</h2>
            <p class="rc-ins-card__sub">{{ __('insights.joins.products_sub', ['count' => number_format($s['total']), 'window' => $this->windowWords()]) }}</p>
            <x-rc.insights.hbars :rows="collect($s['by_product'])->map(fn ($n, $title) => ['label' => $title, 'value' => $n, 'text' => number_format($n).' · '.\App\Filament\Pages\Insights\Joins::percent($n, $s['total'])])->values()->all()" />
        </section>

        <section class="rc-ins-card">
            <h2 class="rc-ins-card__title">{{ __('insights.joins.table_title') }}</h2>
            <p class="rc-ins-card__sub">{{ $grain === 'month' ? __('insights.joins.table_sub_month') : __('insights.joins.table_sub_day') }} · {{ $this->windowWords() }}</p>
            @if ($s['table'] === [])
                <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
            @else
                <div class="rc-ins-scroll">
                    <table class="rc-ins-table">
                        <thead><tr><th>{{ $grain === 'month' ? __('insights.col.month') : __('insights.col.date') }}</th><th class="rc-ins-num">{{ __('insights.joins.col_joined') }}</th></tr></thead>
                        <tbody>
                            @foreach ($s['table'] as $row)
                                <tr wire:key="j-{{ $row['key'] }}">
                                    <td class="rc-ins-strong">{{ $this->longLabel($row['key']) }}</td>
                                    <td class="rc-ins-num">{{ number_format($row['n']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        </div>

        {{-- Who joined, and to what — the same window. Each name opens the subscription. --}}
        <section class="rc-ins-card">
            <h2 class="rc-ins-card__title">{{ __('insights.joins.people_title', ['count' => count($s['people'])]) }}</h2>
            <p class="rc-ins-card__sub">{{ __('insights.joins.people_sub') }}</p>
            @if ($s['people'] === [])
                <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
            @else
                <div class="rc-ins-scroll">
                    <table class="rc-ins-table">
                        <thead><tr><th>{{ __('insights.col.customer') }}</th><th>{{ __('insights.joins.col_subscription') }}</th><th>{{ __('insights.joins.col_joined_on') }}</th></tr></thead>
                        <tbody>
                            @foreach ($s['people'] as $person)
                                <tr wire:key="p-{{ $person['plan'] }}">
                                    <td><x-rc.insights.who :id="$person['plan']" :name="$person['name']" /></td>
                                    <td>{{ $person['product'] !== '' ? $person['product'] : '—' }}</td>
                                    <td>{{ $this->longLabel($person['day']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
