<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-shop PERSONAL-AREA settings, read + written by the WordPress plugin's own
 * admin screens (HMAC-signed; the shop is the verified tenant).
 *
 * The merchant edits in WordPress — banners, appearance, section order, the copy
 * a shopper reads — but LETS stays the single source of truth: the same row
 * feeds the WooCommerce area, the Filament preview and any future surface, so
 * they cannot drift apart. This controller is the WooCommerce DOOR to that row,
 * not a second copy of it.
 *
 * The plugin body is HMAC-signed but is still MERCHANT INPUT. Nothing is stored
 * verbatim: every field is read by name, coerced, clamped, and — for the two URL
 * fields — forced to https, because a merchant-typed `javascript:` would ship
 * straight onto a customer page. Unknown keys are ignored. The model's own
 * accessors sanitise a SECOND time on the way out, so a row written before a
 * rule existed still cannot reach a shopper.
 */
final class PortalSettingsController extends WooStorefrontController
{
    // === CONSTANTS ===

    /** Plain text the merchant types, and how much of it we keep. */
    private const TEXT_FIELDS = [
        'welcome_heading' => 120,
        'welcome_subtext' => 500,
        'gifts_heading' => 120,
        'support_email' => 190,
    ];

    private const MAX_BANNER_HEADING = 80;

    private const MAX_BANNER_SUBTEXT = 300;

    private const MAX_URL = 500;

    /** GET /api/woocommerce/portal-settings — what the plugin renders its forms from. */
    public function show(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json(['ok' => true, 'settings' => $this->payload($shop)]);
    }

    /** POST /api/woocommerce/portal-settings — save the merchant's choices. */
    public function update(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        Tenant::run($shop, function () use ($request): void {
            $settings = MerchantPortalAppearance::current();

            $this->applyAppearance($request, $settings);
            $this->applyCopy($request, $settings);

            if ($request->has('sections')) {
                $settings->sections = $this->cleanSections((array) $request->input('sections', []));
            }

            if ($request->has('nav_tabs')) {
                $settings->nav_tabs = $this->cleanTabs((array) $request->input('nav_tabs', []));
            }

            if ($request->has('banners')) {
                $settings->banners = $this->cleanBanners(
                    (array) $request->input('banners', []),
                    (array) ($settings->banners ?? []),
                );
            }

            if ($request->has('login_code_enabled')) {
                $settings->login_code_enabled = $request->boolean('login_code_enabled');
            }

            if ($request->has('login_code_channel')) {
                $channel = (string) $request->input('login_code_channel');
                $settings->login_code_channel = in_array($channel, MerchantPortalAppearance::LOGIN_CHANNELS, true)
                    ? $channel
                    : MerchantPortalAppearance::CHANNEL_EMAIL;
            }

            // Anything not shaped like a client id is stored as nothing, not as
            // itself: the value lands on a customer page and in an `aud` check.
            if ($request->has('login_google_client_id')) {
                $id = trim((string) $request->input('login_google_client_id'));
                $settings->login_google_client_id =
                    preg_match(MerchantPortalAppearance::GOOGLE_CLIENT_ID_PATTERN, $id) === 1 ? $id : null;
            }

            $settings->save();
        });

        return response()->json(['ok' => true, 'settings' => $this->payload($shop)]);
    }

    /**
     * Colours and the four enums. An unrecognised enum falls back to the house
     * default rather than being stored: the renderer selects on these values as
     * data-attributes, and an unknown one is a silently unstyled area.
     */
    private function applyAppearance(Request $request, MerchantPortalAppearance $settings): void
    {
        if ($request->has('accent_color')) {
            $settings->accent_color = $this->hexOrDefault(
                $request->input('accent_color'),
                MerchantPortalAppearance::DEFAULT_ACCENT,
            );
        }

        if ($request->has('accent_text_color')) {
            $settings->accent_text_color = $this->hexOrDefault(
                $request->input('accent_text_color'),
                MerchantPortalAppearance::DEFAULT_ACCENT_TEXT,
            );
        }

        $enums = [
            'theme_mode' => [MerchantPortalAppearance::THEME_MODES, MerchantPortalAppearance::THEME_LIGHT],
            'corner_radius' => [MerchantPortalAppearance::CORNER_RADII, MerchantPortalAppearance::RADIUS_SOFT],
            'density' => [MerchantPortalAppearance::DENSITIES, MerchantPortalAppearance::DENSITY_COMFORTABLE],
            'card_style' => [MerchantPortalAppearance::CARD_STYLES, MerchantPortalAppearance::CARD_OUTLINED],
            'font_family' => [MerchantPortalAppearance::FONTS, MerchantPortalAppearance::FONT_THEME],
        ];

        foreach ($enums as $field => [$allowed, $fallback]) {
            if ($request->has($field)) {
                $value = (string) $request->input($field);
                $settings->{$field} = in_array($value, $allowed, true) ? $value : $fallback;
            }
        }

        if ($request->has('page_locale')) {
            $locale = (string) $request->input('page_locale');
            $settings->page_locale = in_array($locale, self::locales(), true)
                ? $locale
                : MerchantPortalAppearance::LOCALE_AUTO;
        }
    }

    /** The greeting and the support details. Blank clears the field. */
    private function applyCopy(Request $request, MerchantPortalAppearance $settings): void
    {
        foreach (self::TEXT_FIELDS as $field => $limit) {
            if (! $request->has($field)) {
                continue;
            }

            $value = trim((string) $request->input($field));
            $settings->{$field} = $value !== '' ? mb_substr($value, 0, $limit) : null;
        }

        if ($request->has('support_url')) {
            $settings->support_url = $this->httpsOrNull($request->input('support_url'));
        }
    }

    /**
     * The merchant's ordered section list. Only known keys survive, duplicates
     * collapse, and a locked section cannot be turned off — the same three rules
     * the model applies on read, applied here so the STORED row is already true.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{key: string, enabled: bool}>
     */
    private function cleanSections(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : (string) $row;

            if (! in_array($key, MerchantPortalAppearance::SECTION_KEYS, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $enabled = is_array($row) ? (bool) ($row['enabled'] ?? false) : true;

            $out[] = [
                'key' => $key,
                'enabled' => in_array($key, MerchantPortalAppearance::LOCKED_SECTIONS, true) || $enabled,
            ];
        }

        return $out === [] ? MerchantPortalAppearance::defaultSections() : $out;
    }

    /**
     * The merchant's ordered TAB list — the same three rules as the sections,
     * over the navigation's own vocabulary.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{key: string, enabled: bool}>
     */
    private function cleanTabs(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : (string) $row;

            if (! in_array($key, MerchantPortalAppearance::TAB_KEYS, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $enabled = is_array($row) ? (bool) ($row['enabled'] ?? false) : true;

            $out[] = [
                'key' => $key,
                'enabled' => in_array($key, MerchantPortalAppearance::LOCKED_TABS, true) || $enabled,
            ];
        }

        return $out === [] ? MerchantPortalAppearance::defaultTabs() : $out;
    }

    /**
     * The banners. Empty slots are kept as rows so the plugin's three forms
     * stay stable across a save, but a slot with neither heading nor image can
     * never reach a shopper — the model drops it on read.
     *
     * Placement and audience are edited in the LETS dashboard, not here. A body
     * that never carried them therefore keeps what the SLOT already had — the
     * plugin posts three fixed slots, so the index is the slot — or a merchant
     * who saved a colour in WordPress would find their top banner back in the
     * rail. A value that IS sent but is not one of ours falls back.
     *
     * @param  array<int, mixed>  $rows
     * @param  array<int, mixed>  $stored
     * @return list<array{enabled: bool, heading: ?string, subtext: ?string, image_url: ?string, link_url: ?string, placement: string, audience: string}>
     */
    private function cleanBanners(array $rows, array $stored = []): array
    {
        $out = [];
        $stored = array_values($stored);

        foreach (array_values($rows) as $index => $row) {
            if (count($out) >= MerchantPortalAppearance::BANNER_SLOTS) {
                break;
            }

            $row = is_array($row) ? $row : [];
            $was = is_array($stored[$index] ?? null) ? $stored[$index] : [];

            $out[] = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'heading' => $this->textOrNull($row['heading'] ?? null, self::MAX_BANNER_HEADING),
                'subtext' => $this->textOrNull($row['subtext'] ?? null, self::MAX_BANNER_SUBTEXT),
                'image_url' => $this->httpsOrNull($row['image_url'] ?? null),
                'link_url' => $this->httpsOrNull($row['link_url'] ?? null),
                'placement' => $this->oneOf(
                    $row['placement'] ?? $was['placement'] ?? null,
                    MerchantPortalAppearance::BANNER_PLACEMENTS,
                    MerchantPortalAppearance::BANNER_RAIL,
                ),
                'audience' => $this->oneOf(
                    $row['audience'] ?? $was['audience'] ?? null,
                    MerchantPortalAppearance::BANNER_AUDIENCES,
                    MerchantPortalAppearance::AUDIENCE_EVERYONE,
                ),
            ];
        }

        return $out;
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * Everything the plugin's screens need, read back through the model so what
     * we echo is what a shopper will get — including the catalogues the selects
     * are built from, so the two sides can never drift.
     *
     * @return array<string, mixed>
     */
    private function payload(Shop $shop): array
    {
        return Tenant::run($shop, static function (): array {
            $s = MerchantPortalAppearance::current();

            $banners = [];
            foreach (array_values((array) ($s->banners ?? [])) as $row) {
                $row = is_array($row) ? $row : [];
                $banners[] = [
                    'enabled' => (bool) ($row['enabled'] ?? false),
                    'heading' => $row['heading'] ?? null,
                    'subtext' => $row['subtext'] ?? null,
                    'image_url' => $row['image_url'] ?? null,
                    'link_url' => $row['link_url'] ?? null,
                    'placement' => $row['placement'] ?? MerchantPortalAppearance::BANNER_RAIL,
                    'audience' => $row['audience'] ?? MerchantPortalAppearance::AUDIENCE_EVERYONE,
                ];
            }

            return [
                // --- appearance ---
                'accent_color' => $s->accent_color,
                'accent_text_color' => $s->accent_text_color,
                'theme_mode' => $s->theme_mode,
                'corner_radius' => $s->corner_radius,
                'density' => $s->density,
                'card_style' => $s->card_style,
                'font_family' => $s->fontFamily(),
                'page_locale' => $s->page_locale ?? MerchantPortalAppearance::LOCALE_AUTO,

                // --- structure ---
                'sections' => $s->sections(),
                'nav_tabs' => $s->navTabs(),
                'banners' => $banners,

                // What the shopper will ACTUALLY see: a banner missing a heading
                // and an image, or carrying a non-https URL, is not in this list.
                'banners_live' => $s->banners(),

                // --- copy ---
                'welcome_heading' => $s->welcome_heading,
                'welcome_subtext' => $s->welcome_subtext,
                'gifts_heading' => $s->gifts_heading,
                'support_email' => $s->support_email,
                'support_url' => $s->support_url,

                // --- sign-in ---
                'login_code_enabled' => (bool) $s->login_code_enabled,
                'login_code_channel' => $s->login_code_channel,
                'login_google_client_id' => $s->loginGoogleClientId(),

                // --- catalogues the plugin renders its controls from ---
                'available_sections' => MerchantPortalAppearance::SECTION_KEYS,
                'locked_sections' => MerchantPortalAppearance::LOCKED_SECTIONS,
                'available_tabs' => MerchantPortalAppearance::TAB_KEYS,
                'locked_tabs' => MerchantPortalAppearance::LOCKED_TABS,
                'available_themes' => MerchantPortalAppearance::THEME_MODES,
                'available_radii' => MerchantPortalAppearance::CORNER_RADII,
                'available_densities' => MerchantPortalAppearance::DENSITIES,
                'available_card_styles' => MerchantPortalAppearance::CARD_STYLES,
                'available_fonts' => MerchantPortalAppearance::FONTS,
                'available_locales' => self::locales(),
                'available_login_channels' => MerchantPortalAppearance::LOGIN_CHANNELS,
                'available_banner_placements' => MerchantPortalAppearance::BANNER_PLACEMENTS,
                'available_banner_audiences' => MerchantPortalAppearance::BANNER_AUDIENCES,
                'banner_slots' => MerchantPortalAppearance::BANNER_SLOTS,
            ];
        });
    }

    /** @return list<string> */
    private static function locales(): array
    {
        return [
            MerchantPortalAppearance::LOCALE_AUTO,
            MerchantPortalAppearance::LOCALE_HE,
            MerchantPortalAppearance::LOCALE_EN,
        ];
    }

    private function hexOrDefault(mixed $value, string $default): string
    {
        $hex = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? strtolower($hex) : $default;
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' ? mb_substr($text, 0, $limit) : null;
    }

    /**
     * https only, on both the image and the link. A merchant pasting an http URL
     * gets an empty field back rather than a mixed-content image the browser
     * blocks on their own storefront.
     */
    private function httpsOrNull(mixed $value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return mb_substr($url, 0, self::MAX_URL);
    }
}
