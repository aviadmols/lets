<?php

namespace App\Domain\Upsell\Rendering;

use App\Models\MerchantUpsellAppearance;

/**
 * Translates the merchant's upsell appearance into what Shopify's POST-PURCHASE
 * component set can actually express — and says plainly what it cannot.
 *
 * Shopify does not allow custom CSS in a post-purchase extension: every element
 * is one of Shopify's own components and inherits the store's checkout branding.
 * So roughly half of the appearance settings (accent colour, corner radius, card
 * shadow, webfont, light/dark) have no expression there at all.
 *
 * Pretending otherwise is the failure this class exists to prevent. It answers
 * two questions with one object: what the extension should RENDER, and which
 * controls the admin must mark as not applying — so the preview can be honest
 * instead of showing a card the shopper will never see.
 *
 * ONE object feeds BOTH the extension payload and the admin preview, which is
 * what makes the preview faithful by construction rather than by maintenance.
 *
 * The WooCommerce / App-Proxy HTML card is untouched by any of this: it renders
 * OUR markup, where every appearance setting still applies in full.
 */
final class PostPurchasePresenter
{
    // === CONSTANTS ===
    /**
     * Elements the post-purchase component set can render. The merchant's own
     * order is preserved; anything outside this list is dropped rather than
     * silently mis-rendered.
     *
     * `timer` is absent deliberately: a countdown needs a re-render loop the
     * post-purchase runtime does not give us, and a frozen timer reads as broken.
     *
     * @var list<string>
     */
    public const SUPPORTED_ELEMENTS = [
        'eyebrow', 'badge', 'image', 'headline', 'product_name',
        'subcopy', 'price', 'save', 'trust', 'cta', 'decline', 'disclosure',
    ];

    /**
     * Appearance settings with NO expression in a post-purchase extension. Shown
     * in the admin so a merchant never tunes a control that does nothing here.
     *
     * @var list<string>
     */
    public const UNSUPPORTED_SETTINGS = [
        'accent_color', 'accent_text_color', 'button_style',
        'corner_radius', 'card_shadow', 'theme_font', 'theme_mode',
        // Dropped silently until now: present() never emits a ratio and the
        // extension renders a bare <Image>, so the control did nothing while
        // still looking live in the admin.
        'image_ratio',
    ];

    /** Shopify's own spacing scale, which is all the density control we have. */
    public const SPACING_TIGHT = 'tight';
    public const SPACING_BASE = 'base';
    public const SPACING_LOOSE = 'loose';

    /**
     * Build the presentation contract for one already-presented offer.
     *
     * @param  array<string, mixed>  $card  a UpsellCardPresenter view-model
     * @return array<string, mixed>
     */
    public function present(array $card, MerchantUpsellAppearance $appearance): array
    {
        $enabled = $this->enabledElements($appearance);

        return [
            // What to show, in the merchant's own order, filtered to what Shopify
            // can render. The extension walks this list; it decides nothing.
            'order' => $this->order($appearance, $enabled),
            'elements' => $enabled,

            // Image beside the text (Layout media) or above it (BlockStack).
            'layout' => $appearance->layout === MerchantUpsellAppearance::LAYOUT_MEDIA_SIDE
                ? MerchantUpsellAppearance::LAYOUT_MEDIA_SIDE
                : MerchantUpsellAppearance::LAYOUT_STACKED,

            // A decline rendered as a plain (link-like) button or a subdued one —
            // the closest the component set comes to the card's link/button choice.
            'decline_style' => $appearance->decline_style === MerchantUpsellAppearance::DECLINE_BUTTON
                ? MerchantUpsellAppearance::DECLINE_BUTTON
                : MerchantUpsellAppearance::DECLINE_LINK,

            'spacing' => self::SPACING_LOOSE,

            // Every string the extension prints, already resolved and localized —
            // so the extension never falls back to a hardcoded English default and
            // the merchant's own copy is what the shopper reads.
            'copy' => $this->copy($card),

            // The honest half: what the merchant set that cannot apply here.
            'unsupported_settings' => self::UNSUPPORTED_SETTINGS,
        ];
    }

    /**
     * Which supported elements are on. Locked elements stay on whatever the stored
     * JSON says — the model already guarantees that; this only narrows to what
     * Shopify can draw.
     *
     * @return array<string, bool>
     */
    private function enabledElements(MerchantUpsellAppearance $appearance): array
    {
        $resolved = [];
        foreach ($appearance->elements() as $element) {
            $key = (string) ($element['key'] ?? '');
            if (in_array($key, self::SUPPORTED_ELEMENTS, true)) {
                $resolved[$key] = (bool) ($element['enabled'] ?? false);
            }
        }

        foreach (self::SUPPORTED_ELEMENTS as $key) {
            $resolved[$key] ??= false;
        }

        return $resolved;
    }

    /**
     * The merchant's element ORDER, filtered to the supported set. Order is a real
     * design decision — putting the price above the headline is a different offer
     * — so it survives the translation even though the styling does not.
     *
     * @param  array<string, bool>  $enabled
     * @return list<string>
     */
    private function order(MerchantUpsellAppearance $appearance, array $enabled): array
    {
        $order = [];
        foreach ($appearance->elements() as $element) {
            $key = (string) ($element['key'] ?? '');
            if (isset($enabled[$key]) && $enabled[$key]) {
                $order[] = $key;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, string|null>
     */
    private function copy(array $card): array
    {
        $content = (array) ($card['content'] ?? []);

        return [
            'eyebrow' => $content['eyebrow'] ?? null,
            'badge' => $content['badge'] ?? null,
            'headline' => $content['headline'] ?? null,
            'product_name' => $content['product_name'] ?? null,
            'subcopy' => $content['subcopy'] ?? null,
            'price_display' => $content['price_display'] ?? null,
            'was_display' => $content['was_display'] ?? null,
            'save_label' => $content['save_label'] ?? null,
            'trust' => $content['trust'] ?? null,
            'disclosure' => $content['disclosure'] ?? null,
            'accept_cta' => $content['accept_cta'] ?? null,
            'decline_cta' => $content['decline_cta'] ?? null,
            'accept_busy' => $content['accept_busy'] ?? null,
            'error_text' => $content['error_text'] ?? null,
            'image_url' => $content['product_image'] ?? null,
        ];
    }
}
