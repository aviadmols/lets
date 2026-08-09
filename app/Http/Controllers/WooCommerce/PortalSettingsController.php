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

            if ($request->has('banners')) {
                $settings->banners = $this->cleanBanners((array) $request->input('banners', []));
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
     * @param  array<int, mixed> $rows
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
     * The side rail. Empty slots are kept as rows so the plugin's three forms
     * stay stable across a save, but a slot with neither heading nor image can
     * never reach a shopper — the model drops it on read.
     *
     * @param  array<int, mixed> $rows
     * @return list<array{enabled: bool, heading: ?string, subtext: ?string, image_url: ?string, link_url: ?string}>
     */
    private function cleanBanners(array $rows): array
    {
        $out = [];

        foreach (array_values($rows) as $row) {
            if (count($out) >= MerchantPortalAppearance::BANNER_SLOTS) {
                break;
            }

            $row = is_array($row) ? $row : [];

            $out[] = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'heading' => $this->textOrNull($row['heading'] ?? null, self::MAX_BANNER_HEADING),
                'subtext' => $this->textOrNull($row['subtext'] ?? null, self::MAX_BANNER_SUBTEXT),
                'image_url' => $this->httpsOrNull($row['image_url'] ?? null),
                'link_url' => $this->httpsOrNull($row['link_url'] ?? null),
            ];
        }

        return $out;
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
                'page_locale' => $s->page_locale ?? MerchantPortalAppearance::LOCALE_AUTO,

                // --- structure ---
                'sections' => $s->sections(),
                'banners' => $banners,

                // What the shopper will ACTUALLY see: a banner missing a heading
                // and an image, or carrying a non-https URL, is not in this list.
                'banners_live' => $s->banners(),

                // --- copy ---
                'welcome_heading' => $s->welcome_heading,
                'welcome_subtext' => $s->welcome_subtext,
                'support_email' => $s->support_email,
                'support_url' => $s->support_url,

                // --- sign-in ---
                'login_code_enabled' => (bool) $s->login_code_enabled,
                'login_code_channel' => $s->login_code_channel,

                // --- catalogues the plugin renders its controls from ---
                'available_sections' => MerchantPortalAppearance::SECTION_KEYS,
                'locked_sections' => MerchantPortalAppearance::LOCKED_SECTIONS,
                'available_themes' => MerchantPortalAppearance::THEME_MODES,
                'available_radii' => MerchantPortalAppearance::CORNER_RADII,
                'available_densities' => MerchantPortalAppearance::DENSITIES,
                'available_card_styles' => MerchantPortalAppearance::CARD_STYLES,
                'available_locales' => self::locales(),
                'available_login_channels' => MerchantPortalAppearance::LOGIN_CHANNELS,
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
