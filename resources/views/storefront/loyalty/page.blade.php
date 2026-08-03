{{--
  The members club — a standalone storefront page, served through the Shopify App
  Proxy on the merchant's own domain or inside an iframe on WooCommerce. It has
  no admin chrome and no framework: its own stylesheet (public/css/loyalty.css)
  and one small vanilla controller (public/js/loyalty.js).

  Everything is precomputed by LoyaltyPagePresenter; this file only draws. Action
  URLs are injected by the server so the browser never builds an endpoint, and a
  visitor the platform did not identify sees the public shape only — tiers, perks
  and how to earn — never a balance.

  Server-injected: $model (presenter output), $actions (URL map).
--}}
@php($a = $model['appearance'])
@php($status = $model['status'])
@php($balance = $model['balance'])
<!DOCTYPE html>
<html lang="{{ $a['locale'] }}" dir="{{ $a['dir'] }}" data-theme="{{ $a['theme'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $model['program_name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/loyalty.css') }}">
    {{-- The merchant's palette + the per-tier colours, as custom properties in
         ONE server-rendered block (the storefront-page precedent — no style=""
         attributes anywhere). The stylesheet reads these; nothing in it is
         hard-coded to a brand. --}}
    <style>
        :root {
            --lets-accent: {{ $a['accent'] }};
            --lets-accent-fg: {{ $a['accent_text'] }};
            --lets-radius: {{ $a['radius'] }};
        }
        @foreach($model['tiers'] as $index => $tier)
        .lc-tier[data-tier="{{ $index }}"] { --lets-tier: {{ $tier['color'] }}; }
        @endforeach
        .lc-progress__fill { inline-size: {{ $status['progress'] }}%; }
    </style>
</head>
<body>
<div class="lc"
     data-lets-loyalty
     data-actions='@json($actions)'
     data-strings='@json(['network' => __('loyalty.page.redeem_failed')])'>

    {{-- ============================ HERO ============================ --}}
    @if($model['is_member'])
        <section class="lc-hero">
            <div class="lc-hero__side">
                <span class="lc-hero__label">{{ __('loyalty.page.tier_current') }}</span>
                <span class="lc-hero__tier">{{ $status['tier'] ?? __('loyalty.page.tier_none') }}</span>
                <div class="lc-progress">
                    <div class="lc-progress__track">
                        <div class="lc-progress__fill"></div>
                    </div>
                    <p class="lc-progress__note">
                        {{ $status['next']
                            ? __('loyalty.page.progress', ['amount' => $status['remaining'], 'tier' => $status['next']])
                            : __('loyalty.page.progress_top') }}
                    </p>
                </div>
            </div>

            <div class="lc-hero__side">
                <span class="lc-hero__label">{{ __('loyalty.page.balance') }}</span>
                <span class="lc-hero__points">{{ $balance['formatted'] }} <small>{{ __('loyalty.page.points_short') }}</small></span>
                @if($balance['credit'])
                    <span class="lc-hero__credit">≈ {{ $balance['credit'] }}</span>
                @endif

                @if($balance['redeemable'])
                    <div class="lc-cta">
                        <button type="button" class="lc-btn" data-lets-redeem>
                            {{ __('loyalty.page.redeem_cta', ['amount' => $balance['credit']]) }}
                        </button>
                    </div>
                @elseif($balance['min_points'] > 0 && $balance['points'] < $balance['min_points'])
                    <span class="lc-hero__credit">{{ __('loyalty.page.redeem_min', ['points' => $balance['min_points']]) }}</span>
                @endif
                <p class="lc-note" data-lets-note="redeem"></p>
            </div>
        </section>
    @else
        {{-- Not a member (or not signed in): the pitch, never someone's balance. --}}
        <section class="lc-prompt">
            <p class="lc-prompt__title">{{ $model['program_name'] }}</p>
            <p class="lc-prompt__body">
                {{ $model['identified'] ? __('loyalty.page.join_intro') : __('loyalty.page.login_required') }}
            </p>
            @if($model['identified'])
                <button type="button" class="lc-btn" data-lets-join>{{ __('loyalty.page.join_cta') }}</button>
                <p class="lc-note" data-lets-note="join"></p>
            @endif
        </section>
    @endif

    {{-- ============================ TIERS ============================ --}}
    @if(count($model['tiers']) > 0)
        <section class="lc-section">
            <h2 class="lc-section__title">{{ __('loyalty.page.tiers_heading') }}</h2>
            <div class="lc-tiers">
                @foreach($model['tiers'] as $index => $tier)
                    <div class="lc-tier @if($tier['is_current']) lc-tier--current @endif" data-tier="{{ $index }}">
                        <x-loyalty.tier-icon :icon="$tier['icon']" class="lc-tier__icon" />
                        <div class="lc-tier__name">{{ $tier['name'] }}</div>
                        <div class="lc-tier__range">{{ $tier['range'] }}</div>
                        @if($tier['is_current'])
                            <span class="lc-tier__badge">{{ __('loyalty.page.tier_current') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ======================= PERK COMPARISON ======================= --}}
        @if(count($model['perks']['rows']) > 0)
            <section class="lc-section">
                <h2 class="lc-section__title">{{ __('loyalty.page.perks_heading') }}</h2>
                <table class="lc-perks">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach($model['perks']['tiers'] as $name)
                                <th>{{ $name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($model['perks']['rows'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                @foreach($row['has'] as $has)
                                    <td>
                                        <span class="lc-mark {{ $has ? 'lc-mark--yes' : 'lc-mark--no' }}"
                                              aria-label="{{ $has ? __('loyalty.page.perk_yes') : __('loyalty.page.perk_no') }}">
                                            {{ $has ? '✓' : '✕' }}
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endif

    {{-- ========================= WAYS TO EARN ========================= --}}
    @if(count($model['earn']) > 0)
        <section class="lc-section">
            <h2 class="lc-section__title">{{ __('loyalty.page.earn_heading') }}</h2>
            <div class="lc-earn">
                @foreach($model['earn'] as $way)
                    <div class="lc-way">
                        <x-loyalty.way-icon :way="$way['key']" class="lc-way__icon" />
                        @if($way['points'])
                            <span class="lc-way__points">{{ $way['points'] }} {{ __('loyalty.page.points_short') }}</span>
                        @endif
                        <span class="lc-way__label">{{ $way['label'] }}</span>

                        @if($way['done'])
                            <span class="lc-way__done">✓ {{ __('loyalty.page.earn_done') }}</span>
                        @elseif($way['action'] === 'social')
                            <button type="button" class="lc-btn lc-btn--sm"
                                    data-lets-claim="{{ $way['key'] }}"
                                    @if($way['url']) data-lets-url="{{ $way['url'] }}" @endif>
                                {{ __('loyalty.page.claim') }}
                            </button>
                        @elseif($way['action'] === 'join')
                            <button type="button" class="lc-btn lc-btn--sm" data-lets-join>
                                {{ __('loyalty.page.join_cta') }}
                            </button>
                        @endif
                        <p class="lc-note" data-lets-note="{{ $way['key'] }}"></p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============================ BIRTHDAY ============================ --}}
    @if($model['is_member'])
        @php($birthdayWay = collect($model['earn'])->firstWhere('key', 'birthday'))
        @if($birthdayWay && ! $birthdayWay['done'])
            <section class="lc-section">
                <h2 class="lc-section__title">{{ __('loyalty.page.birthday_heading') }}</h2>
                <p class="lc-hero__label">{{ __('loyalty.page.birthday_intro') }}</p>
                <form class="lc-cta" data-lets-birthday>
                    <input type="date" class="lc-input" required
                           aria-label="{{ __('loyalty.page.birthday_heading') }}">
                    <button type="submit" class="lc-btn lc-btn--ghost">{{ __('loyalty.page.birthday_save') }}</button>
                </form>
                <p class="lc-note" data-lets-note="birthday"></p>
            </section>
        @endif
    @endif
</div>

<script src="{{ asset('js/loyalty.js') }}"></script>
</body>
</html>
