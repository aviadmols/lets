<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\Installments\PlanActivation;
use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Domain\ShopifySubscriptions\ContractActivation;
use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Domain\ShopifySubscriptions\Jobs\SendContractActivationLinkJob;
use App\Filament\Resources\SubscriptionContractResource\Pages\ViewSubscriptionContract;
use App\Mail\ContractActivationMail;
use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\Webhooks\SubscriptionWebhookHandler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * ACTIVATION LINKS ON THE SHOPIFY PAYMENTS RAIL.
 *
 * Shopify's checkout takes the first cycle and creates the contract. When the contract was
 * sold by a plan with `requires_activation`, it is held: marked unbillable here, paused at
 * Shopify, its customer emailed a link. Confirming the link moves the next charge to one
 * cycle from that day and starts it again.
 *
 * What must never happen: a held contract billed by anything, an update webhook holding a
 * running subscription, a mail scanner starting one, or a hold lifted before Shopify agreed.
 */
final class ContractActivationTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SELLING_PLAN = 'gid://shopify/SellingPlan/555';

    private const GID = 'gid://shopify/SubscriptionContract/9911';

    private RecordingShopifyClient $recorder;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Carbon::setTestNow();
        Tenant::clear();
        parent::tearDown();
    }

    // === When Shopify creates the contract ===

    public function test_a_new_contract_from_an_activation_plan_is_held_paused_and_emailed_once(): void
    {
        Bus::fake([SendContractActivationLinkJob::class]);
        $shop = $this->shop();
        $this->template($shop, requiresActivation: true);
        $this->fakeGraphql([$this->readBack('ACTIVE'), $this->statusAnswer('subscriptionContractPause', 'PAUSED')]);

        $this->deliver($shop, ContractActivation::TOPIC_CREATE);

        $contract = SubscriptionContract::acrossAllTenants()->firstOrFail();
        $this->assertTrue($contract->awaitsActivation());
        $this->assertFalse($contract->isBillable());
        $this->assertSame(SubscriptionContract::STATUS_PAUSED, $contract->status, 'Shopify shows it paused too');
        $this->assertStringContainsString('subscriptionContractPause', $this->recorder->graphqlCalls[1]['query']);
        Bus::assertDispatchedTimes(SendContractActivationLinkJob::class, 1);

        // Shopify delivers the same webhook again: nothing is held, paused or emailed twice.
        $this->recorder->graphqlResponses = [$this->readBack('PAUSED')];
        $this->deliver($shop, ContractActivation::TOPIC_CREATE, 'wh-2');

        $this->assertCount(3, $this->recorder->graphqlCalls, 'only the read-back, no second pause');
        Bus::assertDispatchedTimes(SendContractActivationLinkJob::class, 1);
        $this->assertSame(1, ActivityEvent::query()->withoutGlobalScopes()->where('kind', PlanActivation::KIND_AWAITING)->count());
    }

    public function test_a_contract_from_a_plan_without_activation_keeps_running(): void
    {
        Bus::fake([SendContractActivationLinkJob::class]);
        $shop = $this->shop();
        $this->template($shop, requiresActivation: false);
        $this->fakeGraphql([$this->readBack('ACTIVE')]);

        $this->deliver($shop, ContractActivation::TOPIC_CREATE);

        $contract = SubscriptionContract::acrossAllTenants()->firstOrFail();
        $this->assertFalse($contract->awaitsActivation());
        $this->assertTrue($contract->isBillable());
        $this->assertCount(1, $this->recorder->graphqlCalls);
        Bus::assertNotDispatched(SendContractActivationLinkJob::class);
    }

    public function test_an_update_webhook_never_holds_a_running_contract(): void
    {
        Bus::fake([SendContractActivationLinkJob::class]);
        $shop = $this->shop();
        $this->template($shop, requiresActivation: true);
        $this->fakeGraphql([$this->readBack('ACTIVE')]);

        $this->deliver($shop, 'subscription_contracts/update');

        $this->assertFalse(SubscriptionContract::acrossAllTenants()->firstOrFail()->awaitsActivation());
        Bus::assertNotDispatched(SendContractActivationLinkJob::class);
    }

    public function test_a_create_it_could_not_read_is_retried_rather_than_left_running(): void
    {
        $shop = $this->shop();
        $this->template($shop, requiresActivation: true);
        $this->recorder = new RecordingShopifyClient;
        $this->recorder->graphqlThrows = new \RuntimeException('shopify.graphql_failed — status=503');
        $recorder = $this->recorder;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $this->expectExceptionMessage('shopify_subscriptions.contract_create_unread');

        $this->deliver($shop, ContractActivation::TOPIC_CREATE);
    }

    // === Nothing bills a held contract ===

    public function test_no_billing_path_touches_a_held_contract(): void
    {
        $shop = $this->shop();
        // Worst case: Shopify refused the pause, so it still says ACTIVE, and its date is due.
        $contract = $this->heldContract($shop, status: SubscriptionContract::STATUS_ACTIVE, due: now()->subDay());
        $this->fakeGraphql([]);

        Queue::fake();
        $this->artisan('shopify-subscriptions:dispatch-due')->assertExitCode(0);
        Queue::assertNotPushed(BillingAttemptJob::class);

        Tenant::run($shop, fn () => (new BillingAttemptJob((int) $shop->getKey(), (int) $contract->getKey(), now()->toDateString()))->handle());
        $this->assertSame(0, SubscriptionBillingAttempt::acrossAllTenants()->count());

        $actions = app(ContractActionService::class);
        Tenant::run($shop, function () use ($actions, $shop, $contract): void {
            $this->assertFalse($actions->billNow($shop, $contract, 'admin')['ok']);
            foreach ([
                $actions->resume($shop, $contract, 'customer'),
                $actions->pause($shop, $contract, 'customer'),
                $actions->skipNext($shop, $contract, 'customer'),
                $actions->reschedule($shop, $contract, now()->addWeek(), 'customer'),
            ] as $refused) {
                $this->assertSame(ContractActionService::ERR_AWAITING_ACTIVATION, $refused['reason']);
            }
        });

        $this->assertCount(0, $this->recorder->graphqlCalls, 'Shopify was never asked to bill or move it');
    }

    // === The link ===

    public function test_opening_the_link_changes_nothing_and_confirming_starts_it_one_cycle_from_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        $shop = $this->shop();
        $contract = $this->heldContract($shop);
        [$url, $activateUrl] = Tenant::run($shop, fn (): array => [
            app(ContractActivation::class)->url($contract),
            app(ContractActivation::class)->activateUrl($contract),
        ]);
        $this->fakeGraphql([
            $this->statusAnswer('subscriptionContractSetNextBillingDate', 'PAUSED', '2026-10-17T00:00:00Z'),
            $this->statusAnswer('subscriptionContractActivate', 'ACTIVE', '2026-10-17T00:00:00Z'),
        ]);

        // A mail scanner (or the customer) opens the link.
        $this->get($url)->assertOk()->assertSee(__('activation.page.button', [], 'he'));
        $this->assertCount(0, $this->recorder->graphqlCalls);

        // The customer presses the button.
        $this->post($activateUrl)->assertOk()->assertSee('17/10/2026');

        $fresh = $contract->fresh();
        $this->assertStringStartsWith('2026-10-17', (string) $this->recorder->graphqlCalls[0]['variables']['date']);
        $this->assertStringContainsString('subscriptionContractActivate', $this->recorder->graphqlCalls[1]['query']);
        $this->assertFalse($fresh->awaitsActivation());
        $this->assertTrue($fresh->isBillable());
        $this->assertSame('2026-10-17', $fresh->next_billing_date->toDateString());
        $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, ActivityEvent::query()->withoutGlobalScopes()->where('kind', PlanActivation::KIND_ACTIVATED)->value('actor'));

        // A second press asks Shopify nothing and says it is active.
        $this->post($activateUrl)->assertOk()->assertSee(__('activation.page.active_heading', [], 'he'));
        $this->assertCount(2, $this->recorder->graphqlCalls);
    }

    public function test_a_start_shopify_refuses_keeps_it_held_and_offers_the_button_again(): void
    {
        $shop = $this->shop();
        $contract = $this->heldContract($shop);
        $activateUrl = Tenant::run($shop, fn (): string => app(ContractActivation::class)->activateUrl($contract));
        $this->fakeGraphql([['data' => ['subscriptionContractSetNextBillingDate' => [
            'contract' => null,
            'userErrors' => [['field' => 'date', 'message' => 'not allowed']],
        ]]]]);

        $this->post($activateUrl)
            ->assertOk()
            ->assertSee(__('activation.page.failed', [], 'he'))
            ->assertSee(__('activation.page.button', [], 'he'));

        $this->assertTrue($contract->fresh()->awaitsActivation(), 'the hold is lifted only once Shopify agreed');
        $this->assertFalse($contract->fresh()->isBillable());
    }

    public function test_a_revoked_link_is_gone(): void
    {
        $shop = $this->shop();
        $contract = $this->heldContract($shop);
        $old = Tenant::run($shop, fn (): string => app(ContractActivation::class)->activateUrl($contract));
        $this->fakeGraphql([]);

        Tenant::run($shop, fn () => app(ContractActivation::class)->revoke($contract->fresh()));

        $this->post($old)->assertStatus(410);
        $this->assertTrue($contract->fresh()->awaitsActivation());
        $this->assertCount(0, $this->recorder->graphqlCalls);
    }

    public function test_the_email_carries_the_link_to_the_customer(): void
    {
        Mail::fake();
        $shop = $this->shop();
        $contract = $this->heldContract($shop);

        (new SendContractActivationLinkJob((int) $shop->getKey(), (int) $contract->getKey()))->handle(app(ContractActivation::class));

        $url = Tenant::run($shop, fn (): string => app(ContractActivation::class)->url($contract->fresh()));
        Mail::assertSent(ContractActivationMail::class, fn (ContractActivationMail $mail): bool => $mail->activationUrl === $url
            && $mail->hasTo('dana@example.com'));
    }

    // === Admin ===

    public function test_the_merchant_sees_it_waiting_and_can_start_it_for_the_customer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        $shop = $this->shop();
        $contract = $this->heldContract($shop);
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());
        $this->fakeGraphql([
            $this->statusAnswer('subscriptionContractSetNextBillingDate', 'PAUSED', '2026-10-17T00:00:00Z'),
            $this->statusAnswer('subscriptionContractActivate', 'ACTIVE', '2026-10-17T00:00:00Z'),
        ]);

        Livewire::test(ViewSubscriptionContract::class, ['contract' => $contract->getKey()])
            ->assertSee(__('shopify_subscriptions.status.AWAITING_ACTIVATION'))
            ->assertActionVisible('activateNow')
            ->assertActionVisible('activationLink')
            ->assertActionHidden('chargeNow')
            ->assertActionHidden('resume')
            ->assertActionHidden('reschedule')
            ->callAction('activateNow');

        $fresh = $contract->fresh();
        $this->assertFalse($fresh->awaitsActivation());
        $this->assertSame(SubscriptionContract::STATUS_ACTIVE, $fresh->status);
        $this->assertSame('2026-10-17', $fresh->next_billing_date->toDateString());
    }

    // === Fixtures ===

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'activation-rail.myshopify.com',
            'name' => 'Activation Rail',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function template(Shop $shop, bool $requiresActivation): ProductSubscriptionPlan
    {
        return Tenant::run($shop, function () use ($shop, $requiresActivation): ProductSubscriptionPlan {
            $product = new Product;
            $product->forceFill([
                'shop_id' => $shop->id,
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => '4242',
                'title' => 'Monthly box',
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
            ])->save();

            $template = new ProductSubscriptionPlan;
            $template->forceFill([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
                'plan_kind' => 'recurring',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
                'discount_value' => 0,
                'billing_rail' => ProductSubscriptionPlan::RAIL_SHOPIFY_PAYMENTS,
                'shopify_selling_plan_gid' => self::SELLING_PLAN,
                'status' => PlanTemplateStatus::ACTIVE->value,
                'position' => 0,
                'requires_activation' => $requiresActivation,
            ])->save();

            return $template;
        });
    }

    private function heldContract(
        Shop $shop,
        string $status = SubscriptionContract::STATUS_PAUSED,
        ?Carbon $due = null,
    ): SubscriptionContract {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => self::GID,
            'shopify_customer_gid' => 'gid://shopify/Customer/77',
            'customer_email' => 'dana@example.com',
            'customer_name' => 'Dana Buyer',
            'status' => $status,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => $due ?? now()->addMonth(),
            'currency' => 'ILS',
            'amount' => 49.90,
            'lines' => [['title' => 'Monthly box', 'quantity' => 1, 'amount' => '49.90', 'selling_plan_id' => self::SELLING_PLAN]],
            'synced_at' => now(),
            'awaiting_activation_at' => now(),
            'activation_nonce' => str_repeat('a', PlanActivation::NONCE_LENGTH),
        ])->save();

        return $contract;
    }

    /** Deliver one subscription_contracts webhook through the real handler. */
    private function deliver(Shop $shop, string $topic, string $webhookId = 'wh-1'): void
    {
        $event = WebhookEvent::create([
            'shop_id' => (int) $shop->getKey(),
            'source' => WebhookEvent::SOURCE_SHOPIFY,
            'topic' => $topic,
            'webhook_id' => $webhookId,
            'raw_payload' => [
                'admin_graphql_api_id' => self::GID,
                'billing_policy' => ['interval' => 'month', 'interval_count' => 1],
                'currency_code' => 'ILS',
                'customer_id' => 77,
                'status' => 'active',
            ],
            'hmac_valid' => true,
            'received_at' => now(),
        ]);

        Tenant::run($shop, fn () => app(SubscriptionWebhookHandler::class)->handle($event));
    }

    /** @param list<array<string, mixed>> $responses */
    private function fakeGraphql(array $responses): void
    {
        $this->recorder = new RecordingShopifyClient;
        $this->recorder->graphqlResponses = $responses;
        $recorder = $this->recorder;

        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }

    /** @return array<string, mixed> the single-contract read-back, sold by our selling plan */
    private function readBack(string $status): array
    {
        return ['data' => ['subscriptionContract' => [
            'id' => self::GID,
            'status' => $status,
            'currencyCode' => 'ILS',
            'nextBillingDate' => now()->addMonth()->toIso8601String(),
            'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
            'deliveryPrice' => ['amount' => '49.90'],
            'customer' => ['id' => 'gid://shopify/Customer/77', 'email' => 'dana@example.com', 'firstName' => 'Dana', 'lastName' => 'Buyer'],
            'lines' => ['edges' => [['node' => [
                'id' => 'gid://shopify/SubscriptionLine/1',
                'title' => 'Monthly box',
                'quantity' => 1,
                'currentPrice' => ['amount' => '49.90'],
                'sellingPlanId' => self::SELLING_PLAN,
            ]]]],
        ]]];
    }

    /** @return array<string, mixed> a contract mutation's answer */
    private function statusAnswer(string $field, string $status, ?string $nextBillingDate = null): array
    {
        return ['data' => [$field => [
            'contract' => [
                'id' => self::GID,
                'status' => $status,
                'nextBillingDate' => $nextBillingDate ?? now()->addMonth()->toIso8601String(),
                'currencyCode' => 'ILS',
                'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
            ],
            'userErrors' => [],
        ]]];
    }
}
