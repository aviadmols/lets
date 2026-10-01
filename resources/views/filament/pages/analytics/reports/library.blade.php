{{--
    Analytics › Reports — the report library (App\Livewire\Analytics\ReportLibrary).
    Search + category filter; "Run" downloads the report as CSV; untracked reports are listed disabled.
    TOKENS: .rc-an-toolbar .rc-an-search(__icon|__input) .rc-an-select .rc-an-summary
            .rc-report-grid .rc-report-cat(__head|__title|__count|__list) .rc-report(--off|__text|__title|__body|__run|__icon|__off)
            (analytics-retention.css) + .rc-an-btn (analytics.css) + rc.chart.empty
    Vars: groups, categories, range — ReportLibrary::render().
--}}
@php $lang = 'analytics/reports_reports.'; @endphp
<div class="rc-an-live" wire:loading.class="rc-an-live__busy">
    <div class="rc-an-toolbar rc-an-toolbar--bare">
        <label class="rc-an-search">
            <x-heroicon-o-magnifying-glass class="rc-an-search__icon" />
            <span class="rc-sr-only">{{ __($lang.'filter.search') }}</span>
            <input type="search" class="rc-an-search__input" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __($lang.'filter.search') }}" maxlength="60" />
        </label>
        <label class="rc-sr-only" for="rc-report-category">{{ __($lang.'filter.category') }}</label>
        <select id="rc-report-category" class="rc-an-select" wire:model.live="category">
            @foreach($categories as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
        </select>
        <span class="rc-an-toolbar__grow"></span>
        <span class="rc-an-summary rc-an-summary--inline">{{ __($lang.'range', ['range' => $range]) }}</span>
    </div>

    @if($groups === [])
        <x-rc.chart.empty variant="no_data" compact :title="__($lang.'empty.title')" :body="__($lang.'empty.body')" />
    @else
        <div class="rc-report-grid" wire:key="reports-{{ md5(json_encode([$search, $category])) }}">
            @foreach($groups as $group)
                <section class="rc-report-cat">
                    <header class="rc-report-cat__head">
                        <h3 class="rc-report-cat__title">{{ $group['title'] }}</h3>
                        <span class="rc-report-cat__count">{{ $group['count'] }}</span>
                    </header>
                    <ul class="rc-report-cat__list">
                        @foreach($group['items'] as $item)
                            <li @class(['rc-report', 'rc-report--off' => ! $item['available']])>
                                <span class="rc-report__text">
                                    <span class="rc-report__title">{{ $item['title'] }}</span>
                                    <span class="rc-report__body">{{ $item['body'] }}@if($item['available'] && $item['ranged']) · {{ __($lang.'uses_range') }}@endif</span>
                                </span>
                                @if($item['available'])
                                    <button type="button" class="rc-an-btn rc-report__run" wire:click="run('{{ $item['key'] }}')"
                                            wire:loading.attr="disabled" wire:target="run('{{ $item['key'] }}')">
                                        <x-heroicon-o-arrow-down-tray class="rc-report__icon" />
                                        {{ __($lang.'run') }}
                                    </button>
                                @else
                                    <span class="rc-report__off">{{ __('analytics.empty.not_tracked_title') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
