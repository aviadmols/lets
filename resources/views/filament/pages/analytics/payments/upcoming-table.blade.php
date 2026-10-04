{{--
    The Upcoming payments table — view of App\Livewire\Analytics\UpcomingPaymentsTable.
    Hand-rolled (its cells carry links), on the shared table classes; keyed by its rows so a new page is new nodes.
    TOKENS: .rc-an-table .rc-an-table__wrap .rc-num .rc-strong-cell .rc-seg + rc.chart.pill/empty;
            .rc-an-pay-tablebar .rc-an-pay-search* .rc-an-pay-pager* .rc-an-pay-link .rc-an-pay-sub .rc-an-pay-error
            (components/analytics-payments.css)
--}}
@php $l = 'analytics/payments_upcoming.'; @endphp
<div class="rc-an-pay-table">
    <div class="rc-an-pay-tablebar">
        <label class="rc-an-pay-search">
            <x-heroicon-o-magnifying-glass class="rc-an-pay-search__icon" />
            <span class="rc-sr-only">{{ __($l.'search.label') }}</span>
            <input type="search" class="rc-an-pay-search__input" wire:model.live.debounce.400ms="search"
                   maxlength="80" placeholder="{{ __($l.'search.placeholder') }}">
        </label>
        <div class="rc-seg rc-seg--sm" role="group" aria-label="{{ __($l.'sort.label') }}">
            @foreach($sorts as $value => $text)
                <button type="button" @class(['rc-seg__item', 'rc-seg__item--active' => $sort === $value])
                        aria-pressed="{{ $sort === $value ? 'true' : 'false' }}" wire:click="sortBy(@js($value))">{{ $text }}</button>
            @endforeach
        </div>
    </div>

    @if($rows === [])
        <x-rc.chart.empty variant="no_data" compact
                          :title="$search !== '' ? __($l.'search.none_title') : __($l.'empty.title')"
                          :body="$search !== '' ? __($l.'search.none_body') : __($l.'empty.body')" />
    @else
        <div class="rc-an-table__wrap" wire:key="upcoming-rows-{{ md5(json_encode(array_column($rows, 'key')).$sort.$page) }}">
            <table class="rc-an-table">
                <caption class="rc-sr-only">{{ __($l.'table.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __($l.'col.id') }}</th>
                        <th scope="col">{{ __($l.'col.risk') }}</th>
                        <th scope="col">{{ __($l.'col.customer') }}</th>
                        <th scope="col">{{ __($l.'col.method') }}</th>
                        <th scope="col">{{ __($l.'col.date') }}</th>
                        <th scope="col" class="rc-num">{{ __($l.'col.amount') }}</th>
                        <th scope="col" class="rc-num">{{ __($l.'col.retries') }}</th>
                        <th scope="col">{{ __($l.'col.last_status') }}</th>
                        <th scope="col">{{ __($l.'col.last_error') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="upcoming-row-{{ $row['key'] }}">
                            <td class="rc-strong-cell">
                                @if($row['url'])
                                    <a class="rc-an-pay-link rc-iso" href="{{ $row['url'] }}">{{ $row['ref'] }}</a>
                                @else
                                    <span class="rc-iso">{{ $row['ref'] }}</span>
                                @endif
                                <span class="rc-an-pay-sub">{{ $row['source'] }}</span>
                            </td>
                            <td><x-rc.chart.pill :tone="$row['risk']['pill']" :label="$row['risk']['text']" /></td>
                            <td>
                                {{ $row['customer'] }}
                                @if($row['email'] !== '')<span class="rc-an-pay-sub rc-ident">{{ $row['email'] }}</span>@endif
                            </td>
                            <td><x-rc.chart.pill :tone="$row['method']['pill']" :label="$row['method']['text']" /></td>
                            <td><a class="rc-an-pay-link" href="{{ $row['date_url'] }}" title="{{ __($l.'by_day.link') }}">{{ $row['date'] }}</a></td>
                            <td class="rc-num"><span class="rc-iso">{{ $row['amount'] }}</span></td>
                            <td class="rc-num"><span class="rc-iso">{{ $row['retries'] }}</span></td>
                            <td>
                                @if($row['status'])
                                    <x-rc.chart.pill :tone="$row['status']['pill']" :label="$row['status']['text']" />
                                @else
                                    —
                                @endif
                            </td>
                            <td class="rc-an-pay-error">{{ $row['error'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="rc-an-pay-pager">
        <span class="rc-an-pay-pager__count">{{ __($l.'pager.showing', ['from' => $from, 'to' => $to, 'total' => $total]) }}</span>
        <div class="rc-an-pay-pager__buttons">
            <button type="button" class="rc-an-btn rc-an-btn--quiet" wire:click="goToPage({{ $page - 1 }})" @disabled($page <= 1)>{{ __($l.'pager.previous') }}</button>
            <span class="rc-an-pay-pager__page rc-iso">{{ __($l.'pager.page', ['page' => $page, 'pages' => $pages]) }}</span>
            <button type="button" class="rc-an-btn rc-an-btn--quiet" wire:click="goToPage({{ $page + 1 }})" @disabled($page >= $pages)>{{ __($l.'pager.next') }}</button>
        </div>
    </div>
</div>
