<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Domain\Portal\PortalSignedUrlService;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOW a customer cancels: the merchant's choice between the one-click verb and
 * "through support".
 *
 * The law under test is the double read: contact mode must remove the VERB (the
 * endpoint refuses even a hand-crafted request) while the PAGE still offers a
 * button — one that opens the merchant's contact card. And the master switch is
 * still the master: off means no button, no card, no verb, exactly as before.
 */
final class CancelPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The verb ===

    public function test_self_service_is_the_default_and_keeps_the_one_click_cancel(): void
    {
        $shop = $this->shop('cancel-self.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c1', 'one@example.com');

            $this->assertSame(
                MerchantBillingSettings::CANCEL_SELF_SERVICE,
                MerchantBillingSettings::current()->customerCancelMode(),
            );

            $available = app(CustomerSubscriptionActions::class)->availableFor($plan);
            $this->assertTrue($available[CustomerSubscriptionActions::ACTION_CANCEL]);

            $result = app(CustomerSubscriptionActions::class)->perform(
                $this->visitor($shop, 'c1', 'one@example.com'),
                CustomerSubscriptionActions::ACTION_CANCEL,
                (string) $plan->public_id,
            );

            $this->assertSame(CustomerSubscriptionActions::RESULT_OK, $result['result']);
            $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
        });
    }

    public function test_contact_mode_removes_the_verb_and_refuses_a_direct_post(): void
    {
        $shop = $this->shop('cancel-contact.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c2', 'two@example.com');
            $this->contactMode();

            $available = app(CustomerSubscriptionActions::class)->availableFor($plan->fresh());
            $this->assertFalse($available[CustomerSubscriptionActions::ACTION_CANCEL], 'the verb is gone');

            $result = app(CustomerSubscriptionActions::class)->perform(
                $this->visitor($shop, 'c2', 'two@example.com'),
                CustomerSubscriptionActions::ACTION_CANCEL,
                (string) $plan->public_id,
            );

            $this->assertSame(CustomerSubscriptionActions::RESULT_NOT_ALLOWED, $result['result']);
            $this->assertSame(PlanStatus::ACTIVE, $plan->fresh()->status, 'nothing was cancelled');
        });
    }

    // === The payload ===

    public function test_contact_mode_puts_the_button_flag_and_the_card_in_the_payload(): void
    {
        $shop = $this->shop('cancel-payload.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'c3', 'three@example.com');
            $this->contactMode(email: 'help@shop.example.com', phone: '03-1234567', note: 'חייגו ונסדר הכל.');

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c3', 'three@example.com'));
            $sub = $model['subscriptions'][0];

            $this->assertNotContains('cancel', $sub['actions']);
            $this->assertTrue($sub['cancel_contact']);

            $this->assertSame('help@shop.example.com', $model['cancel_contact']['email']);
            $this->assertSame('03-1234567', $model['cancel_contact']['phone']);
            $this->assertSame('חייגו ונסדר הכל.', $model['cancel_contact']['note']);

            // The dialog's copy rides the bag like every other sentence.
            $this->assertArrayHasKey('cancel_contact_heading', $model['copy']);
            $this->assertArrayHasKey('close', $model['copy']);
        });
    }

    /** A merchant who typed nothing still gives the shopper a door. */
    public function test_the_contact_email_falls_back_to_the_support_address(): void
    {
        $shop = $this->shop('cancel-fallback.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'c4', 'four@example.com');
            $this->contactMode();

            $billing = MerchantBillingSettings::current();
            $billing->support_email = 'support@shop.example.com';
            $billing->save();

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c4', 'four@example.com'));

            $this->assertSame('support@shop.example.com', $model['cancel_contact']['email']);
        });
    }

    public function test_self_service_mode_ships_no_contact_card_at_all(): void
    {
        $shop = $this->shop('cancel-none.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'c5', 'five@example.com');

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c5', 'five@example.com'));

            $this->assertNull($model['cancel_contact']);
            $this->assertFalse($model['subscriptions'][0]['cancel_contact']);
            $this->assertContains('cancel', $model['subscriptions'][0]['actions']);
        });
    }

    public function test_the_master_switch_off_means_no_button_and_no_card(): void
    {
        $shop = $this->shop('cancel-off.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'c6', 'six@example.com');
            $this->contactMode();

            $billing = MerchantBillingSettings::current();
            $billing->allow_customer_cancel = false;
            $billing->save();

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c6', 'six@example.com'));

            $this->assertNull($model['cancel_contact']);
            $this->assertFalse($model['subscriptions'][0]['cancel_contact']);
            $this->assertNotContains('cancel', $model['subscriptions'][0]['actions']);
        });
    }

    /** A finished plan offers no cancel door of either kind. */
    public function test_a_terminal_plan_gets_no_contact_button(): void
    {
        $shop = $this->shop('cancel-terminal.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c7', 'seven@example.com');
            $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();
            $this->contactMode();

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c7', 'seven@example.com'));

            $this->assertFalse($model['subscriptions'][0]['cancel_contact']);
        });
    }

    // === The magic-link portal agrees ===

    public function test_the_portal_hides_its_cancel_form_in_contact_mode(): void
    {
        $shop = $this->shop('cancel-portal.example.com');

        [$showUrl] = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->plan($shop, 'c8', 'eight@example.com');
            $this->contactMode();

            return [app(PortalSignedUrlService::class)->showUrl($plan)];
        });

        $response = $this->get($showUrl);

        $response->assertOk();
        $response->assertDontSee(__('portal.action_cancel'));
    }

    // === The stats section toggle (part of the same personal-area pass) ===

    public function test_the_stats_section_ships_enabled_and_can_be_hidden(): void
    {
        $shop = $this->shop('stats-toggle.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'c9', 'nine@example.com');

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c9', 'nine@example.com'));
            $this->assertContains(MerchantPortalAppearance::SECTION_STATS, $model['sections'], 'on by default, even for a shop configured before the key existed');

            // The merchant turns it off.
            $appearance = MerchantPortalAppearance::current();
            $appearance->sections = array_map(
                static fn (string $key): array => ['key' => $key, 'enabled' => $key !== MerchantPortalAppearance::SECTION_STATS],
                MerchantPortalAppearance::SECTION_KEYS,
            );
            $appearance->save();

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c9', 'nine@example.com'));
            $this->assertNotContains(MerchantPortalAppearance::SECTION_STATS, $model['sections']);
        });
    }

    // === helpers ===

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function contactMode(?string $email = null, ?string $phone = null, ?string $note = null): void
    {
        $settings = MerchantBillingSettings::current();
        $settings->customer_cancel_mode = MerchantBillingSettings::CANCEL_CONTACT;
        $settings->cancel_contact_email = $email;
        $settings->cancel_contact_phone = $phone;
        $settings->cancel_contact_note = $note;
        $settings->save();
    }

    private function visitor(Shop $shop, string $ref, string $email): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: $ref,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $email,
        );
    }

    private function plan(Shop $shop, string $ref, string $email): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.$ref.'-'.uniqid(),
            'external_customer_id' => $ref,
            'customer_email' => $email,
            'customer_name' => 'Test Customer',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 89,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ])->save();

        return $plan;
    }
}
