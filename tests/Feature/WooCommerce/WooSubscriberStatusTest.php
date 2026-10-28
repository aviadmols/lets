<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The checkout's subscriber-discount question: does this email belong to a
 * subscription that is paid and running, on THIS shop?
 *
 * It hands out money off an order, so every case that must NOT discount is
 * pinned beside the one that must: the wrong status, the wrong plan kind, the
 * wrong shop, and nobody at all.
 */
final class WooSubscriberStatusTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PATH = '/api/woocommerce/account/subscriber-status';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_guest_email_with_an_active_subscription_is_a_subscriber(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('status-active.example.com');
        $this->plan($shop, '17', 'Member@Example.com', PlanKind::RECURRING, PlanStatus::ACTIVE);

        // A guest's reference IS their email; case must not matter.
        $this->signedPost($key, $secret, ['customer_ref' => 'member@example.com', 'email' => 'member@example.com'])
            ->assertOk()->assertJson(['ok' => true, 'active' => true]);

        // A logged-in member is matched by their user id alone.
        $this->signedPost($key, $secret, ['customer_ref' => '17', 'email' => ''])
            ->assertOk()->assertJson(['active' => true]);
    }

    public function test_a_paid_subscription_waiting_for_activation_counts(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('status-activation.example.com');
        $this->plan($shop, '18', 'waiting@example.com', PlanKind::RECURRING, PlanStatus::AWAITING_ACTIVATION);

        $this->signedPost($key, $secret, ['customer_ref' => 'waiting@example.com', 'email' => 'waiting@example.com'])
            ->assertOk()->assertJson(['active' => true]);
    }

    public function test_paused_dunning_failed_and_cancelled_subscriptions_do_not_count(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('status-lapsed.example.com');

        foreach ([PlanStatus::PAUSED, PlanStatus::AWAITING_PAYMENT, PlanStatus::FAILED, PlanStatus::CANCELLED] as $i => $status) {
            $email = 'lapsed'.$i.'@example.com';
            $this->plan($shop, 'ref-'.$i, $email, PlanKind::RECURRING, $status);

            $this->signedPost($key, $secret, ['customer_ref' => $email, 'email' => $email])
                ->assertOk()->assertJson(['active' => false]);
        }
    }

    public function test_an_installment_plan_is_not_a_subscription(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('status-installments.example.com');
        $this->plan($shop, '19', 'buyer@example.com', PlanKind::INSTALLMENTS, PlanStatus::ACTIVE);

        $this->signedPost($key, $secret, ['customer_ref' => 'buyer@example.com', 'email' => 'buyer@example.com'])
            ->assertOk()->assertJson(['active' => false]);
    }

    public function test_a_subscriber_of_another_shop_gets_nothing_here(): void
    {
        [$other] = $this->connectedShop('status-other.example.com');
        $this->plan($other, '20', 'elsewhere@example.com', PlanKind::RECURRING, PlanStatus::ACTIVE);

        [, $key, $secret] = $this->connectedShop('status-mine.example.com');

        $this->signedPost($key, $secret, ['customer_ref' => 'elsewhere@example.com', 'email' => 'elsewhere@example.com'])
            ->assertOk()->assertJson(['active' => false]);
    }

    public function test_nobody_is_never_a_subscriber_and_an_unsigned_call_is_refused(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('status-nobody.example.com');
        $this->plan($shop, '21', 'someone@example.com', PlanKind::RECURRING, PlanStatus::ACTIVE);

        $this->signedPost($key, $secret, ['customer_ref' => '', 'email' => ''])
            ->assertOk()->assertJson(['active' => false]);

        $this->postJson(self::PATH, ['customer_ref' => 'someone@example.com'])->assertStatus(401);
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$result['shop']->fresh(), (string) $data['k'], (string) $data['s']];
    }

    private function plan(Shop $shop, string $ref, string $email, PlanKind $kind, PlanStatus $status): void
    {
        Tenant::run($shop, function () use ($shop, $ref, $email, $kind, $status): void {
            (new InstallmentPlan)->forceFill([
                'shop_id' => $shop->getKey(),
                'public_id' => 'PLN-'.$ref.'-'.uniqid(),
                'external_customer_id' => $ref,
                'customer_email' => $email,
                'plan_kind' => $kind->value,
                'status' => $status->value,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 89,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'next_charge_at' => now()->addDays(10)->startOfDay(),
            ])->save();
        });
    }

    /** @param array<string, mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.self::PATH.$json, $apiSecret, true));

        return $this->call('POST', self::PATH, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
