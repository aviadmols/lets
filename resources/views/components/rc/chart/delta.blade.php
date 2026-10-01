{{--
    rc.chart.delta — the change chip: arrow + amount, coloured by whether the move is GOOD.
    TOKENS: .rc-delta .rc-delta--good/--bad/--flat
    Props:
      delta  — signed number (percent or points); null renders nothing
      unit   — 'percent' (counts, money) | 'points' (rates) — App\Domain\Analytics\Support\Delta::UNIT_*
      goodUp — is up the good direction? false for churn, failures, lost MRR
--}}
@props(['delta' => null, 'unit' => 'percent', 'goodUp' => true])
@php
    $tone = \App\Domain\Analytics\Support\Delta::tone($delta, (bool) $goodUp);
    $arrow = $tone === 'flat' ? '—' : ($delta > 0 ? '▲' : '▼');
    $amount = $delta === null ? null : number_format(abs((float) $delta), 1);
@endphp
@if($delta !== null)
    <span {{ $attributes->class(['rc-delta', 'rc-delta--'.$tone]) }}>
        <span aria-hidden="true">{{ $arrow }}</span>
        <span class="rc-ltr">{{ $unit === 'points' ? __('analytics.delta.points', ['value' => $amount]) : $amount.'%' }}</span>
        <span class="rc-sr-only">{{ __('analytics.delta.sr_'.$tone) }}</span>
    </span>
@endif
