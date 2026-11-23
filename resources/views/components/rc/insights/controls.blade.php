{{--
    rc.insights.controls — the window picker every analytics screen shares: fixed ranges,
    "dates…" (from/to), and optionally the day/month switch. Drives the page's
    InsightsScreen state (setRange / setGrain / from / to), which lives in the URL.
    TOKENS: .rc-ins-controls .rc-ins-seg .rc-ins-dates (components/insights.css). ZERO inline CSS.

    Props: range, grain, grainSwitch (bool), ranges (value => label key)
--}}
@props([
    'range' => '30',
    'grain' => 'day',
    'grainSwitch' => true,
    'ranges' => ['7' => 'insights.range.7', '30' => 'insights.range.30', '90' => 'insights.range.90', '365' => 'insights.range.365', 'custom' => 'insights.range.custom'],
])
<div {{ $attributes->merge(['class' => 'rc-ins-controls']) }}>
    <div class="rc-ins-seg" role="group" aria-label="{{ __('insights.range.label') }}">
        @foreach ($ranges as $value => $label)
            <button type="button" wire:click="setRange('{{ $value }}')" aria-pressed="{{ $range === (string) $value ? 'true' : 'false' }}">{{ __($label) }}</button>
        @endforeach
    </div>

    @if ($range === 'custom')
        <span class="rc-ins-dates">
            <label for="rc-ins-from">{{ __('insights.range.from') }}</label>
            <input id="rc-ins-from" type="date" wire:model.live="from" max="{{ \App\Domain\Insights\InsightsClock::today()->format('Y-m-d') }}">
            <label for="rc-ins-to">{{ __('insights.range.to') }}</label>
            <input id="rc-ins-to" type="date" wire:model.live="to" max="{{ \App\Domain\Insights\InsightsClock::today()->format('Y-m-d') }}">
        </span>
    @endif

    @if ($grainSwitch)
        <div class="rc-ins-seg" role="group" aria-label="{{ __('insights.grain.label') }}">
            <button type="button" wire:click="setGrain('day')" aria-pressed="{{ $grain === 'day' ? 'true' : 'false' }}">{{ __('insights.grain.day') }}</button>
            <button type="button" wire:click="setGrain('month')" aria-pressed="{{ $grain === 'month' ? 'true' : 'false' }}">{{ __('insights.grain.month') }}</button>
        </div>
    @endif

    {{ $slot }}
</div>
