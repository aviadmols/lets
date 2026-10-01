{{--
    rc.chart.empty — the honest empty state of a card.
      not_tracked — LETS records nothing this card could be computed from (dashed outline)
      no_data     — the source exists, the period simply has nothing in it
    TOKENS: .rc-an-empty(--not-tracked|--compact) .rc-an-empty__icon/__title/__body
    Props: variant ('no_data'|'not_tracked'), title/body (already-translated overrides), compact (bool)
--}}
@props(['variant' => 'no_data', 'title' => null, 'body' => null, 'compact' => false])
<div {{ $attributes->class(['rc-an-empty', 'rc-an-empty--not-tracked' => $variant === 'not_tracked', 'rc-an-empty--compact' => $compact]) }}>
    <x-dynamic-component :component="$variant === 'not_tracked' ? 'heroicon-o-eye-slash' : 'heroicon-o-chart-bar'" class="rc-an-empty__icon" />
    <span class="rc-an-empty__title">{{ $title ?? __('analytics.empty.'.$variant.'_title') }}</span>
    <span class="rc-an-empty__body">{{ $body ?? __('analytics.empty.'.$variant.'_body') }}</span>
</div>
