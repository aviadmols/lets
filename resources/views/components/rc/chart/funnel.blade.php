{{--
    rc.chart.funnel — stages stacked vertically; each bar's width is its share of the FIRST stage; leaks list beside it.
    TOKENS: .rc-funnel .rc-funnel__stage/__bar/__shape/__text/__name/__leaks .rc-tone--*
    GEOMETRY: App\Support\Ui\Charts\ChartGeometry::funnel() — x/width attributes in a 0–100 viewBox.
    MOTION: each stage grows from its centre, staggered.
    Props:
      title   — already-translated title (aria)
      stages  — list<{label (translated), value: number, tone?: s1…s6, leaks?: list<{label, value}>}>
      format  — how values print
--}}
@props(['title', 'stages' => [], 'format' => 'number', 'id' => null])
@php
    $g = \App\Support\Ui\Charts\ChartGeometry::funnel($stages);
    $fmt = fn ($v) => \App\Support\Ui\Charts\ChartFormat::value($v, $format);
    $chartKey = 'funnel-'.($id ?? \Illuminate\Support\Str::slug($title)).'-'.substr(md5((string) json_encode([$stages, app()->getLocale()])), 0, 12);
@endphp
@if($g === [] || (float) $g[0]['value'] <= 0)
    <x-rc.chart.empty variant="no_data" />
@else
    <ol {{ $attributes->class(['rc-funnel']) }} aria-label="{{ $title }}" wire:key="{{ $chartKey }}">
        @foreach($g as $i => $stage)
            <li class="rc-funnel__stage">
                <svg class="rc-funnel__bar" viewBox="0 0 100 44" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <rect class="rc-funnel__shape rc-tone--{{ $stage['tone'] ?? ($i === 0 ? 's1' : 's2') }}" x="{{ $stage['x'] }}" y="0" width="{{ $stage['w'] }}" height="44" rx="1.5" />
                </svg>
                <div class="rc-funnel__text">
                    <span class="rc-funnel__name">{{ $stage['label'] }}</span>
                    <span class="rc-iso">{{ $fmt($stage['value']) }} · {{ number_format($stage['share'], 1) }}%</span>
                    @if(! empty($stage['leaks']))
                        <ul class="rc-funnel__leaks">
                            @foreach($stage['leaks'] as $leak)<li>{{ $leak['label'] }} <span class="rc-iso">{{ $fmt($leak['value']) }}</span></li>@endforeach
                        </ul>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
