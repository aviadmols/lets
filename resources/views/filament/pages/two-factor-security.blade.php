{{--
    Account → Security: sign-in with an authenticator app.
    Three states: OFF (a button), SETTING UP (QR + secret + confirm code),
    ON (status, recovery codes, turn off). Fresh recovery codes show once.

    TOKENS: .rc-two-factor* (components/two-factor.css), .rc-stack/.rc-row/.rc-muted,
    .rc-banner, .rc-badge. ZERO inline CSS. All copy via lang/*/two_factor.php.
    The QR SVG is generated server-side from the TOTP URI (never user input).
--}}
<x-filament-panels::page>
    <div class="rc-two-factor rc-stack">
        @if ($this->isMandatory() && ! $this->isEnabled())
            <div class="rc-banner rc-banner--danger" role="alert">
                <div class="rc-banner__text">
                    <span class="rc-banner__title">{{ __('two_factor.page.mandatory_title') }}</span>
                    <span class="rc-banner__body">{{ __('two_factor.page.mandatory_text') }}</span>
                </div>
            </div>
        @endif

        <section class="rc-section rc-stack">
            <div class="rc-two-factor__head">
                <div class="rc-stack">
                    <h2 class="rc-section__title">{{ __('two_factor.page.app_title') }}</h2>
                    <p class="rc-muted">{{ __('two_factor.page.app_text') }}</p>
                </div>
                @if ($this->isEnabled())
                    <span class="rc-badge rc-badge--success"><span class="rc-badge__dot"></span>{{ __('two_factor.page.status_on') }}</span>
                @else
                    <span class="rc-badge rc-badge--gray"><span class="rc-badge__dot"></span>{{ __('two_factor.page.status_off') }}</span>
                @endif
            </div>

            @if (! $this->isEnabled() && ! $settingUp)
                <div class="rc-row">
                    <x-rc.cta type="button" variant="primary" wire:click="startSetup">{{ __('two_factor.page.start') }}</x-rc.cta>
                </div>
            @endif

            @if ($settingUp && $this->setupSecret())
                <ol class="rc-two-factor__steps">
                    <li>{{ __('two_factor.page.step_install') }}</li>
                    <li>{{ __('two_factor.page.step_scan') }}</li>
                </ol>

                <div class="rc-two-factor__enrol">
                    <div class="rc-two-factor__qr" role="img" aria-label="{{ __('two_factor.page.qr_label') }}">{!! $this->qrCodeSvg() !!}</div>
                    <div class="rc-stack">
                        <p class="rc-muted">{{ __('two_factor.page.manual_key') }}</p>
                        <code class="rc-two-factor__secret">{{ trim(chunk_split($this->setupSecret(), 4, ' ')) }}</code>
                    </div>
                </div>

                <form wire:submit="confirmSetup" class="rc-stack rc-two-factor__form">
                    <p class="rc-muted">{{ __('two_factor.page.step_confirm') }}</p>
                    {{ $this->form }}
                    <div class="rc-row">
                        <x-rc.cta type="submit" variant="primary">{{ __('two_factor.page.confirm') }}</x-rc.cta>
                        <x-rc.cta type="button" variant="ghost" wire:click="cancelSetup">{{ __('two_factor.page.cancel') }}</x-rc.cta>
                    </div>
                </form>
            @endif

            @if ($this->isEnabled())
                <p class="rc-muted">{{ trans_choice('two_factor.page.recovery_left', $this->remainingRecoveryCodes(), ['count' => $this->remainingRecoveryCodes()]) }}</p>

                <form class="rc-stack rc-two-factor__form" wire:submit="regenerateRecoveryCodes">
                    <p class="rc-muted">{{ __('two_factor.page.change_help') }}</p>
                    {{ $this->form }}
                    <div class="rc-row">
                        <x-rc.cta type="submit" variant="ghost">{{ __('two_factor.page.regenerate') }}</x-rc.cta>
                        @unless ($this->isMandatory())
                            <x-rc.cta type="button" variant="danger" wire:click="disable">{{ __('two_factor.page.disable') }}</x-rc.cta>
                        @endunless
                    </div>
                </form>
            @endif
        </section>

        @if ($freshRecoveryCodes !== [])
            <section class="rc-section rc-stack">
                <h2 class="rc-section__title">{{ __('two_factor.page.codes_title') }}</h2>
                <p class="rc-muted">{{ __('two_factor.page.codes_text') }}</p>
                <ul class="rc-two-factor__codes">
                    @foreach ($freshRecoveryCodes as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
