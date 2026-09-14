{{--
    Failed charges — what is missing, what is being done, who stopped being asked.
    TOKENS: .rc-stack/.rc-section/.rc-row/.rc-kpi-grid/.rc-banner/.rc-muted
            (published theme). ZERO inline CSS.
    Renders only — every number comes from the page, which reads ONE set of
    queries (PaymentRecovery::scopeFor) shared with the sidebar badge.
--}}
<x-filament-panels::page>
    @php
        $tabs = $this->tabs();
        $stopped = $this->countFor(\App\Filament\Pages\PaymentRecovery::TAB_STOPPED);
    @endphp

    <div class="rc-stack">

        {{-- The three groups. Counts on the tabs, so a merchant knows where the
             work is before clicking into it. --}}
        <x-filament::tabs>
            @foreach($tabs as $key => $tab)
                <x-filament::tabs.item
                    :active="$tab['active']"
                    :badge="$tab['badge']"
                    wire:click="setTab('{{ $key }}')"
                >
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        {{-- What this group IS, and the money in it. The sentence matters most on
             the middle tab: those subscriptions are held, not cancelled. --}}
        <div class="rc-section">
            <p class="rc-muted">{{ $this->intro() }}</p>

            <div class="rc-kpi-grid">
                <x-rc.kpi
                    label="recovery.kpi.in_group"
                    :value="number_format($this->countFor($this->activeTab))"
                />
                <x-rc.kpi
                    label="recovery.kpi.money"
                    :value="$this->moneyAtRisk()"
                />
            </div>
        </div>

        {{-- The standing reminder, wherever the merchant is on this screen: a held
             subscription is recoverable, and there is exactly one thing that
             recovers it. Shown only while there is something held. --}}
        @if($stopped > 0)
            <div class="rc-banner">
                <div class="rc-banner__text">
                    <span class="rc-banner__title">{{ __('recovery.held.title', ['count' => number_format($stopped)]) }}</span>
                    <span class="rc-banner__body">{{ __('recovery.held.body') }}</span>
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
