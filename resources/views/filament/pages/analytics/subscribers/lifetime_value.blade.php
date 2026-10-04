{{--
    Analytics › Subscribers › Lifetime value (sketch LifetimeValue.dc.html, spec §1.5).
    Draws only: App\Filament\Pages\Analytics\Screens\SubscribersLifetimeValue::data() shapes every prop.
    TOKENS: .rc-an-grid--main-side + rc.chart.* components
            .rc-ans-summary(__lead|__note) .rc-ans-flush-note (analytics-subscribers.css)
    Vars: everything SubscribersLifetimeValue::data() returns, plus $context.
--}}
@php $t = 'analytics/subscribers_lifetime_value.'; @endphp

{{-- Summary sentence: subscriber LTV is real; the comparison with non-subscribers is not tracked. --}}
<div class="rc-ans-summary" wire:key="ltv-summary-{{ md5(($ltv ?? '').'|'.$customers) }}">
    @if($ltv !== null)
        <p class="rc-ans-summary__lead">
            {{ __($t.'summary.lead_before') }} <strong class="rc-iso">{{ $ltv }}</strong>
            {{ trans_choice($t.'summary.lead_after', $customer_count, ['n' => $customers, 'date' => $as_of]) }}
            <x-rc.chart.delta :delta="$ltv_delta" />
        </p>
    @else
        <p class="rc-ans-summary__lead">{{ __($t.'summary.empty') }}</p>
    @endif
    <p class="rc-ans-summary__note">{{ __($t.'summary.note') }}</p>
</div>

<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card :title="__($t.'segments.title')" :subtitle="__($t.'segments.subtitle')">
        <x-rc.chart.empty variant="not_tracked" compact :body="__($t.'segments.not_tracked')" />
    </x-rc.chart.card>
    <x-rc.chart.card :title="__($t.'distribution.title')">
        <x-rc.chart.empty variant="not_tracked" compact :body="__($t.'distribution.not_tracked')" />
    </x-rc.chart.card>
</div>

<x-rc.chart.card flush :title="__($t.'table.title')" :subtitle="__($t.'table.subtitle')">
    <x-rc.chart.table :columns="$table['columns']" :rows="$table['rows']" :caption="__($t.'table.title')" />
    <p class="rc-ans-flush-note">{{ __($t.'table.note') }}</p>
</x-rc.chart.card>

<x-rc.chart.card :title="__($t.'trend.title')" :subtitle="__($t.'trend.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                           :target="$trend['id']" :label="__('analytics.grain.label')" />
    </x-slot>
    <x-rc.chart.line :id="$trend['id']" :title="__($t.'trend.title')" :labels="$trend['labels']"
                     :series="$trend['series']" format="money" :from-zero="false" />
    <x-slot name="note">{{ __($t.'trend.note') }}</x-slot>
</x-rc.chart.card>
