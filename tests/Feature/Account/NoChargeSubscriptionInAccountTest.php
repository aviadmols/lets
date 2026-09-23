<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Domain\Installments\ManualSubscriptionService;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A comped member on a WORDPRESS store sees their subscription where every other
 * subscriber sees theirs — the account area the plugin serves.
 *
 * The admin can now type such a member in by hand, and a subscription nobody can
 * find is not a membership. Two things had to hold for it to appear, and neither
 * is obvious from the creation form:
 *
 *   - the account area matches a visitor by the reference the platform vouched
 *     for, and a hand-typed plan has no reference of its own — it inherits one
 *     from the member's existing plans (ManualSubscriptionService::identityFor),
 *     or is found by the email it was typed with;
 *   - nothing in that area filters on a charge date or a saved card, so a plan
 *     that will never bill still lists.
 *
 * And one thing had to NOT hold: they must not be asked for a card, because
 * their subscription is not waiting on one.
 */
final class NoChargeSubscriptionInAccountTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'comped-wp.example.com',
            'name' => 'Comped WP',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** A member with nothing else: found by the address they were typed with. */
    public function test_a_comped_member_sees_their_subscription_in_the_account_area(): void
    {
        app(ManualSubscriptionService::class)->create($this->shop, [
            'customer_name' => 'Dana Levi',
            'customer_email' => 'dana@example.com',
            'item_title' => 'Coffee club',
            'amount' => 0,
            'frequency' => BillingFrequency::MONTHLY->value,
            'status' => PlanStatus::ACTIVE->value,
        ]);

        $payload = $this->present('dana@example.com');

        $this->assertTrue($payload['identified']);
        $this->assertCount(1, $payload['subscriptions']);
        $this->assertNull(
            $payload['subscriptions'][0]['next_charge_at'],
            'a subscription that never bills names no next charge',
        );
    }

    /**
     * Comping somebody who ALREADY subscribes on this WooCommerce store: the new
     * plan inherits their WordPress user id, so both land on one account — and
     * one customer, everywhere else in the admin.
     */
    public function test_it_joins_the_account_of_a_member_who_already_subscribes(): void
    {
        $paying = new InstallmentPlan;
        $paying->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'customer_email' => 'dana@example.com',
            'external_customer_id' => '4471',   // their WordPress user id
            'installment_amount' => 90,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'next_charge_at' => now()->addWeek(),
            'public_id' => 'paying-plan',
        ]);
        $paying->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        $comped = app(ManualSubscriptionService::class)->create($this->shop, [
            'customer_name' => 'Dana Levi',
            'customer_email' => 'Dana@Example.com',
            'item_title' => 'Staff membership',
            'amount' => 0,
            'status' => PlanStatus::ACTIVE->value,
        ]);

        $this->assertSame('4471', $comped->external_customer_id);

        // The visitor arrives as WordPress knows them — by user id, not by email.
        $payload = $this->present('4471', 'dana@example.com');

        $this->assertCount(2, $payload['subscriptions']);
    }

    /**
     * They are never asked to keep a card current for a subscription nobody
     * bills — while the member beside them, who does pay, still is.
     *
     * Both halves are asserted on purpose: `actions` is a LIST of the verbs that
     * are ON, so asking whether one is absent proves nothing on its own — a
     * renamed key would answer "absent" just as cheerfully.
     */
    public function test_it_offers_the_member_no_card_update(): void
    {
        $this->shop->payplus_credentials = [
            'api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't', 'payment_page_uid' => 'p',
        ];
        $this->shop->callback_token = 'cb-token';
        $this->shop->save();

        $paying = new InstallmentPlan;
        $paying->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'customer_email' => 'roni@example.com',
            'installment_amount' => 90,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'next_charge_at' => now()->addWeek(),
            'public_id' => 'paying-plan',
        ]);
        $paying->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        app(ManualSubscriptionService::class)->create($this->shop, [
            'customer_name' => 'Dana Levi',
            'customer_email' => 'dana@example.com',
            'item_title' => 'Coffee club',
            'amount' => 0,
            'status' => PlanStatus::ACTIVE->value,
        ]);

        $comped = $this->present('dana@example.com')['subscriptions'][0];
        $billed = $this->present('roni@example.com')['subscriptions'][0];

        $this->assertContains(
            CustomerSubscriptionActions::ACTION_UPDATE_CARD,
            $billed['actions'],
            'the paying member is still offered it',
        );
        $this->assertNotContains(CustomerSubscriptionActions::ACTION_UPDATE_CARD, $comped['actions']);
    }

    /** @return array<string, mixed> */
    private function present(string $ref, ?string $email = null): array
    {
        return app(AccountPresenter::class)->present(AccountVisitor::make(
            shop: Tenant::current(),
            customerRef: $ref,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $email ?? $ref,
        ));
    }
}
