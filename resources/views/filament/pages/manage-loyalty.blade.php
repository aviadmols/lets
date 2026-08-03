{{--
    Customers → Loyalty. The club's rates and bonuses, the tier ladder, and how
    the members page looks. All copy via lang/*/loyalty.php (EN/HE mirrored).
    TOKENS: .rc-stack/.rc-row/.rc-muted/.rc-section (published theme). ZERO inline CSS.
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="rc-stack">
        <p class="rc-muted">{{ $this->redeemExample() }}</p>

        {{ $this->form }}

        <div class="rc-row">
            <x-rc.cta type="submit" variant="primary">{{ __('loyalty.admin.save_cta') }}</x-rc.cta>
            <a class="rc-cta rc-cta--ghost" href="{{ \App\Filament\Pages\StorefrontElements::getUrl() }}">
                {{ __('loyalty.admin.embed_cta') }}
            </a>
        </div>
    </form>
</x-filament-panels::page>
