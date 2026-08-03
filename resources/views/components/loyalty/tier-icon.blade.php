{{--
  Tier icons, drawn inline as SVG.

  Inline rather than image files because this page is served on the merchant's
  own domain through the App Proxy: an <img> would be a second request to OUR
  host from THEIR page, which is both slower and a cross-origin dependency the
  page does not need. Each shape inherits `currentColor`, so the tier's colour
  comes from the CSS custom property and nothing here is hard-coded.

  Props: icon — one of LoyaltyTier::ICONS (unknown falls back to spark).
--}}
@props(['icon' => 'spark'])

<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($icon)
        @case('glow')
            {{-- An eight-point asterisk: the middle rung. --}}
            <path d="M12 2v20M2 12h20M4.9 4.9l14.2 14.2M19.1 4.9L4.9 19.1" />
            @break

        @case('shine')
            {{-- A dense star burst: the top rung. --}}
            <path d="M12 1.5l1.6 5.2 4.4-3-3 4.4 5.2 1.6-5.2 1.6 3 4.4-4.4-3L12 22.5l-1.6-5.2-4.4 3 3-4.4L3.8 14.3l5.2-1.6-3-4.4 4.4 3z"
                  fill="currentColor" stroke="none" />
            @break

        @case('star')
            <path d="M12 2.5l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3 1.1-6.5L2.6 9.3l6.5-.9z" fill="currentColor" stroke="none" />
            @break

        @case('crown')
            <path d="M3 8l4 4 5-7 5 7 4-4v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" fill="currentColor" stroke="none" />
            @break

        @case('gem')
            <path d="M6 3h12l4 6-10 12L2 9z" />
            <path d="M2 9h20M12 21L8 9l4-6 4 6z" />
            @break

        @case('heart')
            <path d="M12 20.5S3.5 15 3.5 9.2A4.7 4.7 0 0 1 12 6.6a4.7 4.7 0 0 1 8.5 2.6c0 5.8-8.5 11.3-8.5 11.3z" fill="currentColor" stroke="none" />
            @break

        @default
            {{-- spark: the four-point entry star. --}}
            <path d="M12 1.5c.7 5.3 3.7 8.3 9 9-5.3.7-8.3 3.7-9 9-.7-5.3-3.7-8.3-9-9 5.3-.7 8.3-3.7 9-9z" fill="currentColor" stroke="none" />
    @endswitch
</svg>
