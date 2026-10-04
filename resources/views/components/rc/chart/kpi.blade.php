{{--
    rc.chart.kpi — Analytics KPI card: label, value (rises in), delta chip + "vs …" caption.
    TOKENS: kpi-card.css (.rc-kpi .rc-kpi__label/__value/__foot/__compare) + analytics.css (.rc-delta, motion)
    Props:
      label    — already-translated label
      value    — preformatted value string; null (or `empty` set) renders the empty state
      delta    — signed change (null hides the chip)
      unit     — 'percent' | 'points'
      goodUp   — false when up is bad (churn)
      compare  — already-translated caption ("vs previous 30 days")
      empty    — null | 'not_tracked' | 'no_data'
--}}
@props([
    'label',
    'value' => null,
    'delta' => null,
    'unit' => 'percent',
    'goodUp' => true,
    'compare' => null,
    'empty' => null,
])
@php
    $isEmpty = $empty !== null || $value === null || $value === '';
@endphp
<div {{ $attributes->class(['rc-kpi']) }} wire:key="kpi-{{ md5($label.'|'.$value.'|'.$delta.'|'.$empty) }}">
    <span class="rc-kpi__label">{{ $label }}</span>
    <span @class(['rc-kpi__value', 'rc-iso', 'rc-kpi__value--empty' => $isEmpty])>{{ $isEmpty ? '—' : $value }}</span>
    @if($isEmpty && $empty)
        <span class="rc-kpi__empty">{{ __('analytics.empty.'.$empty.'_title') }}</span>
    @elseif($delta !== null || $compare)
        <span class="rc-kpi__foot">
            <x-rc.chart.delta :delta="$delta" :unit="$unit" :good-up="$goodUp" />
            @if($compare)<span class="rc-kpi__compare">{{ $compare }}</span>@endif
        </span>
    @endif
</div>
