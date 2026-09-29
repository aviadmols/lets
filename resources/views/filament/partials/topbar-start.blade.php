{{--
    The START of the top bar: the search box (App\Livewire\TopbarSearch) for a
    signed-in user. Rendered through PanelsRenderHook::TOPBAR_START.
    ZERO inline CSS.
--}}
@if (filament()->auth()->check())
    @livewire(\App\Livewire\TopbarSearch::class)
@endif
