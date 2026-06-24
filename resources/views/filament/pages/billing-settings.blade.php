{{--
    Settings → Billing (plan §4.7). The merchant sets retry policy, installment
    bounds (the server-side money wall the storefront quote is clamped to), portal
    self-service gates, and the policy/terms snapshotted into every consent.
    TOKENS: .rc-stack/.rc-row/.rc-muted (published theme). ZERO inline CSS.
    All copy via lang/*/billing.php (EN/HE mirrored).
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="rc-stack">
        <p class="rc-muted">{{ __('billing.settings.intro') }}</p>

        {{ $this->form }}

        <div class="rc-row">
            <x-rc.cta type="submit" variant="primary">{{ __('billing.settings.save_cta') }}</x-rc.cta>
        </div>
    </form>
</x-filament-panels::page>
