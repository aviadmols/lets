{{--
    The card-update links sent for this subscription.

    The three-step story is what a merchant chasing a failing card actually
    reads: SENT → OPENED → CARD UPDATED. The gap between any two steps is the
    thing worth acting on — "opened three days ago, still not updated" is a phone
    call, "sent and never opened" is a resend on another channel.

    The URL itself is NOT here and cannot be: the row keeps only a hash, and it
    was shown once when it was created. Revoking is offered instead, which is the
    thing a merchant actually needs after "I sent that to the wrong person".

    TOKENS: component classes only (.rc-section/.rc-kv/.rc-row/.rc-muted/.rc-ltr/
    .rc-banner/.rc-banner--success/.rc-banner--danger).
    ZERO inline CSS. Every value is precomputed on the page; this renders.
--}}
{{-- THE OUTCOME of the customer's last card update, before anything else in the
     section. Green only when the card is actually on the plan; red when PayPlus
     took the card and it still is not, or the bank refused it. --}}
@php $cardOutcome = $this->cardUpdateOutcome(); @endphp
@if($cardOutcome !== null)
    <div class="rc-banner rc-banner--{{ $cardOutcome['tone'] }}">
        <div class="rc-banner__text">
            <span class="rc-banner__title">{{ $cardOutcome['title'] }}</span>
            <span class="rc-banner__body">{{ $cardOutcome['body'] }}</span>
        </div>
    </div>
@endif

{{-- THE LINK JUST MINTED, on the page rather than in a toast or a second modal.
     It is revealed once — the row keeps only a hash — so it gets a real field
     that selects itself, and the WhatsApp message beside it is editable, because
     the merchant knows their customer and a line written for everybody reads
     like one. --}}
@if($this->cardLinkUrl !== '')
    <div class="rc-section">
        <div class="rc-row rc-row--between">
            <span class="rc-section__title">{{ __('card_update.notify.created') }}</span>
            <x-rc.cta variant="ghost" wire:click="dismissCardLink">
                {{ __('card_update.status.done') }}
            </x-rc.cta>
        </div>

        <p class="rc-muted">{{ __('card_update.status.copy_hint') }}</p>

        {{-- The same partial the modal renders, so the two can never drift. --}}
        @include("filament.resources.subscription.card-link-result")
    </div>
@endif

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
