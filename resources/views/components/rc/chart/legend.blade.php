{{--
    rc.chart.legend — series legend. Squares for bars/slices, a bar for a line, a dashed bar for a compared period.
    TOKENS: .rc-legend .rc-legend__item .rc-legend__swatch(--line|--dashed) .rc-tone--*
    Props: items — list<{label: string (translated), tone: s1…s6|ink, shape?: square|line|dashed}>
--}}
@props(['items' => []])
<div {{ $attributes->class(['rc-legend']) }}>
    @foreach($items as $item)
        <span class="rc-legend__item">
            <span @class([
                'rc-legend__swatch',
                'rc-tone--'.($item['tone'] ?? 's1'),
                'rc-legend__swatch--line' => ($item['shape'] ?? 'square') === 'line',
                'rc-legend__swatch--dashed' => ($item['shape'] ?? 'square') === 'dashed',
            ]) aria-hidden="true"></span>{{ $item['label'] }}
        </span>
    @endforeach
</div>
