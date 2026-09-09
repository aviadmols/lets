{{--
    The card-update links sent for this subscription.

    The three-step story is what a merchant chasing a failing card actually
    reads: SENT → OPENED → CARD UPDATED. The gap between any two steps is the
    thing worth acting on — "opened three days ago, still not updated" is a phone
    call, "sent and never opened" is a resend on another channel.

    The URL itself is NOT here and cannot be: the row keeps only a hash, and it
    was shown once when it was created. Revoking is offered instead, which is the
    thing a merchant actually needs after "I sent that to the wrong person".

    TOKENS: component classes only (.rc-section/.rc-kv/.rc-row/.rc-muted/.rc-ltr).
    ZERO inline CSS. Every value is precomputed on the page; this renders.
--}}
@if($this->cardUpdateAvailable())
    @php $links = $this->cardUpdateLinks(); @endphp

    <div class="rc-section">
        <div class="rc-row rc-row--between">
            <span class="rc-section__title">{{ __('card_update.status.heading') }}</span>
            @if($links->contains(fn ($link) => $link->isUsable()))
                {{ $this->revokeCardUpdateLinksAction }}
            @endif
        </div>

        @if($links->isEmpty())
            <p class="rc-muted">{{ __('card_update.status.empty') }}</p>
        @else
            <div class="rc-kv">
                @foreach($links as $link)
                    <span class="rc-kv__k">
                        <x-rc.badge
                            :label="'card_update.state.' . $link->state()"
                            :tone="$link->state() === 'completed' ? 'green' : ($link->state() === 'opened' ? 'teal' : 'gray')" />
                    </span>
                    <span class="rc-kv__v">
                        {{ __('card_update.channel.' . $link->channel) }}
                        @if($link->sent_to)
                            <span class="rc-ltr rc-muted">· {{ $link->sent_to }}</span>
                        @endif
                        <span class="rc-ltr rc-muted">· {{ $link->created_at?->format('d M Y, H:i') }}</span>
                    </span>
                @endforeach
            </div>
        @endif
    </div>
@endif
