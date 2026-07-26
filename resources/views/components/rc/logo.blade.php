{{--
    rc.logo — the LETS brand lockup (the app's identity everywhere it is named).
    TOKENS (via components/logo.css): .rc-logo .rc-logo__mark .rc-logo__word

    The mark is INLINE svg (not an <img>) for two reasons: it inherits the panel's
    text colour through currentColor, so the wordmark reads on a light or a dark
    shell; and the wordmark is real text in the panel font, so it stays crisp at
    any zoom instead of scaling a rasterised image.

    Props:
      mark   — render the two half-discs only (square slots: favicons, avatars)
      label  — accessible name (defaults to the brand name)
--}}
@props([
    'mark' => false,
    'label' => \App\Providers\Filament\AdminPanelProvider::BRAND_NAME,
])

<span {{ $attributes->class(['rc-logo']) }} role="img" aria-label="{{ $label }}">
    <svg class="rc-logo__mark" viewBox="0 0 222 232" aria-hidden="true" focusable="false">
        <path d="M0 0 H222 A111 111 0 0 1 0 0 Z" class="rc-logo__mark-top" />
        <path d="M0 232 H222 A111 111 0 0 0 0 232 Z" class="rc-logo__mark-bottom" />
    </svg>

    @unless($mark)
        <span class="rc-logo__word" aria-hidden="true">let&rsquo;s</span>
    @endunless
</span>
