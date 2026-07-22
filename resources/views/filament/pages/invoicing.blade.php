{{--
    Settings → Invoicing (Green Invoice / Morning).
    Connection status badge, then the four form sections (connection, scope,
    document types, options), then Save + a NON-ISSUING "Test connection".
    TOKENS: .rc-stack/.rc-section/.rc-conn/.rc-badge/.rc-row (published theme).
    ZERO inline CSS. Secrets are masked by the page; credential values stay LTR in HE.
--}}
<x-filament-panels::page>
    <div class="rc-stack">
        {{-- connection status badge --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <span class="rc-conn">
                    <x-rc.badge
                        :status="$connectionStatus"
                        :label="'settings.invoicing.status.' . $connectionStatus"
                        dot
                    />
                </span>
                @if($connectionStatus === 'not_connected')
                    <span class="rc-muted">{{ __('settings.invoicing.empty') }}</span>
                @endif
            </div>
        </div>

        {{-- credentials → scope → document types → options → Save --}}
        <form wire:submit="save" class="rc-stack">
            {{ $this->form }}

            <div class="rc-row">
                <x-rc.cta type="submit" variant="primary">{{ __('settings.invoicing.save') }}</x-rc.cta>
                <x-rc.cta type="button" variant="ghost" wire:click="testConnection" wire:loading.attr="disabled">
                    {{ __('settings.invoicing.test') }}
                </x-rc.cta>
            </div>
        </form>
    </div>
</x-filament-panels::page>
