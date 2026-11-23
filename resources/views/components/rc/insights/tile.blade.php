{{--
    rc.insights.tile — one dark tile in a hero's side column: an icon square (lime for the
    tile that matters most, dim for the rest), a number, and what it counts.
    TOKENS: .rc-ins-tile* (components/insights.css). ZERO inline CSS.

    Props: icon (heroicon name), value, label, accent (bool)
--}}
@props(['icon' => 'heroicon-o-chart-bar', 'value' => '', 'label' => '', 'accent' => false])
<div class="rc-ins-tile">
    <span @class(['rc-ins-tile__icon', 'rc-ins-tile__icon--accent' => $accent])>
        <x-filament::icon :icon="$icon" class="rc-ins-tile__glyph" />
    </span>
    <span class="rc-ins-tile__text">
        <span class="rc-ins-tile__value">{{ $value }}</span>
        <span class="rc-ins-tile__label">{{ $label }}</span>
    </span>
</div>
