{{--
    The bell (App\Livewire\TopbarAlerts): failed charges + documents needing
    attention — the same counts the sidebar badges show. A dot when anything
    waits; the dropdown lists each with a link to its screen. Re-reads on every
    SPA navigation because the top bar's end is persisted between pages.
    TOKENS: .rc-topbar-btn* .rc-topbar-alerts* (components/shell.css). ZERO inline CSS.
--}}
<div x-on:livewire:navigated.window="$wire.$refresh()">
    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <button
                type="button"
                class="rc-topbar-btn"
                aria-label="{{ $alerts === [] ? __('nav.alerts.label') : __('nav.alerts.label_pending') }}"
            >
                <x-filament::icon icon="heroicon-o-bell" class="rc-topbar-btn__icon" />
                @if ($alerts !== [])
                    <span class="rc-topbar-btn__dot" aria-hidden="true"></span>
                @endif
            </button>
        </x-slot>

        <div class="rc-topbar-alerts">
            <div class="rc-topbar-alerts__heading">{{ __('nav.alerts.heading') }}</div>
            @forelse ($alerts as $alert)
                <a href="{{ $alert['url'] }}" wire:navigate class="rc-topbar-alerts__item">
                    <span class="rc-topbar-alerts__label">{{ $alert['label'] }}</span>
                    <span class="rc-topbar-alerts__count rc-topbar-alerts__count--{{ $alert['tone'] }}">{{ $alert['count'] }}</span>
                </a>
            @empty
                <div class="rc-topbar-alerts__empty">{{ __('nav.alerts.empty') }}</div>
            @endforelse
        </div>
    </x-filament::dropdown>
</div>
