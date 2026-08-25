{{--
    "Who would get it" — the merchant's look at the audience BEFORE they send.

    Addresses are shown in full here (this is the merchant's own customer list,
    on an authenticated admin screen) but forced left-to-right, so a Hebrew name
    beside one does not reorder the two.

    TOKENS (via public/css/rc-admin.css → components/campaigns.css):
      .rc-campaign-audience .rc-campaign-audience__row
      .rc-campaign-audience__person .rc-campaign-audience__email
      .rc-campaign-audience__flags .rc-campaign-audience__summary
    Zero inline CSS.

    Props: $rows (Collection of audience rows), $total (int).
--}}
@php
    $lang = \App\Filament\Resources\CampaignResource::LANG;
@endphp

<div>
    <div class="rc-campaign-audience">
        @forelse ($rows as $row)
            <div class="rc-campaign-audience__row">
                <span class="rc-campaign-audience__person">
                    <span>{{ $row['name'] ?? __('common.none') }}</span>
                    <span class="rc-campaign-audience__email">{{ $row['email'] }}</span>
                </span>

                <span class="rc-campaign-audience__flags">
                    <span>{{ __($lang.'.rail.'.$row['rail']) }}</span>

                    @if ($row['already_enrolled'])
                        <span>{{ __($lang.'.preview.already_enrolled') }}</span>
                    @endif

                    @if ($row['unsubscribed'])
                        <span>{{ __($lang.'.preview.unsubscribed') }}</span>
                    @endif
                </span>
            </div>
        @empty
            <p class="rc-campaign-audience__summary">{{ __($lang.'.form.nothing_to_send') }}</p>
        @endforelse
    </div>

    @if ($rows->isNotEmpty())
        <p class="rc-campaign-audience__summary">
            {{ __($lang.'.preview.audience_summary', ['shown' => $rows->count(), 'total' => $total]) }}
        </p>
    @endif
</div>
