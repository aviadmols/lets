{{--
    rc.chart.combo — bars (simple / stacked / grouped, signed ±) with an optional line on its own end axis.
    The workhorse: rc.chart.bars is this component without a line.
    TOKENS: .rc-chart(--sm|--lg) .rc-chart__frame/__axis(--start|--end)/__plot/__svg/__xaxis/__caps
            .rc-chart__grid/__zero/__cols/__col/__stack(--pos|--neg)/__bar/__line/__dot .rc-tone--*
    GEOMETRY: App\Support\Ui\Charts\ChartGeometry::columns() — attributes only, never inline style.
    MOTION: stacks grow from the zero line, staggered per column; the line draws itself (pathLength="1").
            wire:key hashes the data, so a changed chart is a NEW node and its animation replays.
    Props:
      title      — already-translated chart title (svg <title>, aria-label, hidden table caption)
      labels     — list<string> one per bucket, oldest first
      bars       — list<{key, label (translated), tone: s1…s6, values: list<number>, sign?: -1 (hangs below zero)}>
      line       — null | {label, tone?: ink|s1…, values: list<number|null>, format?}
      mode       — 'stacked' | 'grouped'
      format     — 'number' | 'money' | 'percent' (ChartFormat)
      size       — 'sm' | 'md' | 'lg'
      sharedAxis — draw the line against the bar axis instead of its own end axis
      caps       — optional list<string> printed above each bucket (e.g. success % over a bar)
      legend     — true = derive from series; false = none; or an explicit legend items list
      id         — stable id when two charts on a page could share a title
--}}
@props([
    'title',
    'labels' => [],
    'bars' => [],
    'line' => null,
    'mode' => 'stacked',
    'format' => 'number',
    'size' => 'md',
    'sharedAxis' => false,
    'caps' => null,
    'legend' => true,
    'id' => null,
])
@php
    $g = \App\Support\Ui\Charts\ChartGeometry::columns($labels, $bars, $line, $mode, $format, $size, (bool) $sharedAxis);
    $chartKey = 'chart-'.($id ?? \Illuminate\Support\Str::slug($title)).'-'
        .substr(md5((string) json_encode([$labels, $bars, $line, $mode, $format, $size, app()->getLocale()])), 0, 12);
    $legendItems = is_array($legend) ? $legend : ($legend ? array_merge(
        array_map(static fn (array $s): array => ['label' => $s['label'], 'tone' => $s['tone']], $bars),
        $line ? [['label' => $line['label'], 'tone' => $line['tone'] ?? 'ink', 'shape' => 'line']] : [],
    ) : []);
    $points = $g['line']['points'] ?? [];
    $dots = count($points) <= 16 ? $points : array_slice($points, -1);
@endphp
<figure {{ $attributes->class(['rc-chart', 'rc-chart--'.$size]) }} wire:key="{{ $chartKey }}">
    @if($legendItems !== [] && $g['has_data'])
        <x-rc.chart.legend :items="$legendItems" />
    @endif

    @if(! $g['has_data'])
        <x-rc.chart.empty variant="no_data" />
    @else
        <div class="rc-chart__frame">
            @if($caps)
                <div class="rc-chart__caps" aria-hidden="true">
                    @foreach($caps as $cap)<span class="rc-iso">{{ $cap }}</span>@endforeach
                </div>
            @endif

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
                    <line class="rc-chart__zero" x1="0" x2="{{ $g['w'] }}" y1="{{ $g['zero'] }}" y2="{{ $g['zero'] }}" vector-effect="non-scaling-stroke" />

                    <g class="rc-chart__cols">
                        @foreach($g['cols'] as $col)
                            <g class="rc-chart__col">
                                @if($mode === 'grouped')
                                    @foreach($col['groups'] as $bar)
                                        @if($bar['h'] > 0)
                                            <g @class(['rc-chart__stack', 'rc-chart__stack--neg' => $bar['neg'], 'rc-chart__stack--pos' => ! $bar['neg']])>
                                                <rect class="rc-chart__bar rc-tone--{{ $bar['tone'] }}" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['w'] }}" height="{{ $bar['h'] }}"><title>{{ $bar['title'] }}</title></rect>
                                            </g>
                                        @endif
                                    @endforeach
                                @else
                                    @if($col['pos'] !== [])
                                        <g class="rc-chart__stack rc-chart__stack--pos">
                                            @foreach($col['pos'] as $seg)
                                                <rect class="rc-chart__bar rc-tone--{{ $seg['tone'] }}" x="{{ $seg['x'] }}" y="{{ $seg['y'] }}" width="{{ $seg['w'] }}" height="{{ $seg['h'] }}"><title>{{ $seg['title'] }}</title></rect>
                                            @endforeach
                                        </g>
                                    @endif
                                    @if($col['neg'] !== [])
                                        <g class="rc-chart__stack rc-chart__stack--neg">
                                            @foreach($col['neg'] as $seg)
                                                <rect class="rc-chart__bar rc-tone--{{ $seg['tone'] }}" x="{{ $seg['x'] }}" y="{{ $seg['y'] }}" width="{{ $seg['w'] }}" height="{{ $seg['h'] }}"><title>{{ $seg['title'] }}</title></rect>
                                            @endforeach
                                        </g>
                                    @endif
                                @endif
                            </g>
                        @endforeach
                    </g>

                    @if($g['line'] && $g['line']['d'] !== '')
                        <g class="rc-tone--{{ $g['line']['tone'] }}">
                            <path class="rc-chart__line" d="{{ $g['line']['d'] }}" pathLength="1" vector-effect="non-scaling-stroke" />
                            @foreach($dots as [$x, $y, $tip])
                                <path class="rc-chart__dot" d="M{{ $x }} {{ $y }}h0" vector-effect="non-scaling-stroke"><title>{{ $tip }}</title></path>
                            @endforeach
                        </g>
                    @endif
                </svg>
            </div>

            @if($g['end_ticks'])
                <div class="rc-chart__axis rc-chart__axis--end" aria-hidden="true">
                    @foreach($g['end_ticks'] as $tick)<span>{{ $tick }}</span>@endforeach
                </div>
            @endif

            <div class="rc-chart__xaxis" aria-hidden="true">
                @foreach($g['x_labels'] as $x)<span>{{ $x['show'] ? $x['text'] : '' }}</span>@endforeach
            </div>
        </div>

        {{-- The same numbers for a screen reader (and for anyone who prefers a table). --}}
        <table class="rc-sr-only">
            <caption>{{ $title }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('analytics.table.period') }}</th>
                    @foreach($bars as $s)<th scope="col">{{ $s['label'] }}</th>@endforeach
                    @if($line)<th scope="col">{{ $line['label'] }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach($labels as $i => $label)
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        @foreach($bars as $s)<td>{{ \App\Support\Ui\Charts\ChartFormat::value(($s['sign'] ?? 1) * (float) ($s['values'][$i] ?? 0), $format) }}</td>@endforeach
                        @if($line)<td>{{ \App\Support\Ui\Charts\ChartFormat::value($line['values'][$i] ?? null, $line['format'] ?? $format) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</figure>
