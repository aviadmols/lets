{{--
    Revenue forecast — expected charges per day + "if the pace holds" (App\Filament\Pages\Insights\RevenueForecast).
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Pages\Insights\RevenueForecast;
    $s = $this->screen();
    $p = $s['pace'];
@endphp
<x-filament-panels::page>
    <div class="rc-ins">
        <p class="rc-ins-lead">{{ __('insights.forecast.lead') }}</p>

        <div class="rc-ins-controls">
            <div class="rc-ins-seg" role="group" aria-label="{{ __('insights.range.label') }}">
                @foreach (RevenueForecast::AHEAD as $option)
                    <button type="button" wire:click="setAhead('{{ $option }}')" aria-pressed="{{ $ahead === $option ? 'true' : 'false' }}">{{ __('insights.forecast.range.'.$option) }}</button>
                @endforeach
            </div>
        </div>

        <x-rc.insights.hero
            :eyebrow="__('insights.forecast.eyebrow', ['ahead' => __('insights.forecast.ahead.'.$ahead)])"
            :big="RevenueForecast::money($s['total'])"
            :chart="$s['chart']"
        >
            {!! __('insights.forecast.say', ['charges' => '<b>'.number_format($s['charges']).'</b>', 'average' => e(RevenueForecast::money($s['average']))]) !!}
            <x-slot:tiles>
                <x-rc.insights.tile icon="heroicon-o-calendar" :accent="true"
                    :value="$s['busiest'] ? \Carbon\Carbon::parse($s['busiest']['key'])->format('d/m/Y') : '—'"
                    :label="$s['busiest'] ? __('insights.forecast.tile_busiest', ['amount' => RevenueForecast::money($s['busiest']['amount'])]) : __('insights.forecast.tile_busiest_none')" />
                <x-rc.insights.tile icon="heroicon-o-users" :value="number_format($s['busiest']['count'] ?? 0)" :label="__('insights.forecast.tile_busiest_count')" />
                <x-rc.insights.tile icon="heroicon-o-banknotes" :value="RevenueForecast::money($s['average'])" :label="__('insights.forecast.tile_average')" />
            </x-slot:tiles>
        </x-rc.insights.hero>

        <section class="rc-ins-card">
            <div class="rc-ins-card__head">
                <div>
                    <h2 class="rc-ins-card__title">{{ __('insights.forecast.pace') }}</h2>
                    <p class="rc-ins-card__sub">{{ __('insights.forecast.pace_sub', ['joined' => number_format($p['joined']), 'left' => number_format($p['left']), 'net' => ($p['net'] >= 0 ? '+' : '').number_format($p['net'])]) }}</p>
                </div>
                @if ($p['change'] !== null)
                    @php $dir = $p['change'] > 0 ? 'up' : ($p['change'] < 0 ? 'down' : 'flat'); @endphp
                    <span class="rc-ins-trend rc-ins-trend--{{ $dir }}">{{ __('insights.forecast.pace_trend', ['arrow' => ['up' => '▲', 'down' => '▼', 'flat' => '·'][$dir], 'percent' => abs($p['change'])]) }}</span>
                @endif
            </div>
            <div class="rc-ins-pace">
                @foreach ($p['steps'] as $step)
                    <div @class(['rc-ins-pace__col', 'rc-ins-pace__col--now' => $step['months'] === 0])>
                        <div class="rc-ins-pace__k">{{ [__('insights.forecast.pace_now'), __('insights.forecast.pace_one'), __('insights.forecast.pace_two')][$step['months']] }}</div>
                        <div class="rc-ins-pace__v">{{ number_format($step['subscribers']) }} <span class="rc-ins-pace__k">{{ __('insights.forecast.pace_subs') }}</span></div>
                        <div class="rc-ins-pace__r">{{ __('insights.forecast.pace_revenue', ['amount' => RevenueForecast::money($step['revenue'])]) }}</div>
                    </div>
                @endforeach
            </div>
            <p class="rc-ins-note">@if (! $p['comparable']){{ __('insights.forecast.pace_unfair') }} @endif{{ __('insights.forecast.pace_note') }}</p>
        </section>

        <section class="rc-ins-card">
            <h2 class="rc-ins-card__title">{{ __('insights.forecast.table_title') }}</h2>
            <p class="rc-ins-card__sub">{{ __('insights.forecast.table_sub') }}</p>
            @if ($s['table'] === [])
                <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
            @else
                <div class="rc-ins-scroll">
                    <table class="rc-ins-table">
                        <thead><tr>
                            <th>{{ __('insights.col.date') }}</th><th>{{ __('insights.forecast.col_day') }}</th>
                            <th class="rc-ins-num">{{ __('insights.forecast.col_count') }}</th><th class="rc-ins-num">{{ __('insights.forecast.col_amount') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($s['table'] as $row)
                                <tr wire:key="f-{{ $row['key'] }}">
                                    <td class="rc-ins-strong">{{ \Carbon\Carbon::parse($row['key'])->format('d/m/Y') }}</td>
                                    <td>{{ \Carbon\Carbon::parse($row['key'])->locale(app()->getLocale())->translatedFormat('l') }}</td>
                                    <td class="rc-ins-num">{{ number_format($row['count']) }}</td>
                                    <td class="rc-ins-num">{{ RevenueForecast::money($row['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
