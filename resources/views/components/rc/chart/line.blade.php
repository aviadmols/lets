{{--
    rc.chart.line — one or more lines over time; a compared period is a dashed gray series; optional area under a series.
    TOKENS: .rc-chart(--sm|--lg) .rc-chart__frame/__axis--start/__plot/__svg/__xaxis .rc-chart__grid/__line(--dashed)/__area/__dot .rc-tone--*
    GEOMETRY: App\Support\Ui\Charts\ChartGeometry::lines()
    MOTION: solid lines draw themselves (pathLength="1"), dashed lines and areas fade in after.
    Props:
      title     — already-translated title
      labels    — list<string> one per bucket
      series    — list<{key, label (translated), tone: s1…s6|ink, values: list<number|null>, dashed?: bool, area?: bool}>
      format    — 'number' | 'money' | 'percent'
      size      — 'sm' | 'md' | 'lg'
      fromZero  — start the axis at 0 (default) or fit it to the data
      legend    — true (derive) | false | explicit items
      id        — stable id when titles repeat
--}}
@props([
    'title',
    'labels' => [],
    'series' => [],
    'format' => 'number',
    'size' => 'md',
    'fromZero' => true,
    'legend' => true,
    'id' => null,
])
@php
    $g = \App\Support\Ui\Charts\ChartGeometry::lines($labels, $series, $format, $size, (bool) $fromZero);
    $chartKey = 'chart-'.($id ?? \Illuminate\Support\Str::slug($title)).'-'
        .substr(md5((string) json_encode([$labels, $series, $format, $size, app()->getLocale()])), 0, 12);
    $legendItems = is_array($legend) ? $legend : ($legend ? array_map(static fn (array $s): array => [
        'label' => $s['label'], 'tone' => $s['tone'], 'shape' => ! empty($s['dashed']) ? 'dashed' : 'line',
    ], $series) : []);
@endphp
<figure {{ $attributes->class(['rc-chart', 'rc-chart--'.$size]) }} wire:key="{{ $chartKey }}">
    @if($legendItems !== [] && $g['has_data'])
        <x-rc.chart.legend :items="$legendItems" />
    @endif

    @if(! $g['has_data'])
        <x-rc.chart.empty variant="no_data" />
    @else
        <div class="rc-chart__frame">
            <div class="rc-chart__axis rc-chart__axis--start" aria-hidden="true">
                @foreach($g['start_ticks'] as $tick)<span>{{ $tick }}</span>@endforeach
            </div>
            <div class="rc-chart__plot">
                <svg class="rc-chart__svg" viewBox="0 0 {{ $g['w'] }} {{ $g['h'] }}" preserveAspectRatio="none"
                     role="img" aria-label="{{ $title }}" focusable="false">
                    <title>{{ $title }}</title>
                    @foreach($g['grid'] as $y)
                        <line class="rc-chart__grid" x1="0" x2="{{ $g['w'] }}" y1="{{ $y }}" y2="{{ $y }}" vector-effect="non-scaling-stroke" />
                    @endforeach
                    @foreach($g['series'] as $s)
                        @if($s['d'] !== '')
                            <g class="rc-tone--{{ $s['tone'] }}">
                                @if($s['area'])<path class="rc-chart__area" d="{{ $s['area'] }}" />@endif
                                @if($s['dashed'])
                                    <path class="rc-chart__line rc-chart__line--dashed" d="{{ $s['d'] }}" vector-effect="non-scaling-stroke" />
                                @else
                                    <path class="rc-chart__line" d="{{ $s['d'] }}" pathLength="1" vector-effect="non-scaling-stroke" />
                                @endif
                                @php $points = $s['points']; $dots = count($points) <= 16 ? $points : array_slice($points, -1); @endphp
                                @foreach($dots as [$x, $y, $tip])
                                    <path @class(['rc-chart__dot', 'rc-chart__dot--quiet' => $s['dashed']]) d="M{{ $x }} {{ $y }}h0" vector-effect="non-scaling-stroke"><title>{{ $tip }}</title></path>
                                @endforeach
                            </g>
                        @endif
                    @endforeach
                </svg>
            </div>
            <div class="rc-chart__xaxis" aria-hidden="true">
                @foreach($g['x_labels'] as $x)<span>{{ $x['show'] ? $x['text'] : '' }}</span>@endforeach
            </div>
        </div>

        <table class="rc-sr-only">
            <caption>{{ $title }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('analytics.table.period') }}</th>
                    @foreach($series as $s)<th scope="col">{{ $s['label'] }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($labels as $i => $label)
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        @foreach($series as $s)<td>{{ \App\Support\Ui\Charts\ChartFormat::value($s['values'][$i] ?? null, $format) }}</td>@endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</figure>
