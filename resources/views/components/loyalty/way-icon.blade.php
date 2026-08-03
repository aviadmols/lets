{{--
  "Ways to earn" icons — one per earning route, inline for the same reason the
  tier icons are (no cross-origin image request from the merchant's page).

  Props: way — 'purchase' | 'join' | 'birthday' | a social action key.
--}}
@props(['way' => 'purchase'])

<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($way)
        @case('join')
            {{-- People: joining the club. --}}
            <path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20" />
            <circle cx="9" cy="7" r="3.2" />
            <path d="M18 8.5h4M20 6.5v4" />
            @break

        @case('birthday')
            {{-- A wrapped gift. --}}
            <path d="M3 11h18v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" />
            <path d="M2.5 7.5h19V11h-19zM12 7.5V21" />
            <path d="M12 7.5S10.5 3 8 3a2.2 2.2 0 0 0 0 4.5zM12 7.5S13.5 3 16 3a2.2 2.2 0 0 1 0 4.5z" />
            @break

        @case('facebook_like')
            {{-- A thumbs-up. --}}
            <path d="M7 21V10l5-7 1.2.6a2 2 0 0 1 1 2.3L13 10h5.5a2 2 0 0 1 2 2.4l-1.4 7A2 2 0 0 1 17 21z" />
            <path d="M7 10H4a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h3" />
            @break

        @case('instagram_follow')
            <rect x="3" y="3" width="18" height="18" rx="5" />
            <circle cx="12" cy="12" r="4" />
            <circle cx="17.2" cy="6.8" r="1" fill="currentColor" stroke="none" />
            @break

        @case('tiktok_follow')
            <path d="M14 3v11.5a3.5 3.5 0 1 1-3-3.46" />
            <path d="M14 3c.6 2.6 2.3 4.2 5 4.5" />
            @break

        @default
            {{-- purchase (and any custom action): a shopping bag. --}}
            <path d="M4.5 8h15l-1.2 12a1 1 0 0 1-1 .9H6.7a1 1 0 0 1-1-.9z" />
            <path d="M8.5 8V6.2a3.5 3.5 0 0 1 7 0V8" />
    @endswitch
</svg>
