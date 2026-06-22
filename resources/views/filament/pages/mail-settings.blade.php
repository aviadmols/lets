{{--
    Settings → Email notifications (W9 Part A). The merchant customizes the six
    customer-facing emails; a blank field uses the platform default.
    TOKENS: .rc-stack/.rc-row/.rc-section/.rc-muted (published theme). ZERO inline
    CSS (the email PREVIEW markup is the allowed exception — and it lives sandboxed
    inside an iframe srcdoc, htmlspecialchars-escaped, in the preview partial).
    All copy via lang/*/mail.php (EN/HE mirrored).
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="rc-stack">
        <p class="rc-muted">{{ __('mail.intro') }}</p>

        {{ $this->form }}

        <div class="rc-row">
            <x-rc.cta type="submit" variant="primary">{{ __('mail.actions.save') }}</x-rc.cta>
        </div>
    </form>
</x-filament-panels::page>
