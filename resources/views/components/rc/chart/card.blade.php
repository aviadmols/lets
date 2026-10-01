{{--
    rc.chart.card — an Analytics card: title row (title, subtitle, actions, "View report →"), then the body.
    TOKENS (components/analytics.css): .rc-an-card .rc-an-card__head/__titles/__title/__sub/__actions/__link/__body/__note
    Props:
      title     — already-translated title (required)
      subtitle  — already-translated caption under the title
      link      — optional URL for the report link; linkLabel overrides its text
      flush     — body without padding (tables that meet the card edges)
    Slots: default (body), actions (toggles beside the title), note (caption under the body)
--}}
@props([
    'title',
    'subtitle' => null,
    'link' => null,
    'linkLabel' => null,
    'flush' => false,
])
<section {{ $attributes->class(['rc-an-card', 'rc-an-card--flush' => $flush]) }}>
    <header class="rc-an-card__head">
        <div class="rc-an-card__titles">
            <h2 class="rc-an-card__title">{{ $title }}</h2>
            @if($subtitle)<p class="rc-an-card__sub">{{ $subtitle }}</p>@endif
        </div>
        @if(isset($actions) || $link)
            <div class="rc-an-card__actions">
                {{ $actions ?? '' }}
                @if($link)
                    <a class="rc-an-card__link" href="{{ $link }}">{{ $linkLabel ?? __('analytics.view_report') }}</a>
                @endif
            </div>
        @endif
    </header>
    <div class="rc-an-card__body">
        {{ $slot }}
        @isset($note)<p class="rc-an-card__note">{{ $note }}</p>@endisset
    </div>
</section>
