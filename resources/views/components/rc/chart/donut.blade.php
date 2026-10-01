{{--
    rc.chart.donut — shares of one whole, with the total in the centre and a legend of label · value · share.
    TOKENS: .rc-donut .rc-donut__figure/__svg/__track/__slice/__centre/__value/__caption/__legend/__name/__num/__pct .rc-tone--*
    GEOMETRY: App\Support\Ui\Charts\ChartGeometry::donut() — stroke-dasharray/-offset ATTRIBUTES on pathLength="100" circles.
    MOTION: each slice sweeps in from zero (keyframes animate TO the attribute value), staggered.
    Props:
      title    — already-translated title
      slices   — list<{label (translated), value: number, tone: s1…s6, display?: string}>
      centre   — the centre value (already formatted); defaults to the formatted total
      caption  — already-translated caption under the centre value
      format   — how values print when `display` is absent
--}}
@props([
    'title',
    'slices' => [],
    'centre' => null,
    'caption' => null,
    'format' => 'number',
    'id' => null,
])
@php
    $g = \App\Support\Ui\Charts\ChartGeometry::donut($slices);
    $chartKey = 'donut-'.($id ?? \Illuminate\Support\Str::slug($title)).'-'
        .substr(md5((string) json_encode([$slices, $centre, $caption, app()->getLocale()])), 0, 12);
@endphp
<figure {{ $attributes->class(['rc-donut']) }} wire:key="{{ $chartKey }}">
    @if($g['total'] <= 0)
        <x-rc.chart.empty variant="no_data" />
    @else
        <div class="rc-donut__figure">
            <svg class="rc-donut__svg" viewBox="0 0 42 42" role="img" aria-label="{{ $title }}" focusable="false">
                <title>{{ $title }}</title>
                <circle class="rc-donut__track" cx="21" cy="21" r="15.9" pathLength="100" />
                @foreach($g['slices'] as $slice)
                    @if($slice['dash'] > 0)
                        <circle class="rc-donut__slice rc-tone--{{ $slice['tone'] }}" cx="21" cy="21" r="15.9" pathLength="100"
                                stroke-dasharray="{{ $slice['dash'] }} {{ $slice['gap'] }}" stroke-dashoffset="{{ $slice['offset'] }}">
                            <title>{{ $slice['label'] }}: {{ $slice['display'] ?? \App\Support\Ui\Charts\ChartFormat::value($slice['value'], $format) }} ({{ $slice['pct'] }}%)</title>
                        </circle>
                    @endif
                @endforeach
            </svg>
            <div class="rc-donut__centre" aria-hidden="true">
                <span class="rc-donut__value rc-ltr">{{ $centre ?? \App\Support\Ui\Charts\ChartFormat::value($g['total'], $format) }}</span>
                @if($caption)<span class="rc-donut__caption">{{ $caption }}</span>@endif
            </div>
        </div>
        <dl class="rc-donut__legend">
            @foreach($g['slices'] as $slice)
                <dt><span class="rc-legend__swatch rc-tone--{{ $slice['tone'] }}" aria-hidden="true"></span><span class="rc-donut__name">{{ $slice['label'] }}</span></dt>
                <dd class="rc-donut__num rc-ltr">{{ $slice['display'] ?? \App\Support\Ui\Charts\ChartFormat::value($slice['value'], $format) }}</dd>
                <dd class="rc-donut__pct rc-ltr">{{ number_format($slice['pct'], $slice['pct'] < 10 ? 1 : 0) }}%</dd>
            @endforeach
        </dl>
    @endif
</figure>
