{{--
    rc.timeline — activity / timeline feed (components §4.14)
    TOKENS (via components/timeline.css): .rc-timeline .rc-timeline__*
    Reuses the engine's dot + label + actor + time presentation language.

    HARD RULE: never render invoice_url / document_url. EventPresenter::summarize()
    whitelists safe detail keys; this Blade only ever prints what it returns.

    Props:
      events — iterable of App\Models\ActivityEvent (already shop-scoped + ordered)
--}}
@props([
    'events' => [],
])
@php use App\Support\Ui\EventPresenter; @endphp
@if(count($events) === 0)
    <x-rc.empty title="customers.detail.timeline_empty" icon="heroicon-o-clock" />
@else
    <div {{ $attributes->merge(['class' => 'rc-timeline']) }}>
        @foreach($events as $event)
            @php
                $tone = EventPresenter::tone($event);
                $summary = EventPresenter::summarize($event);
            @endphp
            <div class="rc-timeline__row">
                <span class="rc-timeline__dot rc-timeline__dot--{{ $tone }}"></span>
                <div class="rc-timeline__body">
                    <span class="rc-timeline__title">{{ EventPresenter::label($event) }}</span>
                    @if($summary)
                        <span class="rc-timeline__summary rc-ltr">{{ $summary }}</span>
                    @endif
                    <span class="rc-timeline__meta">
                        <span class="rc-timeline__actor">{{ EventPresenter::actorLabel($event) }}</span>
                        <span class="rc-ltr">{{ optional($event->created_at)->format('d M Y, H:i') }}</span>
                    </span>
                </div>
            </div>
        @endforeach
    </div>
@endif
