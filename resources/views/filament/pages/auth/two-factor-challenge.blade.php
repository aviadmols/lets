{{--
    Step two of the admin login — the authenticator-app code (or a recovery code).
    TOKENS: .rc-two-factor* (components/two-factor.css). ZERO inline CSS. EN/HE via __().
--}}
<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="verify">
        {{ $this->form }}

        <x-filament::button type="submit" class="rc-two-factor__submit">
            {{ __('two_factor.challenge.submit') }}
        </x-filament::button>
    </x-filament-panels::form>

    <div class="rc-two-factor__switch">
        <x-filament::link tag="button" wire:click="toggleRecovery">
            {{ $this->useRecoveryCode ? __('two_factor.challenge.use_app') : __('two_factor.challenge.use_recovery') }}
        </x-filament::link>
        <x-filament::link :href="filament()->getLoginUrl()">
            {{ __('two_factor.challenge.back') }}
        </x-filament::link>
    </div>
</x-filament-panels::page.simple>
