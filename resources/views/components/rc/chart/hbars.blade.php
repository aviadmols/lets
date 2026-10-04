{{--
    rc.chart.hbars — ranked horizontal bars (by product, plan, reason): label | track | value.
    The longest row is 100% of the track. Each track is a tiny SVG so the fill is a geometry ATTRIBUTE.
    TOKENS: .rc-hbars .rc-hbar .rc-hbar__label/__track/__rail/__fill/__value .rc-tone--*
    MOTION: fills grow from the inline start (the track mirrors in RTL), staggered per row.
    Props:
      title   — already-translated title (aria)
      rows    — list<{label (translated), value: number, display?: string, tone?: s1…s6}>
      format  — how values print when `display` is absent
      tone    — default tone for rows without one
--}}
@props(['title', 'rows' => [], 'format' => 'number', 'tone' => 's1', 'id' => null])
@php
    $g = \App\Support\Ui\Charts\ChartGeometry::hbars($rows);
    $chartKey = 'hbars-'.($id ?? \Illuminate\Support\Str::slug($title)).'-'.substr(md5((string) json_encode([$rows, app()->getLocale()])), 0, 12);
@endphp
@if($g === [])
    <x-rc.chart.empty variant="no_data" />
@else
    <ol {{ $attributes->class(['rc-hbars']) }} aria-label="{{ $title }}" wire:key="{{ $chartKey }}">
        @foreach($g as $row)
            <li class="rc-hbar">
                <span class="rc-hbar__label">{{ $row['label'] }}</span>
                <svg class="rc-hbar__track" viewBox="0 0 100 12" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <rect class="rc-hbar__rail" x="0" y="0" width="100" height="12" rx="6" />
                    @if($row['pct'] > 0)
                        <rect class="rc-hbar__fill rc-tone--{{ $row['tone'] ?? $tone }}" x="{{ $row['x'] }}" y="0" width="{{ $row['pct'] }}" height="12" rx="6" />
                    @endif
                </svg>
                <span class="rc-hbar__value rc-iso">{{ $row['display'] ?? \App\Support\Ui\Charts\ChartFormat::value($row['value'], $format) }}</span>
            </li>
        @endforeach
    </ol>
@endif
