{{--
    Platform → AI. The owner's screen: the model account, the kill switch, the
    budget, the bill. TOKENS: .rc-stack/.rc-row/.rc-muted. ZERO inline CSS.
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="rc-stack">
        <p class="rc-muted">{{ __('platform_ai.intro') }}</p>

        {{ $this->form }}

        <div class="rc-row">
            <x-rc.cta type="submit" variant="primary">{{ __('platform_ai.actions.save') }}</x-rc.cta>
        </div>
    </form>
</x-filament-panels::page>
