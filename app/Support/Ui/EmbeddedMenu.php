<?php

namespace App\Support\Ui;

use App\Filament\Pages\Analytics;
use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Filament\Pages\FlowBuilder;
use App\Filament\Pages\GiftOrders;
use App\Filament\Pages\HomeDashboard;
use App\Filament\Pages\ImportSubscriptions;
use App\Filament\Pages\LoyaltyMembers;
use App\Filament\Pages\ManageBillingSettings;
use App\Filament\Pages\ManageCustomerArea;
use App\Filament\Pages\ManageInvoicing;
use App\Filament\Pages\ManageLoyalty;
use App\Filament\Pages\ManageMailSettings;
use App\Filament\Pages\ManagePayPlusConnection;
use App\Filament\Pages\ManageUpsellAppearance;
use App\Filament\Pages\ObservabilityDashboard;
use App\Filament\Pages\PostPurchaseOffers;
use App\Filament\Pages\ProductDetail;
use App\Filament\Pages\StorefrontElements;
use App\Filament\Resources\IssuedDocumentResource;
use App\Filament\Resources\PaymentLedgerResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShopResource;
use App\Filament\Resources\SubscriptionContractResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\TeamMemberResource;

/**
 * WHICH AREAS a shop may see when the admin is rendered inside wp-admin — the
 * single list that both the platform owner's "Embedded menu" action and the
 * navigation filter read, so a tick-box and a 403 can never disagree.
 *
 * Three rules, in this order:
 *   1. ALWAYS_HIDDEN wins over everything. Credentials (PayPlus) and logins
 *      (Team) are not managed from inside somebody else's admin, and the
 *      platform's own Shops list is never reachable from a merchant's iframe.
 *   2. HOME is always allowed — an embed that lands on a 403 is a broken menu
 *      item in wp-admin, and there is nothing sensitive on the dashboard the
 *      merchant does not already own.
 *   3. Everything else consults the shop's allow-list. A NULL list means "no
 *      restriction", and a screen that is not in SCREEN_AREAS is ALLOWED —
 *      failing OPEN here is deliberate: a newly added screen must not silently
 *      vanish for every shop whose owner ticked boxes months ago. Hiding is a
 *      MENU feature, not a security boundary; tenancy is still the wall.
 *
 * Resource sub-pages (…\SubscriptionResource\Pages\ViewSubscription) resolve
 * through their resource by namespace prefix, because Filament authorises a
 * resource page against its RESOURCE (CanAuthorizeResourceAccess).
 */
final class EmbeddedMenu
{
    // === CONSTANTS ===
    public const AREA_HOME = 'home';

    public const AREA_CUSTOMERS = 'customers';

    public const AREA_SUBSCRIPTIONS = 'subscriptions';

    public const AREA_LOYALTY = 'loyalty';

    public const AREA_GIFT_ORDERS = 'gift_orders';

    public const AREA_IMPORT = 'import';

    public const AREA_PRODUCTS = 'products';

    public const AREA_PAYMENTS = 'payments';

    public const AREA_DOCUMENTS = 'documents';

    public const AREA_UPSELL = 'upsell';

    public const AREA_STOREFRONT = 'storefront';

    public const AREA_ANALYTICS = 'analytics';

    public const AREA_OBSERVABILITY = 'observability';

    public const AREA_SETTINGS_BILLING = 'settings_billing';

    public const AREA_SETTINGS_INVOICING = 'settings_invoicing';

    public const AREA_SETTINGS_MAIL = 'settings_mail';

    public const AREA_SETTINGS_CUSTOMER_AREA = 'settings_customer_area';

    public const AREA_SETTINGS_UPSELL = 'settings_upsell_appearance';

    /**
     * area key => translation key. The ORDER is the order the owner's checkbox
     * list renders in — sidebar order, not alphabetical.
     *
     * @var array<string, string>
     */
    public const AREAS = [
        self::AREA_HOME => 'embedded.areas.home',
        self::AREA_CUSTOMERS => 'embedded.areas.customers',
        self::AREA_SUBSCRIPTIONS => 'embedded.areas.subscriptions',
        self::AREA_LOYALTY => 'embedded.areas.loyalty',
        self::AREA_GIFT_ORDERS => 'embedded.areas.gift_orders',
        self::AREA_IMPORT => 'embedded.areas.import',
        self::AREA_PRODUCTS => 'embedded.areas.products',
        self::AREA_PAYMENTS => 'embedded.areas.payments',
        self::AREA_DOCUMENTS => 'embedded.areas.documents',
        self::AREA_UPSELL => 'embedded.areas.upsell',
        self::AREA_STOREFRONT => 'embedded.areas.storefront',
        self::AREA_ANALYTICS => 'embedded.areas.analytics',
        self::AREA_OBSERVABILITY => 'embedded.areas.observability',
        self::AREA_SETTINGS_BILLING => 'embedded.areas.settings_billing',
        self::AREA_SETTINGS_INVOICING => 'embedded.areas.settings_invoicing',
        self::AREA_SETTINGS_MAIL => 'embedded.areas.settings_mail',
        self::AREA_SETTINGS_CUSTOMER_AREA => 'embedded.areas.settings_customer_area',
        self::AREA_SETTINGS_UPSELL => 'embedded.areas.settings_upsell_appearance',
    ];

    /**
     * screen class => area key. Detail pages share their parent's key (a merchant
     * allowed "customers" may open a customer).
     *
     * @var array<class-string, string>
     */
    public const SCREEN_AREAS = [
        HomeDashboard::class => self::AREA_HOME,

        Customers::class => self::AREA_CUSTOMERS,
        CustomerDetail::class => self::AREA_CUSTOMERS,

        SubscriptionResource::class => self::AREA_SUBSCRIPTIONS,
        SubscriptionContractResource::class => self::AREA_SUBSCRIPTIONS,

        ManageLoyalty::class => self::AREA_LOYALTY,
        LoyaltyMembers::class => self::AREA_LOYALTY,

        GiftOrders::class => self::AREA_GIFT_ORDERS,
        ImportSubscriptions::class => self::AREA_IMPORT,

        ProductResource::class => self::AREA_PRODUCTS,
        ProductDetail::class => self::AREA_PRODUCTS,

        PaymentLedgerResource::class => self::AREA_PAYMENTS,
        IssuedDocumentResource::class => self::AREA_DOCUMENTS,

        PostPurchaseOffers::class => self::AREA_UPSELL,
        FlowBuilder::class => self::AREA_UPSELL,

        StorefrontElements::class => self::AREA_STOREFRONT,
        Analytics::class => self::AREA_ANALYTICS,
        ObservabilityDashboard::class => self::AREA_OBSERVABILITY,

        ManageBillingSettings::class => self::AREA_SETTINGS_BILLING,
        ManageInvoicing::class => self::AREA_SETTINGS_INVOICING,
        ManageMailSettings::class => self::AREA_SETTINGS_MAIL,
        ManageCustomerArea::class => self::AREA_SETTINGS_CUSTOMER_AREA,
        ManageUpsellAppearance::class => self::AREA_SETTINGS_UPSELL,
    ];

    /**
     * Never available inside wp-admin, whatever the allow-list says. Gateway
     * credentials and admin logins belong in the full LETS admin; the platform's
     * Shops list belongs to the platform.
     *
     * @var array<int, class-string>
     */
    public const ALWAYS_HIDDEN = [
        ManagePayPlusConnection::class,
        TeamMemberResource::class,
        ShopResource::class,
    ];

    /** area key => translation key (the single list both sides read). */
    public static function areas(): array
    {
        return self::AREAS;
    }

    /** Every area key, in sidebar order. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::AREAS);
    }

    /** key => translated label, for the platform owner's checkbox list. */
    public static function options(): array
    {
        return array_map(static fn (string $key): string => __($key), self::AREAS);
    }

    /** The area a screen belongs to, resolving resource sub-pages to their resource. */
    public static function areaFor(string $screenClass): ?string
    {
        if (isset(self::SCREEN_AREAS[$screenClass])) {
            return self::SCREEN_AREAS[$screenClass];
        }

        foreach (self::SCREEN_AREAS as $class => $area) {
            if (str_starts_with($screenClass, $class.'\\')) {
                return $area;
            }
        }

        return null;
    }

    /** Is this screen one of the never-embedded ones (including its sub-pages)? */
    public static function isAlwaysHidden(string $screenClass): bool
    {
        foreach (self::ALWAYS_HIDDEN as $class) {
            if ($screenClass === $class || str_starts_with($screenClass, $class.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The decision. $allowed is the shop's stored list, or null for "everything".
     *
     * @param  list<string>|null  $allowed
     */
    public static function allows(?array $allowed, string $screenClass): bool
    {
        if (self::isAlwaysHidden($screenClass)) {
            return false;
        }

        $area = self::areaFor($screenClass);

        // Unmapped screen → allowed (fail open for the MENU; tenancy is the wall).
        if ($area === null || $area === self::AREA_HOME || $allowed === null) {
            return true;
        }

        return in_array($area, $allowed, true);
    }

    /**
     * Clean an incoming/stored list down to known keys. Returns NULL when the
     * result covers every area — "everything is allowed" is stored as null so a
     * screen added next release stays visible for a shop whose owner ticked all.
     *
     * @return list<string>|null
     */
    public static function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $clean = [];
        foreach (self::keys() as $key) {
            if (in_array($key, $value, true)) {
                $clean[] = $key;
            }
        }

        return count($clean) === count(self::AREAS) ? null : $clean;
    }
}
