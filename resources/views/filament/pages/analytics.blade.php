{{--
    Analytics — subscription KPIs + the subscribers trend (Loop-style overview).
    TOKENS: .rc-kpi-grid/.rc-section/.rc-pp-segment/.rc-chart* (published theme).
    ZERO inline CSS; the SVG is geometry only — every colour comes from classes.
    Renders only: AnalyticsMetrics aggregates, Analytics::chart() lays out.
--}}
<x-filament-panels::page>
    <div class="rc-stack">

        {{-- KPI cards --}}
        <div class="rc-kpi-grid">
            @foreach($this->kpis() as $kpi)
                <x-rc.kpi :label="$kpi['label']" :value="$kpi['value']" />
            @endforeach
        </div>

        {{-- Subscribers trend --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <div class="rc-section__title">{{ __('analytics.trend.title') }}</div>
                <div class="rc-pp-segment" role="group" aria-label="{{ __('analytics.trend.period') }}">
                    @foreach(\App\Filament\Pages\Analytics::RANGES as $key => $days)
                        <button type="button"
                                class="rc-pp-segment__item {{ $range === $key ? 'rc-pp-segment__item--active' : '' }}"
                                aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                                wire:click="selectRange('{{ $key }}')">
                            {{ __('analytics.trend.range.'.$key) }}
                        </button>
                    @endforeach
                </div>
            </div>

            @php $chart = $this->chart(); @endphp
            @if(! $chart['has_data'])
                <x-rc.empty title="analytics.trend.empty" icon="heroicon-o-chart-bar" />
            @else
                {{-- The time axis reads left-to-right like every date in the admin. --}}
                <div class="rc-chart rc-ltr">
                    <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img"
                         aria-label="{{ __('analytics.trend.title') }}" preserveAspectRatio="xMidYMid meet">
                        {{-- gridlines + axis labels for the active line --}}
                        @foreach($chart['y_ticks'] as $tick)
                            <line class="rc-chart__grid" x1="46" x2="{{ $chart['width'] - 34 }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" />
                            <text class="rc-chart__tick" x="38" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['label'] }}</text>
                        @endforeach

                        {{-- the bars' zero line + right-edge scale --}}
                        <line class="rc-chart__zero" x1="46" x2="{{ $chart['width'] - 34 }}" y1="{{ $chart['zero_y'] }}" y2="{{ $chart['zero_y'] }}" />
                        @foreach($chart['bar_ticks'] as $tick)
                            <text class="rc-chart__tick" x="{{ $chart['width'] - 26 }}" y="{{ $tick['y'] + 4 }}" text-anchor="start">{{ $tick['label'] }}</text>
                        @endforeach

                        {{-- new / cancelled bars (native <title> = the hover tooltip) --}}
                        @foreach($chart['bars'] as $bar)
                            <rect class="rc-chart__bar rc-chart__bar--{{ $bar['kind'] }}"
                                  x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['w'] }}" height="{{ $bar['h'] }}" rx="2">
                                <title>{{ $bar['title'] }}</title>
                            </rect>
                        @endforeach

                        {{-- the active-subscriptions line + day dots --}}
                        <polyline class="rc-chart__line" points="{{ $chart['line_points'] }}" />
                        @foreach($chart['dots'] as $dot)
                            <circle class="rc-chart__dot" cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="3.5">
                                <title>{{ $dot['title'] }}</title>
                            </circle>
                        @endforeach

                        {{-- date ticks --}}
                        @foreach($chart['x_ticks'] as $tick)
                            <text class="rc-chart__tick" x="{{ $tick['x'] }}" y="312" text-anchor="middle">{{ $tick['label'] }}</text>
                        @endforeach
                    </svg>
                </div>

                <div class="rc-chart-legend">
                    <span class="rc-chart-legend__item"><span class="rc-chart-legend__swatch rc-chart-legend__swatch--line"></span>{{ __('analytics.legend.active') }}</span>
                    <span class="rc-chart-legend__item"><span class="rc-chart-legend__swatch rc-chart-legend__swatch--new"></span>{{ __('analytics.legend.new') }}</span>
                    <span class="rc-chart-legend__item"><span class="rc-chart-legend__swatch rc-chart-legend__swatch--churn"></span>{{ __('analytics.legend.cancelled') }}</span>
                </div>

                {{-- An honest chart says what it is. --}}
                <span class="rc-muted">{{ __('analytics.trend.note') }}</span>
            @endif
        </div>
    </div>
</x-filament-panels::page>
