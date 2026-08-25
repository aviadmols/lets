<?php

namespace App\Domain\Storefront;

use App\Models\MerchantLoyaltySettings;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;

/**
 * What the merchant can put on their storefront, and how to put it there.
 *
 * One place answers both halves, per platform: WHICH elements this app offers
 * (the subscriptions widget, the deposit/installments widget, the loyalty page)
 * and WHAT the merchant must do to make each appear. The two platforms differ in
 * kind, not in degree — on Shopify the merchant adds an app block in the theme
 * editor (so we hand them a deep link straight to it); on WooCommerce the plugin
 * renders the widgets itself, so the honest instruction is "nothing, here is what
 * decides whether it shows".
 *
 * RENDERS NOTHING: this returns view-models; the Blade draws them.
 */
final class StorefrontElementsPresenter
{
    // === CONSTANTS ===
    /** Element keys (also the i18n sub-key under storefront_admin.element.*). */
    public const ELEMENT_SUBSCRIPTIONS = 'subscriptions';
    public const ELEMENT_DEPOSIT = 'deposit';
    public const ELEMENT_LOYALTY = 'loyalty';
    public const ELEMENT_ACCOUNT = 'account';

    /** Which admin button a deep link opens — they go to different places. */
    public const LINK_THEME = 'theme';   // the theme editor, with our block ready
    public const LINK_MENUS = 'menus';   // Online Store → Navigation

    /**
     * Theme app-block handles — the block FILENAME without extension, which is
     * what Shopify's `activateAppId` deep link addresses.
     * @see extensions/lets-installments/blocks/
     */
    public const BLOCK_SUBSCRIPTIONS = 'subscription_options';
    public const BLOCK_DEPOSIT = 'installments_button';

    /**
     * The loyalty doorway lives in its OWN theme extension
     * (extensions/lets-loyalty-link/blocks/loyalty-link.liquid), so it has its
     * own uuid and `themeEditorLink()` (which carries the installments
     * extension's uuid) cannot deep-link to it. The handle is still named here
     * because the placement copy points merchants at the block by name.
     */
    public const BLOCK_LOYALTY_LINK = 'loyalty-link';

    /** Status vocabulary: what the merchant must know at a glance. */
    public const STATUS_READY = 'ready';           // live / will render
    public const STATUS_NEEDS_SETUP = 'needs_setup'; // one more step needed
    public const STATUS_AUTO = 'auto';             // renders itself, nothing to do

    /** The theme-editor template each block belongs on. */
    private const TEMPLATE_PRODUCT = 'product';

    /**
     * @return list<array{
     *   key: string, status: string, deep_link: ?string, deep_link_kind: ?string,
     *   block: ?string, steps: int, snippet: ?string, shortcode: ?string, page_url: ?string
     * }>
     */
    public function elements(?Shop $shop): array
    {
        if (! $shop instanceof Shop) {
            return [];
        }

        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? $this->wooElements($shop)
            : $this->shopifyElements($shop);
    }

    // === Shopify ===

    /** @return list<array<string, mixed>> */
    private function shopifyElements(Shop $shop): array
    {
        $elements = [
            [
                'key' => self::ELEMENT_SUBSCRIPTIONS,
                // A published selling plan is what makes the block show real
                // options — without one it renders a one-time card and the
                // merchant reasonably reads that as the widget being broken.
                'status' => $this->hasPublishedPlan() ? self::STATUS_READY : self::STATUS_NEEDS_SETUP,
                'deep_link' => $this->themeEditorLink($shop, self::BLOCK_SUBSCRIPTIONS),
                'deep_link_kind' => self::LINK_THEME,
                'block' => self::BLOCK_SUBSCRIPTIONS,
                'steps' => 3,
                'snippet' => null,
                'shortcode' => null,
                'page_url' => null,
            ],
            [
                'key' => self::ELEMENT_DEPOSIT,
                'status' => self::STATUS_READY,
                'deep_link' => $this->themeEditorLink($shop, self::BLOCK_DEPOSIT),
                'deep_link_kind' => self::LINK_THEME,
                'block' => self::BLOCK_DEPOSIT,
                'steps' => 3,
                // The pasteable alternative for themes without app-block support.
                'snippet' => "{% render 'lets-installments-button' %}",
                'shortcode' => null,
                'page_url' => null,
            ],
        ];

        if ($this->loyaltyEnabled()) {
            $elements[] = [
                'key' => self::ELEMENT_LOYALTY,
                'status' => self::STATUS_READY,
                // The App Proxy already serves the page on the merchant's OWN
                // domain — nothing to install. The EASY path is the
                // "Loyalty club link" app block (step 1 of the copy names it);
                // the deep link stays Online Store → Navigation for the manual
                // menu-link alternative the remaining steps describe, because
                // the block lives in its own extension whose uuid we cannot
                // address from here (see BLOCK_LOYALTY_LINK).
                'deep_link' => $this->adminLink($shop, 'menus'),
                'deep_link_kind' => self::LINK_MENUS,
                'block' => self::BLOCK_LOYALTY_LINK,
                'steps' => 5,
                'snippet' => null,
                'shortcode' => null,
                'page_url' => $this->proxyPageUrl($shop, 'loyalty'),
            ];
        }

        // The personal area's Shopify storefront surface is not built yet; the
        // merchant's own account extension covers subscriptions there. Listing it
        // as "coming" would be a promise, so it appears only where it works.

        return $elements;
    }

    /**
     * A deep link that opens the theme editor with OUR app block ready to add.
     * Null when the shop has no readable domain or the extension uuid is not
     * configured — a half-built link would drop the merchant on an error page.
     */
    private function themeEditorLink(Shop $shop, string $blockHandle): ?string
    {
        $domain = trim((string) ($shop->shopify_domain ?? ''));
        $uuid = trim((string) config('shopify.theme_extension_uuid', ''));

        if ($domain === '' || $uuid === '') {
            return null;
        }

        return sprintf(
            'https://%s/admin/themes/current/editor?context=apps&template=%s&activateAppId=%s/%s',
            $domain,
            self::TEMPLATE_PRODUCT,
            $uuid,
            $blockHandle,
        );
    }

    /**
     * A customer-facing page served through the Shopify App Proxy.
     *
     * The URL is on the MERCHANT'S OWN domain — that is the whole point of the
     * proxy, and the thing merchants most often miss. There is no theme edit, no
     * app block and nothing to install: the page is already live, and all that is
     * missing is something linking to it.
     */
    private function proxyPageUrl(Shop $shop, string $path): ?string
    {
        $domain = trim((string) ($shop->shopify_domain ?? ''));
        $prefix = trim((string) config('shopify.app_proxy_prefix', 'apps'));
        $subpath = trim((string) config('shopify.app_proxy_subpath', 'payplus'));

        return $domain !== '' ? sprintf('https://%s/%s/%s/%s', $domain, $prefix, $subpath, $path) : null;
    }

    /** A deep link into a section of the merchant's own Shopify admin. */
    private function adminLink(Shop $shop, string $section): ?string
    {
        $domain = trim((string) ($shop->shopify_domain ?? ''));

        return $domain !== '' ? sprintf('https://%s/admin/%s', $domain, $section) : null;
    }

    // === WooCommerce ===

    /** @return list<array<string, mixed>> */
    private function wooElements(Shop $shop): array
    {
        $elements = [
            [
                'key' => self::ELEMENT_SUBSCRIPTIONS,
                // The plugin asks the SaaS per product; an ACTIVE template is
                // what flips the widget on. Say so rather than "auto".
                'status' => $this->hasPublishedPlan() ? self::STATUS_AUTO : self::STATUS_NEEDS_SETUP,
                'deep_link' => null,
                'deep_link_kind' => null,
                'block' => null,
                'steps' => 2,
                'snippet' => null,
                'shortcode' => null,
                'page_url' => null,
            ],
            [
                'key' => self::ELEMENT_DEPOSIT,
                'status' => self::STATUS_AUTO,
                'deep_link' => null,
                'deep_link_kind' => null,
                'block' => null,
                'steps' => 2,
                'snippet' => null,
                'shortcode' => null,
                'page_url' => null,
            ],
            [
                // The personal area needs no placement at all: the plugin adds
                // its own My Account tab. What the merchant DOES need to know is
                // where to configure it, so the steps point at that screen.
                'key' => self::ELEMENT_ACCOUNT,
                'status' => self::STATUS_AUTO,
                'deep_link' => null,
                'deep_link_kind' => null,
                'block' => null,
                'steps' => 3,
                'snippet' => null,
                'shortcode' => null,
                'page_url' => null,
            ],
        ];

        if ($this->loyaltyEnabled()) {
            $elements[] = [
                'key' => self::ELEMENT_LOYALTY,
                'status' => self::STATUS_READY,
                'deep_link' => null,
                'deep_link_kind' => null,
                'block' => null,
                'steps' => 2,
                'snippet' => null,
                // WooCommerce has no app blocks — a shortcode on any page is the
                // equivalent "put it where you want it" handle.
                'shortcode' => '[lets_loyalty]',
                'page_url' => null,
            ];
        }

        return $elements;
    }

    // === Shared status probes (tenant-scoped by the models' global scope) ===

    /** Does this shop have at least one ACTIVE subscription template? */
    private function hasPublishedPlan(): bool
    {
        return ProductSubscriptionPlan::query()
            ->where('plan_type', ProductSubscriptionPlan::TYPE_SUBSCRIPTION)
            ->where('status', PlanTemplateStatus::ACTIVE->value)
            ->exists();
    }

    private function loyaltyEnabled(): bool
    {
        return (bool) MerchantLoyaltySettings::current()->enabled;
    }
}
