<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\PlanActivation;
use App\Domain\Installments\StoreActivationPage;
use App\Domain\ShopifySubscriptions\ContractActivation;
use App\Filament\Pages\ManageBillingSettings;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * THE ACTIVATION BUTTON ON A PAGE IN THE STORE.
 *
 * The merchant chooses a store page for activation links; the emailed link becomes that page
 * plus a token, and the "Subscription activation" theme block asks the App Proxy what to draw.
 *
 * What must never happen: the block showing anything to a visitor who did not arrive from a
 * valid link — no token, a stale one, or one from ANOTHER store — or a page load starting a
 * subscription. And a stored path must only ever lead into the store.
 */
final class StoreActivationPageTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SECRET = 'test_app_proxy_secret';

    private const ENDPOINT = '/proxy/activation';

    private const PAGE = '/pages/activate';

    private RecordingShopifyClient $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.webhook_secret', self::SECRET);
        config()->set('shopify.api_secret', self::SECRET);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Carbon::setTestNow();
        Tenant::clear();
        parent::tearDown();
    }

    // === The setting ===

    public function test_the_page_path_is_always_a_plain_path_inside_the_store(): void
    {
        $this->assertSame('/pages/activate', MerchantBillingSettings::normalizeActivationPagePath('/pages/activate'));
        $this->assertSame('/pages/activate', MerchantBillingSettings::normalizeActivationPagePath('pages/activate'));
        $this->assertSame('/pages/activate', MerchantBillingSettings::normalizeActivationPagePath('https://books.co.il/pages/activate?x=1#y'));
        // A second leading slash would make it another host; it stays a path in the store.
        $this->assertSame('/evil.com/x', MerchantBillingSettings::normalizeActivationPagePath('//evil.com/x'));

        foreach (['', '   ', '/', 'javascript:alert(1)', '/pages/<script>', null, 42] as $bad) {
            $this->assertNull(MerchantBillingSettings::normalizeActivationPagePath($bad));
        }
    }

    public function test_the_settings_screen_stores_the_page_or_the_lets_page(): void
    {
        $shop = $this->shop('settings-store.myshopify.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        Livewire::test(ManageBillingSettings::class)
            ->assertSet('data.activation_link_opens', ManageBillingSettings::ACTIVATION_OPENS_LETS)
            ->set('data.activation_link_opens', ManageBillingSettings::ACTIVATION_OPENS_STORE)
            ->set('data.activation_page_path', 'https://books.co.il/pages/activate')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(self::PAGE, MerchantBillingSettings::current()->fresh()->activationPagePath());

        Livewire::test(ManageBillingSettings::class)
            ->set('data.activation_page_path', 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors(['data.activation_page_path']);

        Livewire::test(ManageBillingSettings::class)
            ->set('data.activation_link_opens', ManageBillingSettings::ACTIVATION_OPENS_LETS)
            ->call('save');

        $this->assertNull(MerchantBillingSettings::current()->fresh()->activationPagePath());
    }

    // === The link ===

    public function test_the_link_opens_the_store_page_only_when_the_merchant_chose_one(): void
    {
        $shop = $this->shop('links-store.myshopify.com');
        $contract = $this->heldContract($shop);
        $plan = $this->waitingPlan($shop);

        $lets = Tenant::run($shop, fn (): string => app(ContractActivation::class)->url($contract));
        $this->assertStringStartsWith(config('app.url'), $lets, 'no page chosen: the LETS page, as before');

        $this->choosePage($shop);

        $contractUrl = Tenant::run($shop, fn (): string => app(ContractActivation::class)->url($contract->fresh()));
        $planUrl = Tenant::run($shop, fn (): string => app(PlanActivation::class)->url($plan->fresh()));

        $this->assertSame('https://links-store.myshopify.com/pages/activate?lets_activation=s.'.$contract->id.'.'.$contract->activation_nonce, $contractUrl);
        $this->assertStringStartsWith('https://links-store.myshopify.com/pages/activate?lets_activation=p.'.$plan->public_id.'.', $planUrl);
    }

    // === The block's server ===

    public function test_no_token_or_a_stale_token_shows_nothing(): void
    {
        $shop = $this->shop('quiet-store.myshopify.com');
        $contract = $this->heldContract($shop);

        $this->signed('GET', $shop, [])->assertOk()->assertExactJson(['show' => false]);
        $this->signed('GET', $shop, ['token' => 'nonsense'])->assertExactJson(['show' => false]);
        $this->signed('GET', $shop, ['token' => 's.'.$contract->id.'.'.str_repeat('b', PlanActivation::NONCE_LENGTH)])
            ->assertExactJson(['show' => false]);
    }

    public function test_a_token_from_another_store_shows_nothing_and_starts_nothing(): void
    {
        $mine = $this->shop('mine-store.myshopify.com');
        $theirs = $this->shop('theirs-store.myshopify.com');
        $contract = $this->heldContract($theirs);
        $this->fakeGraphql([]);

        $token = $this->token($contract);

        $this->signed('GET', $mine, ['token' => $token])->assertExactJson(['show' => false]);
        $this->signed('POST', $mine, ['token' => $token])->assertExactJson(['show' => false]);

        $this->assertTrue($contract->fresh()->awaitsActivation());
        $this->assertCount(0, $this->recorder->graphqlCalls);
    }

    public function test_the_store_page_shows_the_button_and_the_button_starts_a_shopify_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        $shop = $this->shop('button-store.myshopify.com');
        $contract = $this->heldContract($shop);
        $token = $this->token($contract);
        $this->fakeGraphql([
            $this->contractAnswer('subscriptionContractSetNextBillingDate', 'PAUSED'),
            $this->contractAnswer('subscriptionContractActivate', 'ACTIVE'),
        ]);

        // Opening the page draws the card and changes nothing.
        $this->signed('GET', $shop, ['token' => $token])
            ->assertOk()
            ->assertJson(['show' => true, 'awaiting' => true, 'button' => __('activation.page.button', [], 'he')]);
        $this->assertCount(0, $this->recorder->graphqlCalls);
        $this->assertTrue($contract->fresh()->awaitsActivation());

        // The button.
        $this->signed('POST', $shop, ['token' => $token])
            ->assertOk()
            ->assertJson(['show' => true, 'awaiting' => false, 'button' => null])
            ->assertJsonPath('note', __('activation.page.next_charge', ['date' => '17/10/2026'], 'he'));

        $this->assertFalse($contract->fresh()->awaitsActivation());
        $this->assertSame('2026-10-17', $contract->fresh()->next_billing_date->toDateString());
    }

    public function test_the_button_starts_a_payplus_plan_on_a_shopify_store(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        $shop = $this->shop('plan-store.myshopify.com');
        $plan = $this->waitingPlan($shop);
        $this->choosePage($shop);
        $url = Tenant::run($shop, fn (): string => app(PlanActivation::class)->url($plan->fresh()));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->signed('POST', $shop, ['token' => $query[StoreActivationPage::QUERY_PARAM]])
            ->assertOk()
            ->assertJson(['show' => true, 'awaiting' => false]);

        $fresh = Tenant::run($shop, fn () => $plan->fresh());
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
        $this->assertSame('2026-10-17', $fresh->next_charge_at->toDateString());
    }

    // === Fixtures ===

    private function shop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Store '.$domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function choosePage(Shop $shop): void
    {
        Tenant::run($shop, fn () => MerchantBillingSettings::current()->forceFill(['activation_page_path' => self::PAGE])->save());
    }

    private function heldContract(Shop $shop): SubscriptionContract
    {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.random_int(1000, 99999),
            'shopify_customer_gid' => 'gid://shopify/Customer/77',
            'customer_email' => 'dana@example.com',
            'status' => SubscriptionContract::STATUS_PAUSED,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'currency' => 'ILS',
            'amount' => 49.90,
            'lines' => [['title' => 'Monthly box', 'quantity' => 1, 'amount' => '49.90']],
            'synced_at' => now(),
            'awaiting_activation_at' => now(),
            'activation_nonce' => Str::random(PlanActivation::NONCE_LENGTH),
        ])->save();

        return $contract;
    }

    private function waitingPlan(Shop $shop): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => 'cust-store',
                'payplus_card_token_uid' => 'tok-'.Str::random(8),
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 39.0,
                'total_charged' => 39.0,
                'installment_amount' => 39.0,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'next_charge_at' => null,
                'public_id' => 'PLN-'.Str::upper(Str::random(12)),
                'customer_email' => 'subscriber@example.com',
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'cust-store',
            ]);
            $plan->forceFill(['shop_id' => (int) $shop->getKey(), 'status' => PlanStatus::AWAITING_ACTIVATION->value])->save();

            return $plan->fresh();
        });
    }

    private function token(SubscriptionContract $contract): string
    {
        return StoreActivationPage::KIND_CONTRACT.'.'.$contract->id.'.'.$contract->activation_nonce;
    }

    /**
     * A request as Shopify's App Proxy forwards it: the shop and a signature over the query.
     *
     * @param  array<string, string>  $data  query for GET, JSON body for POST
     */
    private function signed(string $method, Shop $shop, array $data): TestResponse
    {
        $query = $method === 'GET' ? $data + ['shop' => (string) $shop->shopify_domain] : ['shop' => (string) $shop->shopify_domain];
        ksort($query);

        $message = '';
        foreach ($query as $key => $value) {
            $message .= $key.'='.$value;
        }
        $query['signature'] = hash_hmac('sha256', $message, self::SECRET);

        $url = self::ENDPOINT.'?'.http_build_query($query);

        return $method === 'GET' ? $this->getJson($url) : $this->postJson($url, $data);
    }

    /** @param list<array<string, mixed>> $responses */
    private function fakeGraphql(array $responses): void
    {
        $this->recorder = new RecordingShopifyClient;
        $this->recorder->graphqlResponses = $responses;
        $recorder = $this->recorder;

        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }

    /** @return array<string, mixed> */
    private function contractAnswer(string $field, string $status): array
    {
        return ['data' => [$field => [
            'contract' => [
                'id' => 'gid://shopify/SubscriptionContract/1',
                'status' => $status,
                'nextBillingDate' => '2026-10-17T00:00:00Z',
                'currencyCode' => 'ILS',
                'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
            ],
            'userErrors' => [],
        ]]];
    }
}
