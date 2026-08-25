<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Domain\Installments\CardUpdateService;
use App\Models\ActivityEvent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Update my card" on the PayPlus rail.
 *
 * The laws under test: the verb answers with a LINK and changes nothing; the
 * page it mints asks PayPlus to re-vault (`create_token`) and correlates with a
 * prefix a deposit callback can never wear; the callback vaults a NEW method and
 * points the plan — and every live sibling on the old card — at it; a replay
 * changes nothing more; and card digits exist nowhere in any of it.
 */
final class CardUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake gateway captured, for payload assertions. */
    private array $generatedPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPayloads = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private readonly CardUpdateTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                $this->test->captureGenerated($payload);

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['payment_page_link' => 'https://payplus.example/page/abc', 'page_request_uid' => 'pr-1'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    public function captureGenerated(array $payload): void
    {
        $this->generatedPayloads[] = $payload;
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === The verb ===

    public function test_the_verb_is_offered_on_a_live_plan_and_hidden_on_a_finished_one(): void
    {
        $shop = $this->connectedShop('card-verb.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $live = $this->plan($shop, 'c1', 'one@example.com');
            $done = $this->plan($shop, 'c1', 'one@example.com');
            $done->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

            $actions = app(CustomerSubscriptionActions::class);

            $this->assertTrue($actions->availableFor($live)[CustomerSubscriptionActions::ACTION_UPDATE_CARD]);
            $this->assertFalse($actions->availableFor($done->fresh())[CustomerSubscriptionActions::ACTION_UPDATE_CARD]);

            // And it rides the payload like every other verb.
            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'c1', 'one@example.com'));
            $liveCard = collect($model['subscriptions'])->firstWhere('id', (string) $live->public_id);
            $this->assertContains('update_card', $liveCard['actions']);
        });
    }

    public function test_a_shop_without_payplus_or_a_callback_token_offers_nothing(): void
    {
        $bare = Shop::create([
            'woocommerce_domain' => 'card-bare.example.com',
            'name' => 'Bare',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::run($bare, function () use ($bare): void {
            $plan = $this->plan($bare, 'c2', 'two@example.com');

            $this->assertFalse(
                app(CustomerSubscriptionActions::class)->availableFor($plan)[CustomerSubscriptionActions::ACTION_UPDATE_CARD],
            );
        });
    }

    public function test_the_verb_answers_with_a_link_and_changes_nothing(): void
    {
        $shop = $this->connectedShop('card-link.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c3', 'three@example.com');

            $result = app(CustomerSubscriptionActions::class)->perform(
                $this->visitor($shop, 'c3', 'three@example.com'),
                CustomerSubscriptionActions::ACTION_UPDATE_CARD,
                (string) $plan->public_id,
            );

            $this->assertSame(CustomerSubscriptionActions::RESULT_OK, $result['result']);
            $this->assertSame('https://payplus.example/page/abc', $result['link']);

            $fresh = $plan->fresh();
            $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
            $this->assertNull($fresh->payment_method_id, 'the swap happens on the callback, never on the click');

            // The CLICK is on the record — the gap between this and
            // card_updated is the "tried and gave up" dunning signal.
            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CARD_UPDATE_STARTED,
                'actor' => ActivityEvent::ACTOR_CUSTOMER,
            ]);
        });
    }

    public function test_the_minted_page_asks_to_revault_and_wears_our_own_marker(): void
    {
        $shop = $this->connectedShop('card-mint.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c4', 'four@example.com');

            app(CardUpdateService::class)->mintPage($shop, $plan);

            $this->assertCount(1, $this->generatedPayloads);
            $payload = $this->generatedPayloads[0];

            $this->assertTrue($payload['create_token']);
            $this->assertSame(CardUpdateService::MORE_INFO_PREFIX.$plan->public_id, $payload['more_info']);
            $this->assertSame((float) config('payplus.card_update_amount'), $payload['amount']);
            $this->assertSame((int) config('payplus.card_update_charge_method'), $payload['charge_method']);
            $this->assertStringContainsString('/woocommerce/cardupdate/callback/'.$shop->wc_shop_token, $payload['refURL_callback']);
            $this->assertStringContainsString('/woocommerce/cardupdate/return/'.$shop->wc_shop_token, $payload['refURL_success']);
        });
    }

    // === The callback ===

    public function test_a_successful_callback_vaults_the_token_and_points_the_plan_at_it(): void
    {
        $shop = $this->connectedShop('card-cb.example.com');

        [$plan] = Tenant::run($shop, fn (): array => [$this->plan($shop, 'c5', 'five@example.com')]);

        $response = $this->postJson(
            '/woocommerce/cardupdate/callback/'.$shop->wc_shop_token,
            $this->callbackBody($plan, tokenUid: 'tok-new-1'),
        );

        $response->assertOk();
        $response->assertJson(['ok' => true, 'updated' => true]);

        Tenant::run($shop, function () use ($plan): void {
            $fresh = $plan->fresh();
            $this->assertNotNull($fresh->payment_method_id);

            $method = InstallmentPaymentMethod::query()->find($fresh->payment_method_id);
            $this->assertSame('tok-new-1', $method->payplus_card_token_uid);
            $this->assertSame('visa', $method->card_brand);
            $this->assertSame('4242', $method->card_last_four);
            // Identity copied off the PLAN, not the callback.
            $this->assertSame($plan->shopify_customer_id, $method->shopify_customer_id);

            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CARD_UPDATED,
                'actor' => ActivityEvent::ACTOR_CUSTOMER,
            ]);
        });
    }

    /** One person updated one card — the sibling on the same dead card comes along. */
    public function test_siblings_on_the_old_card_are_repointed_and_strangers_are_not(): void
    {
        $shop = $this->connectedShop('card-sib.example.com');

        [$plan, $sibling, $stranger, $oldMethod] = Tenant::run($shop, function () use ($shop): array {
            $old = InstallmentPaymentMethod::query()->create([
                'shopify_customer_id' => 'c6',
                'payplus_card_token_uid' => 'tok-old',
                'card_brand' => 'visa',
                'card_last_four' => '1111',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $plan = $this->plan($shop, 'c6', 'six@example.com');
            $sibling = $this->plan($shop, 'c6', 'six@example.com');
            $stranger = $this->plan($shop, 'other', 'other@example.com');

            foreach ([$plan, $sibling] as $p) {
                $p->forceFill(['payment_method_id' => $old->getKey()])->save();
            }

            return [$plan, $sibling, $stranger, $old];
        });

        $this->postJson(
            '/woocommerce/cardupdate/callback/'.$shop->wc_shop_token,
            $this->callbackBody($plan, tokenUid: 'tok-new-2'),
        )->assertOk();

        Tenant::run($shop, function () use ($plan, $sibling, $stranger, $oldMethod): void {
            $newId = $plan->fresh()->payment_method_id;

            $this->assertNotSame((int) $oldMethod->getKey(), (int) $newId);
            $this->assertSame((int) $newId, (int) $sibling->fresh()->payment_method_id, 'the sibling came along');
            $this->assertNull($stranger->fresh()->payment_method_id, 'a stranger did not');

            // The old method row survives as history.
            $this->assertNotNull($oldMethod->fresh());
        });
    }

    public function test_a_replayed_callback_reuses_the_row_and_changes_nothing_more(): void
    {
        $shop = $this->connectedShop('card-replay.example.com');

        [$plan] = Tenant::run($shop, fn (): array => [$this->plan($shop, 'c7', 'seven@example.com')]);

        $body = $this->callbackBody($plan, tokenUid: 'tok-new-3');

        $this->postJson('/woocommerce/cardupdate/callback/'.$shop->wc_shop_token, $body)->assertOk();
        $this->postJson('/woocommerce/cardupdate/callback/'.$shop->wc_shop_token, $body)->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertSame(1, InstallmentPaymentMethod::query()->count());
        });
    }

    public function test_an_unknown_shop_token_is_a_404_and_a_failure_status_vaults_nothing(): void
    {
        $shop = $this->connectedShop('card-refuse.example.com');
        [$plan] = Tenant::run($shop, fn (): array => [$this->plan($shop, 'c8', 'eight@example.com')]);

        $this->postJson(
            '/woocommerce/cardupdate/callback/no-such-token',
            $this->callbackBody($plan, tokenUid: 'tok-x'),
        )->assertNotFound();

        $failure = $this->callbackBody($plan, tokenUid: 'tok-x');
        $failure['transaction']['status_code'] = 'failed';

        $this->postJson('/woocommerce/cardupdate/callback/'.$shop->wc_shop_token, $failure)
            ->assertOk()
            ->assertJson(['updated' => false]);

        Tenant::run($shop, function (): void {
            $this->assertSame(0, InstallmentPaymentMethod::query()->count());
        });
    }

    /** A deposit callback replayed at this endpoint wears the wrong marker. */
    public function test_a_bare_public_id_without_our_prefix_is_ignored(): void
    {
        $shop = $this->connectedShop('card-prefix.example.com');
        [$plan] = Tenant::run($shop, fn (): array => [$this->plan($shop, 'c9', 'nine@example.com')]);

        $body = $this->callbackBody($plan, tokenUid: 'tok-x');
        $body['transaction']['more_info'] = (string) $plan->public_id; // no cardupd: prefix

        $this->postJson('/woocommerce/cardupdate/callback/'.$shop->wc_shop_token, $body)
            ->assertOk()
            ->assertJson(['updated' => false]);
    }

    public function test_the_return_landing_renders_for_both_outcomes(): void
    {
        $shop = $this->connectedShop('card-return.example.com');

        $this->get('/woocommerce/cardupdate/return/'.$shop->wc_shop_token.'?status=success')
            ->assertOk()
            ->assertSee(__('storefront.card_update.return_success_title'));

        $this->get('/woocommerce/cardupdate/return/'.$shop->wc_shop_token.'?status=failure')
            ->assertOk()
            ->assertSee(__('storefront.card_update.return_failure_title'));
    }

    // === helpers ===

    private function connectedShop(string $domain): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'wc_shop_token' => strtolower(str_replace('.', '', $domain)).'tok',
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'k', 'secret_key' => 's',
            'terminal_uid' => 't', 'payment_page_uid' => 'p',
        ];
        $shop->woocommerce_credentials = [
            'base_url' => 'https://'.$domain,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function plan(Shop $shop, string $ref, string $email): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.$ref.'-'.uniqid(),
            'shopify_customer_id' => $ref,
            'customer_email' => $email,
            'customer_name' => 'Card Tester',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ])->save();

        return $plan;
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

    /** @return array<string, mixed> the raw PayPlus body shape */
    private function callbackBody(InstallmentPlan $plan, string $tokenUid): array
    {
        return [
            'transaction' => [
                'status_code' => '000',
                'more_info' => CardUpdateService::MORE_INFO_PREFIX.$plan->public_id,
                'uid' => 'txn-'.$tokenUid,
                'token_uid' => $tokenUid,
                'customer_uid' => 'cust-uid-1',
                'four_digits' => '4242',
                'brand_name' => 'visa',
                'expiry_month' => '12',
                'expiry_year' => '30',
            ],
        ];
    }
}
