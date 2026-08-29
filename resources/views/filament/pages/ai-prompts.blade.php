{{--
    Platform → AI prompts. One fold per stage; a blank prompt means the shipped
    default (shown as the placeholder). TOKENS: .rc-stack/.rc-row/.rc-muted.
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="rc-stack">
        <p class="rc-muted">{{ __('platform_ai.prompts.intro') }}</p>

        {{ $this->form }}

        <div class="rc-row">
            <x-rc.cta type="submit" variant="primary">{{ __('platform_ai.actions.save') }}</x-rc.cta>
        </div>
    </form>
</x-filament-panels::page>
