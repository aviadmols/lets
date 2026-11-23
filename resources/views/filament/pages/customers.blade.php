{{--
    Customers list (docs/ux/20-customers.md Part A), in the LETS panel language: a black
    hero with the book at a glance, a pill search + quick filter, then the list — every
    name opens the customer.
    TOKENS: .rc-ins-* (components/insights.css) .rc-cust-* (components/customers-list.css)
            .rc-badge (published theme). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Pages\Customers;
    $s = $this->screen();
@endphp
<x-filament-panels::page>
    <div class="rc-ins">
        <x-rc.insights.hero :eyebrow="__('customers.list.hero.eyebrow')" :big="number_format($s['total'])">
            {!! __('customers.list.hero.say', ['active' => '<b>'.number_format($s['active']).'</b>', 'revenue' => '<b>'.e(\App\Support\Ui\Money::format($s['revenue'])).'</b>']) !!}
            <x-slot:tiles>
                <x-rc.insights.tile icon="heroicon-o-check-circle" :accent="true" :value="number_format($s['active'])" :label="__('customers.list.hero.active')" />
                <x-rc.insights.tile icon="heroicon-o-credit-card" :value="number_format($s['problem'])" :label="__('customers.list.hero.problem')" />
                <x-rc.insights.tile icon="heroicon-o-banknotes" :value="\App\Support\Ui\Money::format($s['revenue'])" :label="__('customers.list.hero.revenue')" />
            </x-slot:tiles>
        </x-rc.insights.hero>

        <div class="rc-ins-controls">
            <label class="rc-cust-search">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="rc-cust-search__icon" />
                <input type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('customers.list.search_placeholder') }}"
                       aria-label="{{ __('customers.list.search_placeholder') }}">
            </label>
            <div class="rc-ins-seg" role="group" aria-label="{{ __('customers.list.show.label') }}">
                @foreach (Customers::SHOW as $option)
                    <button type="button" wire:click="setShow('{{ $option }}')" aria-pressed="{{ $show === $option ? 'true' : 'false' }}">{{ __('customers.list.show.'.$option) }}</button>
                @endforeach
            </div>
        </div>

        <section class="rc-ins-card">
            @if($s['rows']->isEmpty())
                <x-rc.empty title="customers.list.empty.first_run" icon="heroicon-o-users" />
            @else
                <div class="rc-ins-scroll rc-cust-scroll">
                    <table class="rc-ins-table">
                        <thead>
                            <tr>
                                <th>{{ __('customers.list.col.customer') }}</th>
                                <th>{{ __('customers.list.col.phone') }}</th>
                                <th class="rc-ins-num">{{ __('customers.list.col.active_subs') }}</th>
                                <th class="rc-ins-num">{{ __('customers.list.col.spend') }}</th>
                                <th>{{ __('customers.list.col.payment_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($s['rows'] as $row)
                                <tr wire:key="cust-{{ $row['id'] }}">
                                    <td>
                                        <a class="rc-cust-person" href="{{ \App\Filament\Pages\CustomerDetail::getUrl(['customer' => $row['id']]) }}" wire:navigate>
                                            <span class="rc-cust-person__avatar" aria-hidden="true">{{ Customers::initials($row['label']) }}</span>
                                            <span class="rc-cust-person__text">
                                                <span class="rc-cust-person__name">{{ $row['label'] }}</span>
                                                {{-- The email under the name, when the name is not itself the email. --}}
                                                @if($row['email'] && $row['email'] !== $row['label'])
                                                    <span class="rc-cust-person__meta">{{ $row['email'] }}</span>
                                                @endif
                                            </span>
                                        </a>
                                    </td>
                                    {{-- From the plan captured at checkout, not a per-row store read. --}}
                                    <td class="rc-iso">{{ $row['phone'] ?? '—' }}</td>
                                    <td class="rc-ins-num">{{ $row['active_subs'] }}</td>
                                    {{-- SUCCEEDED charges only — money actually received. --}}
                                    <td class="rc-ins-num rc-ins-strong">{{ $row['spend'] }}</td>
                                    {{-- Every status the customer's subscriptions are in, in words. --}}
                                    <td>
                                        <div class="rc-row">
                                            @foreach($row['statuses'] as $status)
                                                <x-rc.badge :tone="$status['tone']" :label="'customers.list.status.' . $status['key']" />
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
