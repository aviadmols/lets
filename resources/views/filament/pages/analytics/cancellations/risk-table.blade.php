{{--
    Analytics › Cancellations › Risk analysis — the live table (App\Livewire\Analytics\RiskTable).
    Search · level + card filters · sortable headers · pager · CSV of what is filtered.
    TOKENS: .rc-an-toolbar(__grow) .rc-an-search(__icon|__input) .rc-an-select .rc-an-sort(--active|__icon)
            .rc-an-pager(__nav|__btn|__icon) .rc-an-cell-sub .rc-an-cell-link .rc-an-live(__busy)  (analytics-retention.css)
            .rc-an-table .rc-num .rc-strong-cell .rc-an-btn  (analytics.css) + rc.chart.pill / rc.chart.empty
    Vars: rows, total, pages, from, to, levels, cards, sortable — RiskTable::render().
--}}
@php
    $lang = 'analytics/cancellations_risk.';
    $sortHead = function (string $key, string $label, bool $numeric = false) use ($sort, $dir) {
        return ['key' => $key, 'label' => $label, 'numeric' => $numeric, 'active' => $sort === $key, 'dir' => $dir];
    };
    $columns = [
        ['key' => 'subscription', 'label' => __($lang.'col.subscription')],
        $sortHead('risk', __($lang.'col.risk')),
        ['key' => 'status', 'label' => __($lang.'col.status')],
        $sortHead('created', __($lang.'col.created')),
        ['key' => 'customer', 'label' => __($lang.'col.customer')],
        ['key' => 'price', 'label' => __($lang.'col.price'), 'numeric' => true],
        $sortHead('orders', __($lang.'col.orders'), true),
        $sortHead('success', __($lang.'col.success'), true),
        ['key' => 'streak', 'label' => __($lang.'col.streak'), 'numeric' => true],
        ['key' => 'card', 'label' => __($lang.'col.card')],
        ['key' => 'expiry', 'label' => __($lang.'col.expiry')],
        ['key' => 'interval', 'label' => __($lang.'col.interval')],
    ];
@endphp
<div class="rc-an-live" wire:loading.class="rc-an-live__busy">
    <div class="rc-an-toolbar">
        <label class="rc-an-search">
            <x-heroicon-o-magnifying-glass class="rc-an-search__icon" />
            <span class="rc-sr-only">{{ __($lang.'filter.search') }}</span>
            <input type="search" class="rc-an-search__input" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __($lang.'filter.search') }}" maxlength="80" />
        </label>
        <label class="rc-sr-only" for="rc-risk-level">{{ __($lang.'filter.level') }}</label>
        <select id="rc-risk-level" class="rc-an-select" wire:model.live="level">
            @foreach($levels as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
        </select>
        <label class="rc-sr-only" for="rc-risk-card">{{ __($lang.'filter.card') }}</label>
        <select id="rc-risk-card" class="rc-an-select" wire:model.live="card">
            @foreach($cards as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
        </select>
        <span class="rc-an-toolbar__grow"></span>
        <button type="button" class="rc-an-btn" wire:click="export" @disabled($total === 0)>
            <x-heroicon-o-arrow-down-tray class="rc-an-btn__icon" />
            {{ __($lang.'export') }}
        </button>
    </div>

    @if($rows === [])
        <x-rc.chart.empty variant="no_data" compact :title="__($lang.'empty.title')" :body="__($lang.'empty.body')" />
    @else
        <div class="rc-an-table__wrap" wire:key="risk-rows-{{ md5(json_encode([$search, $level, $card, $sort, $dir, $page, $total])) }}">
            <table class="rc-an-table">
                <caption class="rc-sr-only">{{ __($lang.'table.title') }}</caption>
                <thead>
                    <tr>
                        @foreach($columns as $col)
                            <th scope="col" @class(['rc-num' => ! empty($col['numeric'])])
                                @if(isset($col['active'])) aria-sort="{{ $col['active'] ? ($col['dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
                                @if(isset($col['active']))
                                    <button type="button" wire:click="sortBy('{{ $col['key'] }}')"
                                            @class(['rc-an-sort', 'rc-an-sort--active' => $col['active']])>
                                        {{ $col['label'] }}
                                        @if($col['active'])
                                            <x-dynamic-component :component="$col['dir'] === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'" class="rc-an-sort__icon" />
                                        @endif
                                    </button>
                                @else
                                    {{ $col['label'] }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="risk-{{ $row['ref'] }}">
                            <td class="rc-strong-cell"><a class="rc-an-cell-link rc-iso" href="{{ $row['url'] }}">{{ $row['ref'] }}</a></td>
                            <td><x-rc.chart.pill :tone="$row['risk']['pill']" :label="$row['risk']['text']" /></td>
                            <td><x-rc.chart.pill :tone="$row['status_tone']" :label="$row['status']" /></td>
                            <td class="rc-an-nowrap">{{ $row['created'] }}</td>
                            <td>{{ $row['name'] }}@if($row['email'] !== '')<span class="rc-an-cell-sub">{{ $row['email'] }}</span>@endif</td>
                            <td class="rc-num"><span class="rc-iso">{{ $row['price'] }}</span></td>
                            <td class="rc-num"><span class="rc-iso">{{ $row['orders'] }}</span></td>
                            <td class="rc-num">
                                @if($row['success_tone'])
                                    <x-rc.chart.pill :tone="$row['success_tone']" :label="$row['success']" />
                                @else
                                    <span class="rc-iso">{{ $row['success'] }}</span>
                                @endif
                            </td>
                            <td class="rc-num"><span class="rc-iso">{{ $row['streak'] }}</span></td>
                            <td><x-rc.chart.pill :tone="$row['card']['pill']" :label="$row['card']['text']" /></td>
                            <td><span class="rc-iso">{{ $row['expiry'] }}</span></td>
                            <td>{{ $row['interval'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="rc-an-pager">
        <span>{{ __($lang.'pager.showing', ['from' => $from, 'to' => $to, 'total' => $total]) }} · {{ __($lang.'pager.legend') }}</span>
        <span class="rc-an-pager__nav">
            <button type="button" class="rc-an-btn rc-an-btn--quiet rc-an-pager__btn" wire:click="goToPage({{ $page - 1 }})"
                    @disabled($page <= 1) aria-label="{{ __($lang.'pager.previous') }}">
                <x-heroicon-m-chevron-left class="rc-an-pager__icon" />
            </button>
            <span>{{ __($lang.'pager.page', ['page' => $page, 'pages' => $pages]) }}</span>
            <button type="button" class="rc-an-btn rc-an-btn--quiet rc-an-pager__btn" wire:click="goToPage({{ $page + 1 }})"
                    @disabled($page >= $pages) aria-label="{{ __($lang.'pager.next') }}">
                <x-heroicon-m-chevron-right class="rc-an-pager__icon" />
            </button>
        </span>
    </div>
</div>
