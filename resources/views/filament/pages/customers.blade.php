{{--
    Customers list (docs/ux/20-customers.md Part A). v1 derived-from-plans.
    TOKENS: via .rc-section/.rc-table/.rc-dot/.rc-input (published theme). ZERO inline CSS.
--}}
<x-filament-panels::page>
    <div class="rc-stack">
        <div class="rc-section">
            <div class="rc-row">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('customers.list.search_placeholder') }}"
                    class="fi-input rc-grow"
                    aria-label="{{ __('customers.list.search_placeholder') }}"
                />
            </div>
        </div>

        @php $rows = $this->customers(); @endphp
        <div class="rc-section">
            @if($rows->isEmpty())
                <x-rc.empty
                    title="customers.list.empty.first_run"
                    icon="heroicon-o-users"
                />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('customers.list.col.customer') }}</th>
                            <th>{{ __('customers.list.col.active_subs') }}</th>
                            <th>{{ __('customers.list.col.payment_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr wire:key="cust-{{ $row['id'] }}">
                                <td>
                                    <a class="rc-strong" href="{{ \App\Filament\Pages\CustomerDetail::getUrl(['customer' => $row['id']]) }}">
                                        {{ $row['id'] }}
                                    </a>
                                </td>
                                <td class="rc-ltr">{{ $row['active_subs'] }}</td>
                                <td><span class="rc-dot rc-dot--{{ $row['dot'] }}" aria-hidden="true"></span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
