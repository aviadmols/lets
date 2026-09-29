{{--
    The top bar's icon buttons, before the language pill and the user menu (the
    approved sketch): the bell (only with a bound shop — its counts are the
    shop's) and help (mail to LETS support; there is no chat yet).
    TOKENS: .rc-topbar-actions .rc-topbar-btn* (components/shell.css). ZERO inline CSS.
--}}
<div class="rc-topbar-actions">
    @if (\App\Support\Tenant::check())
        @livewire(\App\Livewire\TopbarAlerts::class)
    @endif

    <a
        href="{{ \App\Support\Ui\SupportContact::mailto() }}"
        class="rc-topbar-btn"
        aria-label="{{ __('nav.help') }}"
        x-data="{}"
        x-tooltip="{ content: @js(__('nav.help')), theme: $store.theme }"
    >
        <x-filament::icon icon="heroicon-o-question-mark-circle" class="rc-topbar-btn__icon" />
    </a>
</div>
