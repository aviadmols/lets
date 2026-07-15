<?php

namespace App\Domain\Upsell\Rendering;

use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Models\MerchantUpsellAppearance;

/**
 * The ONE serializer that turns an (offer, appearance, platform) into the view-model the shared
 * renderer (public/upsell/lets-ppu.js) consumes — byte-identically on the live WooCommerce widget
 * AND the Filament preview. There is exactly one card shape; per-platform differs only in the
 * transport handlers the renderer is wired with, never in this payload.
 *
 * MONEY LAW: every money value here is SERVER-computed from $offer->discountedPrice() /
 * $offer->base_price and formatted server-side. The client never sends or influences an amount;
 * the amount in the consent disclosure is the exact amount that will be charged.
 */
final class UpsellCardPresenter
{
    // === CONSTANTS ===
    public const PLATFORM_WOOCOMMERCE = 'woocommerce';
    public const PLATFORM_SHOPIFY = 'shopify';

    private const DEFAULT_CURRENCY = 'ILS';

    /** Display symbols for the currencies we format; unknown → the code itself. */
    private const CURRENCY_SYMBOLS = [
        'ILS' => '₪',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    /** A monochrome SVG placeholder so the preview's image element renders without an external asset. */
    private const SAMPLE_IMAGE = 'data:image/svg+xml;utf8,'
        .'%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20600%20420%22%3E'
        .'%3Cdefs%3E%3ClinearGradient%20id%3D%22g%22%20x1%3D%220%22%20y1%3D%220%22%20x2%3D%221%22%20y2%3D%221%22%3E'
        .'%3Cstop%20offset%3D%220%22%20stop-color%3D%22%23ececec%22%2F%3E%3Cstop%20offset%3D%221%22%20stop-color%3D%22%23d8d8d8%22%2F%3E'
        .'%3C%2FlinearGradient%3E%3C%2Fdefs%3E%3Crect%20width%3D%22600%22%20height%3D%22420%22%20fill%3D%22url(%23g)%22%2F%3E'
        .'%3Cpath%20d%3D%22M300%20150l70%20120H230z%22%20fill%3D%22%23bdbdbd%22%2F%3E'
        .'%3Ccircle%20cx%3D%22250%22%20cy%3D%22168%22%20r%3D%2222%22%20fill%3D%22%23bdbdbd%22%2F%3E%3C%2Fsvg%3E';

    /**
     * Build the full view-model for one offer under the shop's appearance.
     *
     * @return array<string, mixed>
     */
    public function forOffer(UpsellFlowOffer $offer, MerchantUpsellAppearance $appearance, string $platform): array
    {
        $currency = (string) ($offer->currency ?? config('payplus.currency', self::DEFAULT_CURRENCY));

        // Money truth — server-computed, never from the client.
        $price = $offer->discountedPrice();
        $base = round((float) $offer->base_price, 2);
        $hasDiscount = $offer->discount_type !== UpsellFlowOffer::DISCOUNT_NONE && $base > $price;
        $save = $hasDiscount ? round($base - $price, 2) : 0.0;
        $savePercent = ($hasDiscount && $base > 0) ? (int) round($save / $base * 100) : 0;

        $product = $offer->resolveProduct();

        $priceDisplay = $this->money($price, $currency);
        $timerSeconds = ($offer->show_timer && (int) $offer->timer_minutes > 0)
            ? (int) $offer->timer_minutes * 60
            : null;

        return [
            'platform' => $platform,
            'content' => [
                'headline' => (string) ($offer->headline ?: $offer->offer_title ?: __('upsell.default_headline')),
                'product_name' => $product?->title,
                'product_image' => $product?->image_url,
                'subcopy' => $this->blankToNull($offer->subcopy),

                'currency' => $currency,
                'price' => $price,
                'price_display' => $priceDisplay,
                'was_display' => $hasDiscount ? $this->money($base, $currency) : null,
                'save_label' => $hasDiscount ? __('upsell.you_save', ['amount' => $this->money($save, $currency)]) : null,
                'save_percent' => $savePercent,
                'has_discount' => $hasDiscount,

                'accept_cta' => (string) ($offer->accept_cta ?: __('upsell.accept_cta')),
                'decline_cta' => (string) ($offer->decline_cta ?: __('upsell.decline_cta')),

                'eyebrow' => $appearance->eyebrowText() ?? __('upsell.widget_eyebrow'),
                'badge' => $appearance->badgeText(),
                'trust' => $appearance->trustText() ?? __('upsell.no_card_reentry'),

                'timer_seconds' => $timerSeconds,

                // LOCKED, always rendered — the exact amount that will be charged to the saved card.
                'disclosure' => __('upsell.consent_disclosure', ['amount' => $priceDisplay]),

                // State-machine labels (localized; the renderer swaps these in).
                'accept_busy' => __('upsell.adding'),
                'error_text' => __('upsell.error_generic'),
                'success_title' => __('upsell.success_title'),
                'success_sub' => __('upsell.no_card_reentry'),
            ],
            'appearance' => $this->appearance($appearance),
        ];
    }

    /**
     * A fixed, clearly-labelled SAMPLE view-model so the builder/preview never renders empty when
     * the shop has no offer yet. Reflects the merchant's live appearance (colours, elements, and
     * the eyebrow/badge/trust copy) but uses a fixed sample product + price. No DB, no charge.
     *
     * @return array<string, mixed>
     */
    public function sample(MerchantUpsellAppearance $appearance, string $platform): array
    {
        $currency = (string) config('payplus.currency', self::DEFAULT_CURRENCY);
        $base = 99.90;
        $price = 79.90;
        $save = round($base - $price, 2);
        $priceDisplay = $this->money($price, $currency);

        return [
            'platform' => $platform,
            'is_sample' => true,
            'content' => [
                'headline' => __('upsell.preview.sample_headline'),
                'product_name' => __('upsell.preview.sample_product'),
                'product_image' => self::SAMPLE_IMAGE,
                'subcopy' => __('upsell.preview.sample_subcopy'),

                'currency' => $currency,
                'price' => $price,
                'price_display' => $priceDisplay,
                'was_display' => $this->money($base, $currency),
                'save_label' => __('upsell.you_save', ['amount' => $this->money($save, $currency)]),
                'save_percent' => 20,
                'has_discount' => true,

                'accept_cta' => __('upsell.accept_cta'),
                'decline_cta' => __('upsell.decline_cta'),

                'eyebrow' => $appearance->eyebrowText() ?? __('upsell.widget_eyebrow'),
                'badge' => $appearance->badgeText(),
                'trust' => $appearance->trustText() ?? __('upsell.no_card_reentry'),

                'timer_seconds' => null,
                'disclosure' => __('upsell.consent_disclosure', ['amount' => $priceDisplay]),

                'accept_busy' => __('upsell.adding'),
                'error_text' => __('upsell.error_generic'),
                'success_title' => __('upsell.success_title'),
                'success_sub' => __('upsell.no_card_reentry'),
            ],
            'appearance' => $this->appearance($appearance),
        ];
    }

    /**
     * The appearance block — tokens + the resolved (locked-enforced, ordered) element list. Never
     * carries money; safe to postMessage into the live-preview iframe as an unsaved draft.
     *
     * @return array<string, mixed>
     */
    public function appearance(MerchantUpsellAppearance $appearance): array
    {
        return [
            'theme' => $appearance->themeMode(),
            'accent' => $appearance->accentColor(),
            'accent_text' => $appearance->accentTextColor(),
            'button_style' => $appearance->buttonStyle(),
            'radius_px' => $appearance->cornerRadiusPx(),
            'shadow' => $appearance->cardShadow(),
            'font' => $appearance->themeFont(),
            'layout' => $appearance->layout(),
            'image_ratio' => $appearance->imageRatio(),
            'decline_style' => $appearance->declineStyle(),
            'elements' => $appearance->elements(),
        ];
    }

    private function money(float $amount, string $currency): string
    {
        $symbol = self::CURRENCY_SYMBOLS[strtoupper($currency)] ?? ($currency.' ');

        return $symbol.number_format(round($amount, 2), 2);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
