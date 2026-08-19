<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Models\AccountOffer;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The HTTP surface of an offer acceptance, over the plugin's HMAC.
 *
 * The tests worth having on this endpoint are about what it REFUSES and what it
 * hands back. The browser never says who it is — the plugin's server asserts the
 * logged-in user over the shared secret — so the interesting failures are a
 * subscription that is not the caller's (a 404 like every other miss, because a
 * 403 would confirm it exists) and a body that names a price of its own.
 */
final class AccountOfferEndpointTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PATH = '/api/woocommerce/account/subscriptions/accept_offer';

    public int $payplusCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->payplusCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private AccountOfferEndpointTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->payplusCalls;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n]],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_route_admits_accept_offer(): void
    {
        [, $key, $secret] = $this->connectedShop('offer-route.example.com');

        // Not a 404-from-routing: the verb is on the whitelist. It misses on the
        // subscription instead, which is the ownership wall doing its job.
        $this->signedPost($key, $secret, self::PATH, [])->assertStatus(404);
        $this->signedPost($key, $secret, '/api/woocommerce/account/subscriptions/accept_anything', [])
            ->assertStatus(404);
    }

    public function test_an_unsigned_call_is_rejected(): void
    {
        $this->postJson(self::PATH, [])->assertStatus(401);
    }

    public function test_a_round_trip_switches_the_subscription_and_returns_the_redrawn_area(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('offer-accept.example.com');
        [$offer, $source] = $this->scenario($shop);

        $response = $this->signedPost($key, $secret, self::PATH, [
            'customer_ref' => self::MEMBER_REF,
            'email' => self::MEMBER_EMAIL,
            'subscription' => (string) $source->public_id,
            'offer' => (string) $offer->getKey(),
            'amount' => 49.0,
        ])->assertOk();

        $response->assertJsonPath('ok', true)
            ->assertJsonPath('result', AccountOfferOutcome::RESULT_OK);

        $this->assertSame(1, $this->payplusCalls);

        // The page redraws from the truth, not from what it hoped happened: the
        // new subscription is in the payload and the old one reads as ended.
        $statuses = collect($response->json('account.subscriptions'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['active', 'cancelled'], $statuses);

        // And the offer is gone from the redraw — they hold it now.
        $this->assertSame([], $response->json('account.offers'));

        Tenant::run($shop, function () use ($source): void {
            $this->assertSame(PlanStatus::CANCELLED, $source->fresh()->status);
        });
    }

    public function test_another_shoppers_subscription_is_a_404(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('offer-theirs.example.com');
        [$offer, $theirs] = $this->scenario($shop);

        $this->signedPost($key, $secret, self::PATH, [
            'customer_ref' => 'somebody-else',
            'email' => 'other@example.com',
            'subscription' => (string) $theirs->public_id,
            'offer' => (string) $offer->getKey(),
        ])->assertStatus(404);

        $this->assertSame(0, $this->payplusCalls);

        Tenant::run($shop, function () use ($theirs): void {
            $this->assertSame(PlanStatus::ACTIVE, $theirs->fresh()->status);
        });
    }

    public function test_a_price_named_by_the_client_is_a_guard_and_never_an_input(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('offer-price.example.com');
        [$offer, $source] = $this->scenario($shop);

        $response = $this->signedPost($key, $secret, self::PATH, [
            'customer_ref' => self::MEMBER_REF,
            'email' => self::MEMBER_EMAIL,
            'subscription' => (string) $source->public_id,
            'offer' => (string) $offer->getKey(),
            'amount' => 1.0,
        ])->assertOk();

        // 200, not 404: the offer is real, the answer is "the page moved".
        $response->assertJsonPath('ok', false)
            ->assertJsonPath('result', AccountOfferOutcome::RESULT_CHANGED);

        $this->assertSame(0, $this->payplusCalls, 'Never charged at either number.');
        Tenant::run($shop, fn () => $this->assertSame(1, InstallmentPlan::count()));
    }

    public function test_the_offer_payload_and_its_copy_reach_the_bootstrap(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('offer-payload.example.com');
        $this->scenario($shop, ['placement' => AccountOffer::PLACEMENT_TOP]);

        $response = $this->signedPost($key, $secret, '/api/woocommerce/account/bootstrap', [
            'customer_ref' => self::MEMBER_REF,
            'email' => self::MEMBER_EMAIL,
        ])->assertOk();

        $response->assertJsonStructure([
            'account' => [
                'offers' => [['id', 'placement', 'mode', 'timing', 'product', 'amount', 'currency',
                    'currency_symbol', 'price_display', 'cadence', 'first_charge_at', 'heading',
                    'subtext', 'image_url', 'button_text', 'html', 'source_plan', 'disclosure']],
                'rail_offers',
                'subscriptions' => [['offers']],
                'copy' => ['offer_accept', 'offer_from', 'offer_replaces', 'offer_price_label',
                    'offer_unavailable', 'result_accept_offer', 'result_accept_offer_unavailable',
                    'result_accept_offer_charge_failed', 'result_accept_offer_not_eligible',
                    'result_accept_offer_changed'],
            ],
        ]);
    }

    // === Fixtures ===

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

    /**
     * @param  array<string, mixed>  $offerAttributes
     * @return array{0: AccountOffer, 1: InstallmentPlan}
     */
    private function scenario(Shop $shop, array $offerAttributes = []): array
    {
        return Tenant::run($shop, function () use ($offerAttributes): array {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $offer = $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY), $offerAttributes);

            return [$offer, $this->makeSourcePlan()];
        });
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
