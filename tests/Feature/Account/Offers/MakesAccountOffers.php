<?php

namespace Tests\Feature\Account\Offers;

use App\Models\AccountOffer;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Support\Tenant;

/**
 * Fixtures for the account-offer suite, shaped like the shop this feature was
 * built for: a WooCommerce store of IMPORTED members.
 *
 * The imported shape is not incidental. Those members are known by the UUID
 * their previous system gave them (shopify_customer_id), carry customer_id NULL,
 * and hold their card on the plan's payment_method_id — which is exactly the
 * shape that breaks any code that compares customer_id to a reference string, or
 * that builds a new plan from the visitor's own WordPress id. Every fixture here
 * is that shape on purpose.
 */
trait MakesAccountOffers
{
    // === CONSTANTS ===
    /** The pilot shop's real product codes — 2666/2675 yearly, offered monthly. */
    protected const PRODUCT_YEARLY = '2666';

    protected const PRODUCT_MONTHLY = '2675';

    /** An imported member's reference: a UUID, never a digit string. */
    protected const MEMBER_REF = 'b3f1c0de-0000-4000-8000-000000000001';

    protected const MEMBER_EMAIL = 'member@example.com';

    protected function makeShop(string $domain = 'offers.example.com'): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Offers Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }

    /** A catalog product with one priced variant. Tenant must be bound. */
    protected function makeProduct(string $externalId, string $title, float $price): Product
    {
        $product = Product::create([
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => $title,
            'image_url' => 'https://cdn.example.com/'.$externalId.'.png',
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::create([
            'product_id' => $product->getKey(),
            'external_variant_id' => $externalId,
            'title' => '',
            'price' => $price,
            'position' => 0,
        ]);

        return $product->fresh();
    }

    /** A subscription template on the PayPlus rail. Tenant must be bound. */
    protected function makeTemplate(
        Product $product,
        BillingFrequency $frequency = BillingFrequency::MONTHLY,
        int $intervalCount = 1,
        PlanTemplateStatus $status = PlanTemplateStatus::ACTIVE,
        string $rail = ProductSubscriptionPlan::RAIL_PAYPLUS,
        string $discountType = ProductSubscriptionPlan::DISCOUNT_NONE,
        float $discountValue = 0,
    ): ProductSubscriptionPlan {
        $template = new ProductSubscriptionPlan([
            'product_id' => $product->getKey(),
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
            'plan_kind' => PlanKind::RECURRING->value,
            'plan_name' => $product->title.' monthly',
            'billing_frequency' => $frequency->value,
            'interval_count' => $intervalCount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'pricing_mode' => ProductSubscriptionPlan::PRICING_PLAN_PRICE,
            'billing_rail' => $rail,
        ]);

        $template->forceFill(['shop_id' => Tenant::id(), 'status' => $status->value])->save();

        return $template->fresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function makeOffer(ProductSubscriptionPlan $template, array $attributes = []): AccountOffer
    {
        $offer = new AccountOffer(array_merge([
            'name' => 'Switch to monthly',
            'product_subscription_plan_id' => $template->getKey(),
            'mode' => AccountOffer::MODE_REPLACE,
            'replace_timing' => AccountOffer::TIMING_IMMEDIATE,
            'placement' => AccountOffer::PLACEMENT_PLAN,
            'status' => AccountOffer::STATUS_ACTIVE,
            'priority' => 0,
        ], $attributes));

        $offer->forceFill(['shop_id' => Tenant::id()])->save();

        return $offer->fresh();
    }

    /**
     * An imported member's live yearly subscription, with a saved card and a
     * transcribed consent row. Tenant must be bound.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSourcePlan(array $attributes = [], bool $withConsent = true, bool $withCard = true): InstallmentPlan
    {
        $method = $withCard
            ? InstallmentPaymentMethod::create([
                'shopify_customer_id' => self::MEMBER_REF,
                'payplus_card_token_uid' => 'tok-'.uniqid(),
                'payplus_customer_uid' => 'pcu-1',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ])
            : null;

        if ($withConsent) {
            CustomerConsent::create([
                'shopify_customer_id' => self::MEMBER_REF,
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'accepted_at' => now()->subYear(),
            ]);
        }

        $plan = new InstallmentPlan(array_merge([
            'public_id' => 'PLN-'.uniqid(),
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            // The imported shape: a UUID reference, and NO internal customer id.
            'customer_id' => null,
            'shopify_customer_id' => self::MEMBER_REF,
            'external_customer_id' => self::MEMBER_REF,
            'external_product_id' => self::PRODUCT_YEARLY,
            'customer_email' => self::MEMBER_EMAIL,
            'customer_name' => 'Dana Levi',
            'payment_method_id' => $method?->getKey(),
            'total_amount' => 480,
            'total_charged' => 480,
            'installment_amount' => 480,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::YEARLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(40)->startOfDay(),
        ], $attributes));

        $status = $attributes['status'] ?? PlanStatus::ACTIVE->value;
        $plan->forceFill(['shop_id' => Tenant::id(), 'status' => $status])->save();

        return $plan->fresh();
    }
}
