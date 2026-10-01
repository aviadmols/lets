{{--
    Analytics › Reports › Exports (sketch Reports.dc.html "Recent exports", spec §8).
    LETS keeps no export history (every export streams straight to the browser) — an honest not-tracked state.
    Draws only: App\Filament\Pages\Analytics\Screens\ReportsExports::data().
    TOKENS: rc.chart.card / rc.chart.empty
    Vars: library_url, plus $context.
--}}
@php $lang = 'analytics/reports_exports.'; @endphp

<x-rc.chart.card :title="__($lang.'title')" :subtitle="__($lang.'subtitle')" :link="$library_url" :link-label="__($lang.'link')">
    <x-rc.chart.empty variant="not_tracked" :title="__($lang.'empty.title')" :body="__($lang.'empty.body')" />
</x-rc.chart.card>
