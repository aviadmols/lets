<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;

/**
 * Fixtures for the email-campaign suite.
 *
 * The shapes here are the ones that break things: an IMPORTED member known by a
 * UUID with customer_id NULL (the shape that aborts a Postgres query comparing a
 * bigint to a reference), a contract whose product ids live inside a JSON array
 * as gids, and a person who holds two subscriptions and must still receive one
 * email.
 */
trait MakesEmailCampaigns
{
    // === CONSTANTS ===
    protected const PRODUCT_YEARLY = '2666';

    protected const PRODUCT_MONTHLY = '2675';

    /** An imported member's reference: a UUID, never a digit string. */
    protected const MEMBER_REF = 'b3f1c0de-0000-4000-8000-000000000001';

    protected const MEMBER_EMAIL = 'member@example.com';

    protected function makeShop(
        string $domain = 'campaigns.example.com',
        string $platform = Shop::PLATFORM_WOOCOMMERCE,
    ): Shop {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Campaign Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => $platform,
        ]);

        if ($platform === Shop::PLATFORM_WOOCOMMERCE) {
            $shop->woocommerce_credentials = [
                'base_url' => 'https://'.$domain,
                'consumer_key' => 'ck',
                'consumer_secret' => 'cs',
            ];
            $shop->save();
        }

        return $shop->fresh();
    }

    /** A PayPlus-rail plan. Tenant must be bound. */
    protected function makePlan(
        Shop $shop,
        string $email = self::MEMBER_EMAIL,
        PlanKind $kind = PlanKind::RECURRING,
        PlanStatus $status = PlanStatus::ACTIVE,
        BillingFrequency $frequency = BillingFrequency::MONTHLY,
        ?string $productId = self::PRODUCT_YEARLY,
        ?string $name = 'Dana Subscriber',
        ?string $ref = self::MEMBER_REF,
    ): InstallmentPlan {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'public_id' => 'PLN-'.uniqid('', true),
            'plan_kind' => $kind->value,
            'status' => $status->value,
            // An imported member: the reference is a UUID and customer_id is NULL.
            'shopify_customer_id' => $ref,
            'customer_id' => null,
            'customer_name' => $name,
            'customer_email' => $email,
            'external_product_id' => $productId,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'billing_frequency' => $frequency->value,
            'interval_count' => 1,
        ])->save();

        return $plan->fresh();
    }

    /** A mirrored Shopify-Payments contract. Tenant must be bound. */
    protected function makeContract(
        Shop $shop,
        string $email = 'contract@example.com',
        string $status = SubscriptionContract::STATUS_ACTIVE,
        string $interval = 'MONTH',
        ?string $productId = self::PRODUCT_MONTHLY,
        ?string $name = 'Noa Contract',
    ): SubscriptionContract {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.random_int(1000, 999999),
            'shopify_customer_gid' => 'gid://shopify/Customer/5501',
            'status' => $status,
            'interval' => $interval,
            'interval_count' => 1,
            'amount' => 99,
            'currency' => 'ILS',
            'customer_email' => $email,
            'customer_name' => $name,
            // Mirrored lines carry the product as a GID; the filter holds bare ids.
            'lines' => $productId === null ? [] : [['product_id' => 'gid://shopify/Product/'.$productId]],
        ])->save();

        return $contract->fresh();
    }

    /** A loyalty-club membership. Tenant must be bound. */
    protected function makeMember(
        Shop $shop,
        string $email = 'member-only@example.com',
        ?int $tierId = null,
        ?string $name = 'Yael Member',
    ): LoyaltyAccount {
        $account = new LoyaltyAccount;
        $account->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => 'ref-'.uniqid('', true),
            'customer_email' => $email,
            'customer_name' => $name,
            'tier_id' => $tierId,
            'joined_at' => now(),
        ])->save();

        return $account->fresh();
    }

    /**
     * A campaign. `audience` is the raw bag; `status` is forced because the
     * column is guarded everywhere else.
     *
     * @param  array<string, mixed>  $audience
     */
    protected function makeCampaign(
        Shop $shop,
        array $audience = [],
        string $status = EmailCampaign::STATUS_DRAFT,
        ?string $body = null,
        bool $isMarketing = true,
        ?string $subject = 'Hello {customer_name}',
    ): EmailCampaign {
        $campaign = new EmailCampaign;
        $campaign->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'name' => 'Spring news',
            'subject' => $subject,
            'body_html' => $body ?? '<p>Hi {customer_name}</p><a href="{account_login_url}">Enter</a>'
                .'<a href="{unsubscribe_url}">Out</a>',
            'editor_mode' => EmailCampaign::EDITOR_VISUAL,
            'audience' => $audience,
            'status' => $status,
            'is_marketing' => $isMarketing,
        ])->save();

        return $campaign->fresh();
    }

    /** Run a callback with the shop bound — every fixture above needs one. */
    protected function inShop(Shop $shop, callable $callback): mixed
    {
        return Tenant::run($shop, $callback);
    }
}
