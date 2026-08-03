{{--
    Customers → Club members. Who is in the club and what they hold.
    TOKENS: .rc-stack/.rc-section/.rc-table/.rc-input (published theme). ZERO inline CSS.
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

        @php($members = $this->members())
        <div class="rc-section">
            @if($members->isEmpty())
                <x-rc.empty title="loyalty.admin.members.empty" icon="heroicon-o-user-group" />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('loyalty.admin.members.col.member') }}</th>
                            <th>{{ __('loyalty.admin.members.col.tier') }}</th>
                            <th>{{ __('loyalty.admin.members.col.balance') }}</th>
                            <th>{{ __('loyalty.admin.members.col.lifetime_spend') }}</th>
                            <th>{{ __('loyalty.admin.members.col.joined') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($members as $member)
                            <tr wire:key="member-{{ $member->getKey() }}">
                                <td>
                                    <span class="rc-strong">{{ $member->label() }}</span>
                                    @if($member->customer_email && $member->customer_email !== $member->label())
                                        <div class="rc-muted rc-ltr">{{ $member->customer_email }}</div>
                                    @endif
                                </td>
                                <td>{{ $member->tier?->name ?? '—' }}</td>
                                <td class="rc-ltr rc-strong">{{ \App\Support\Ui\Money::number($member->points_balance) }}</td>
                                <td class="rc-ltr">{{ \App\Support\Ui\Money::format((float) $member->lifetime_spend) }}</td>
                                <td class="rc-ltr">{{ optional($member->joined_at)->format('d M Y') ?? '—' }}</td>
                                <td>
                                    <button type="button" class="rc-ghost-btn"
                                            wire:click="mountAction('adjustPoints', { account: {{ $member->getKey() }} })">
                                        {{ __('loyalty.admin.members.adjust') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
