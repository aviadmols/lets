{{--
    Platform → Email delivery. THE OWNER'S screen: the house's sending account,
    the address every un-verified shop sends as, and the domain that address
    must sit on.

    TOKENS: .rc-stack/.rc-row/.rc-muted (published theme). ZERO inline CSS.
    All copy via lang/*/platform_mail.php (EN/HE mirrored).
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="rc-stack">
        <p class="rc-muted">{{ __('platform_mail.intro') }}</p>

        {{ $this->form }}

        <div class="rc-row">
            <x-rc.cta type="submit" variant="primary">{{ __('platform_mail.actions.save') }}</x-rc.cta>
        </div>
    </form>
</x-filament-panels::page>
