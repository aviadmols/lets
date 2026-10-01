{{--
    Analytics › Payments › Upcoming payments (sketch UpcomingPayments.dc.html, spec §3.4).
    Draws only: App\Filament\Pages\Analytics\Screens\PaymentsUpcoming::data() shapes every prop; the
    table is the nested App\Livewire\Analytics\UpcomingPaymentsTable (search · sort · pages).
    TOKENS: .rc-an-grid .rc-kpi* + rc.chart.card/toggle; .rc-an-pay-toolbar .rc-an-pay-days* (components/analytics-payments.css)
    Vars: everything PaymentsUpcoming::data() returns, plus $context.
--}}
@php $l = 'analytics/payments_upcoming.'; @endphp

<div class="rc-an-pay-toolbar">
    <x-rc.chart.toggle :options="$day_options" :active="(string) $days" action="setOption" target="days" :label="__($l.'window.label')" />
</div>

<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <div class="rc-kpi" wire:key="upcoming-kpi-{{ md5($kpi['label'].$kpi['value'].$kpi['caption']) }}">
            <span class="rc-kpi__label">{{ $kpi['label'] }}</span>
            <span class="rc-kpi__value rc-ltr">{{ $kpi['value'] }}</span>
            @if($kpi['caption'])<span class="rc-kpi__foot"><span class="rc-kpi__compare">{{ $kpi['caption'] }}</span></span>@endif
        </div>
    @endforeach
</div>

<x-rc.chart.card flush :title="__($l.'table.title')" :subtitle="__($l.'table.subtitle')">
    <livewire:analytics.upcoming-payments-table :days="$days" :filters="$filters"
                                                :key="'upcoming-table-'.$days.'-'.$filters_key" />
    <x-slot name="note">{{ __($l.'table.note') }}</x-slot>
</x-rc.chart.card>

<x-rc.chart.card :title="__($l.'by_day.title')" :subtitle="__($l.'by_day.subtitle')">
    @if($by_day === [])
        <x-rc.chart.empty variant="no_data" compact :title="__($l.'empty.title')" :body="__($l.'empty.body')" />
    @else
        <ul class="rc-an-pay-days">
            @foreach($by_day as $day)
                <li>
                    <a class="rc-an-pay-days__item" href="{{ $day['url'] }}">
                        <span class="rc-an-pay-days__date">{{ $day['label'] }}</span>
                        <span class="rc-an-pay-days__count rc-ltr">{{ trans_choice($l.'by_day.count', $day['n'], ['count' => $day['count']]) }}</span>
                        <span class="rc-an-pay-days__amount rc-ltr">{{ $day['amount'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-rc.chart.card>
