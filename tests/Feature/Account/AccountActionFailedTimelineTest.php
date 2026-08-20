<?php

namespace Tests\Feature\Account;

use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * A refused customer-area click must land on the merchant's Timeline — the main
 * activity feed is where a merchant learns a customer hit a wall, instead of the
 * customer being the only witness ("הפעולה לא הצליחה" with nobody on the other
 * side ever knowing). A SUCCESSFUL click must not: the feed's value is that a
 * failure entry means friction, and routine traffic would bury it.
 */
final class AccountActionFailedTimelineTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PATH_CANCEL = '/api/woocommerce/account/subscriptions/cancel';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_refused_cancel_is_pinned_to_the_merchant_timeline(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('action-fail.example.com');
        $plan = Tenant::run($shop, fn () => $this->makeSourcePlan(['min_cycles_before_exit' => 3]));

        $this->signedPost($key, $secret, self::PATH_CANCEL, [
            'customer_ref' => self::MEMBER_REF,
            'email' => self::MEMBER_EMAIL,
            'subscription' => (string) $plan->public_id,
        ])->assertOk()->assertJsonPath('ok', false);

        $event = ActivityEvent::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->getKey())
            ->where('kind', Timeline::KIND_ACCOUNT_ACTION_FAILED)
            ->first();

        $this->assertNotNull($event, 'the refusal never reached the merchant feed');
        $this->assertSame('cancel', $event->details['action']);
        $this->assertSame('not_allowed', $event->details['result']);
        $this->assertSame((string) $plan->public_id, $event->details['subscription']);
        // Attributed to the plan (so it shows on the plan's own timeline too)
        // and to the customer (so the feed names who hit the wall).
        $this->assertSame($plan->getKey(), $event->plan_id);
        $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, $event->actor);
    }

    public function test_a_successful_cancel_writes_no_failure_event(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('action-ok.example.com');
        $plan = Tenant::run($shop, fn () => $this->makeSourcePlan());

        $this->signedPost($key, $secret, self::PATH_CANCEL, [
            'customer_ref' => self::MEMBER_REF,
            'email' => self::MEMBER_EMAIL,
            'subscription' => (string) $plan->public_id,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(0, ActivityEvent::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->getKey())
            ->where('kind', Timeline::KIND_ACCOUNT_ACTION_FAILED)
            ->count());
    }

    public function test_an_unknown_subscription_is_recorded_without_confirming_anything(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('action-miss.example.com');
        Tenant::run($shop, fn () => $this->makeSourcePlan());

        $this->signedPost($key, $secret, self::PATH_CANCEL, [
            'customer_ref' => self::MEMBER_REF,
            'email' => self::MEMBER_EMAIL,
            'subscription' => 'PLN-not-yours',
        ])->assertStatus(404);

        $event = ActivityEvent::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->getKey())
            ->where('kind', Timeline::KIND_ACCOUNT_ACTION_FAILED)
            ->first();

        // The merchant sees the miss; the event confirms nothing about the id —
        // no plan attached, the raw string as typed.
        $this->assertNotNull($event);
        $this->assertSame('invalid', $event->details['result']);
        $this->assertNull($event->plan_id);
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    /** @param array<string, mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
