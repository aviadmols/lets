{{--
    rc.chart.bars — vertical bars without a line: simple (one series), stacked, or grouped (mode="grouped").
    A thin alias of rc.chart.combo (same geometry, same motion, same accessibility).
    TOKENS: see rc.chart.combo
    Props: title, labels, bars, mode ('stacked'|'grouped'), format, size, caps, legend, id — as rc.chart.combo
--}}
@props([
    'title',
    'labels' => [],
    'bars' => [],
    'mode' => 'stacked',
    'format' => 'number',
    'size' => 'md',
    'caps' => null,
    'legend' => true,
    'id' => null,
])
<x-rc.chart.combo {{ $attributes }} :title="$title" :labels="$labels" :bars="$bars" :line="null"
                  :mode="$mode" :format="$format" :size="$size" :caps="$caps" :legend="$legend" :id="$id" />
