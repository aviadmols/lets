{{--
    Analytics › Reports › Reports (sketch Reports.dc.html, spec §8) — mounts the live report library.
    Draws only: App\Filament\Pages\Analytics\Screens\ReportsLibrary::data(); the cards are App\Livewire\Analytics\ReportLibrary,
    re-keyed when the date range or the filter chips change.
    TOKENS: see reports/library.blade.php
    Vars: library, plus $context.
--}}
@livewire($library['component'], $library['props'], key($library['key']))
