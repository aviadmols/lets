{{--
    Settings → PayPlus Connection (docs/ux/50-settings.md §1).
    REDESIGN: merchant enters only api_key + secret_key + environment, then
    "Connect" auto-discovers the terminal / payment page / cashier from PayPlus
    (a picker appears only when the account has multiple terminals/pages).
    TOKENS: .rc-section/.rc-conn/.rc-badge/.rc-row (published theme). ZERO inline CSS.
    Secrets masked; credential values stay LTR even in HE.
--}}
<x-filament-panels::page>
    <div class="rc-stack">
        {{-- connection status badge --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <span class="rc-conn">
                    <x-rc.badge
                        :status="$connectionStatus"
                        :label="'settings.payplus.status.' . $connectionStatus"
                        dot
                    />
                </span>
                @if($connectionStatus === 'not_connected')
                    <span class="rc-muted">{{ __('settings.payplus.empty') }}</span>
                @endif
            </div>
        </div>

        {{-- credentials → Connect (discovery) → terminal/page pickers → Save --}}
        <form wire:submit="save" class="rc-stack">
            {{ $this->form }}

            <div class="rc-row">
                <x-rc.cta type="submit" variant="primary">{{ __('settings.payplus.save') }}</x-rc.cta>
                <x-rc.cta type="button" variant="ghost" wire:click="testConnection" wire:loading.attr="disabled">
                    {{ __('settings.payplus.test') }}
                </x-rc.cta>
            </div>
        </form>
    </div>
</x-filament-panels::page>
