<?php

namespace App\Livewire;

use Filament\Livewire\GlobalSearch;
use Illuminate\Contracts\View\View;

/**
 * The top bar's search box, at the START of the bar where the approved sketch
 * draws it (Filament's own box sits at the end, beside the user menu).
 *
 * All behaviour is Filament's GlobalSearch — the debounced field, the results
 * dropdown, keyboard focus — and the answers come from the panel's provider
 * (App\Filament\Search\TenantGlobalSearchProvider). Only the markup is ours, so
 * the field carries the sketch's placeholder and rc-* classes.
 *
 * Filament renders its own box only when a Resource is globally searchable; none
 * is (the provider does the searching), so this is the one box on the bar.
 */
class TopbarSearch extends GlobalSearch
{
    // === CONSTANTS ===
    public const VIEW = 'filament.partials.topbar-search';

    public function render(): View
    {
        return view(self::VIEW, [
            'results' => $this->getResults(),
        ]);
    }
}
