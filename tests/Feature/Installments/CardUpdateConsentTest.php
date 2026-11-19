<?php

namespace Tests\Feature\Installments;

use App\Domain\Billing\ConsentCeiling;
use App\Domain\Installments\CardUpdateLinks;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\CardUpdateTerms;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Filament\Pages\PaymentRecovery;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesPayPlusIpn;
use Tests\TestCase;

/**
 * A subscription with no stored consent.
 *
 * The case that started this: a merchant rescued an abandoned checkout with a
 * card-update link. The card was saved, a date was set, and the charge gate
 * refused it every five minutes for six days (1,599 times), because consent is
 * recorded when a checkout completes and that never happened. Nobody saw it.
 *
 * Pinned here:
 *   - the card-update page shows such a plan's terms and requires a tick; the
 *     consent is written when the card is really saved, never before;
 *   - a refused plan is stamped once, said once, skipped by the scheduler and
 *     listed on Failed charges, and any consent lifts it;
 *   - a guest's "0" customer reference never borrows another guest's consent;
 *   - the attestation command shows what it would do and writes nothing
 *     without --commit, and what it writes says it was the merchant.
 */
final class CardUpdateConsentTest extends TestCase
{
    use FakesPayPlusIpn;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $generated = [];

    public int $charges = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGateway();
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === The page ===

    public function test_a_plan_without_consent_shows_its_terms_and_one_with_consent_does_not(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $bare = $this->plan($shop);
            ['url' => $bareUrl] = app(CardUpdateLinks::class)->mint($shop, $bare);

            $this->get($bareUrl)->assertOk()
                ->assertSee('class="rc-campaign-terms"', false)
                ->assertSee('name="'.CardUpdateTerms::FIELD.'"', false)
                ->assertDontSee($bare->customer_email);

            $consented = $this->plan($shop, 'two@example.com');
            $this->consent($shop, $consented);
            ['url' => $okUrl] = app(CardUpdateLinks::class)->mint($shop, $consented);

            $this->get($okUrl)->assertOk()->assertDontSee('class="rc-campaign-terms"', false);
        });
    }

    public function test_without_the_tick_no_card_page_is_opened(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $this->post(route(CardUpdateLinks::ROUTE_START, ['token' => $this->tokenFrom($url)]))
                ->assertStatus(422)
                ->assertSee('rc-campaign-terms__error', false);

            $this->assertSame([], $this->generated);
            $this->assertNull($link->fresh()->terms_accepted_at);
        });
    }

    public function test_the_tick_is_stamped_on_the_link_and_the_card_page_opens(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $this->post(route(CardUpdateLinks::ROUTE_START, ['token' => $this->tokenFrom($url)]), $this->agreed($plan))
                ->assertRedirect('https://payplus.example/page/abc');

            $link->refresh();
            $this->assertNotNull($link->terms_accepted_at);
            $this->assertSame(100.0, (float) $link->terms_snapshot['amount']);
            $this->assertSame('monthly', $link->terms_snapshot['frequency']);

            // Ticked, but no card saved yet: nothing that can be charged.
            $this->assertFalse(app(ConsentCeiling::class)->hasConsent($plan));
        });
    }

    public function test_saving_the_card_turns_the_tick_into_the_consent_and_lifts_the_stop(): void
    {
        $shop = $this->shop();

        [$plan, $link, $url] = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->plan($shop);
            $plan->forceFill(['consent_blocked_at' => now()->subDay()])->save();
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            return [$plan, $link, $url];
        });

        $this->post(route(CardUpdateLinks::ROUTE_START, ['token' => $this->tokenFrom($url)]), Tenant::run($shop, fn () => $this->agreed($plan)));

        $card = ['token' => 'tok-new-card', 'four_digits' => '4242', 'brand_name' => 'Visa'];
        Http::fake($this->payplusIpn(CardUpdateService::moreInfoFor($plan, $link), [], ['customer_uid' => 'c1', 'card_information' => $card]));

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, $card))
            ->assertOk()->assertJson(['updated' => true]);

        Tenant::run($shop, function () use ($plan): void {
            $consent = CustomerConsent::query()->where('plan_id', $plan->id)->sole();
            $this->assertSame(ConsentCeiling::SOURCE_CUSTOMER, $consent->ceiling_source);
            $this->assertSame(100.0, (float) $consent->consented_amount);
            $this->assertNotNull($consent->accepted_at);
            $this->assertStringStartsWith(CardUpdateTerms::VERSION_PREFIX.':', (string) $consent->accepted_terms_version);

            $this->assertNull($plan->fresh()->consent_blocked_at);
            $this->assertTrue(ActivityEvent::query()->where('plan_id', $plan->id)->where('kind', Timeline::KIND_CONSENT_RECORDED)->exists());
        });
    }

    // === The stop ===

    public function test_a_refused_plan_is_stamped_and_said_once_and_the_scheduler_leaves_it(): void
    {
        $shop = $this->shop();

        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = $this->plan($shop);
            $plan->forceFill([
                'payment_method_id' => $this->card($shop)->id,
                'next_charge_at' => now()->subDay(),
            ])->save();

            $orchestrator = app(ChargeOrchestrator::class);
            $orchestrator->charge($plan->id, PaymentType::RECURRING);
            $orchestrator->charge($plan->id, PaymentType::RECURRING);

            return $plan->fresh();
        });

        $this->assertSame(0, $this->charges);
        $this->assertNotNull($plan->consent_blocked_at);
        $this->assertSame(1, ActivityEvent::withoutGlobalScopes()->where('plan_id', $plan->id)->where('kind', Timeline::KIND_CONSENT_MISSING)->count());

        Queue::fake();
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        Queue::assertNotPushed(ChargeJob::class);

        // It is where a person looks.
        Tenant::run($shop, function () use ($plan): void {
            $this->assertSame([$plan->id], PaymentRecovery::scopeFor(PaymentRecovery::TAB_NO_CONSENT, InstallmentPlan::query())->pluck('id')->all());
            $this->assertSame('1', PaymentRecovery::getNavigationBadge());
        });

        // Any consent lifts it, and the next run charges.
        Tenant::run($shop, fn () => $this->consent($shop, $plan));
        $this->assertNull($plan->fresh()->consent_blocked_at);

        Queue::fake();
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->planId === (int) $plan->id);
    }

    /** "0" is every guest. One guest's customer-wide consent is not another's. */
    public function test_a_guest_reference_never_borrows_another_guests_consent(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            CustomerConsent::query()->create([
                'shop_id' => (int) $shop->getKey(),
                'plan_id' => null,
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
                'shopify_customer_id' => '0',
                'customer_email' => 'someone-else@example.com',
                'accepted_at' => now(),
            ]);

            $guest = $this->plan($shop);
            $guest->forceFill(['shopify_customer_id' => '0'])->save();

            $this->assertFalse(app(ConsentCeiling::class)->hasConsent($guest->fresh()));
            $this->assertNull(app(ConsentCeiling::class)->consentFor($guest->fresh(), CustomerConsent::CONTEXT_RECURRING));
        });
    }

    // === The attestation command ===

    public function test_the_preview_writes_nothing_and_names_who_would_be_charged(): void
    {
        $shop = $this->shop();
        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = $this->plan($shop);
            $plan->forceFill(['payment_method_id' => $this->card($shop)->id, 'next_charge_at' => now()->subDay()])->save();

            return $plan;
        });

        $this->artisan('lets:consents:attest-missing', ['--shop' => $shop->getKey()])
            ->expectsOutputToContain('1 will be CHARGED')
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();

        $this->assertSame(0, CustomerConsent::withoutGlobalScopes()->where('plan_id', $plan->id)->count());
    }

    public function test_commit_needs_a_reason(): void
    {
        $shop = $this->shop();
        Tenant::run($shop, fn () => $this->plan($shop));

        $this->artisan('lets:consents:attest-missing', ['--shop' => $shop->getKey(), '--commit' => true])
            ->assertFailed();

        $this->assertSame(0, CustomerConsent::withoutGlobalScopes()->count());
    }

    public function test_commit_records_an_attestation_that_says_it_was_the_merchant(): void
    {
        $shop = $this->shop();
        [$withCard, $bare] = Tenant::run($shop, function () use ($shop): array {
            $withCard = $this->plan($shop);
            $withCard->forceFill(['payment_method_id' => $this->card($shop)->id, 'consent_blocked_at' => now()])->save();

            return [$withCard, $this->plan($shop, 'bare@example.com')];
        });

        $this->artisan('lets:consents:attest-missing', [
            '--shop' => $shop->getKey(),
            '--with-card-only' => true,
            '--commit' => true,
            '--reason' => 'Agreed by phone',
            '--by' => 'admin:3',
        ])->assertSuccessful();

        Tenant::run($shop, function () use ($withCard, $bare): void {
            $row = CustomerConsent::query()->where('plan_id', $withCard->id)->sole();
            $this->assertSame(ConsentCeiling::SOURCE_MERCHANT_ATTESTED, $row->ceiling_source);
            $this->assertSame('admin:3', $row->approved_by);
            $this->assertSame('Agreed by phone', $row->approval_reason);
            $this->assertNull($withCard->fresh()->consent_blocked_at);

            // --with-card-only left the plan with no card alone.
            $this->assertSame(0, CustomerConsent::query()->where('plan_id', $bare->id)->count());
        });
    }

    public function test_cancelled_plans_are_never_attested(): void
    {
        $shop = $this->shop();
        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = $this->plan($shop);
            $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

            return $plan;
        });

        $this->artisan('lets:consents:attest-missing', ['--shop' => $shop->getKey(), '--commit' => true, '--reason' => 'x', '--by' => 'admin:3'])
            ->assertSuccessful();

        $this->assertSame(0, CustomerConsent::withoutGlobalScopes()->where('plan_id', $plan->id)->count());
    }

    /** The merchant edits the plan between the page and the click: the new terms are shown, nothing is stamped. */
    public function test_terms_that_changed_after_the_page_was_drawn_are_shown_again(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);
            $shown = $this->agreed($plan);

            $plan->forceFill(['installment_amount' => 150])->save();

            $this->post(route(CardUpdateLinks::ROUTE_START, ['token' => $this->tokenFrom($url)]), $shown)
                ->assertStatus(422)
                ->assertSee('rc-campaign-terms__error', false);

            $this->assertSame([], $this->generated);
            $this->assertNull($link->fresh()->terms_accepted_at);
        });
    }

    /**
     * A consent can arrive without the hook clearing the stop: a raw insert, a
     * firstOrCreate that found a row, a customer-wide row that starts matching.
     * The scheduler asks a stopped plan again once a day, so it is still collected.
     */
    public function test_a_stopped_plan_whose_consent_arrived_quietly_is_collected_within_a_day(): void
    {
        $shop = $this->shop();
        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = $this->plan($shop);
            $plan->forceFill([
                'payment_method_id' => $this->card($shop)->id,
                'next_charge_at' => now()->subDays(2),
                'consent_blocked_at' => now()->subHours(2),
            ])->save();

            return $plan;
        });

        // A consent written without any model event.
        \Illuminate\Support\Facades\DB::table('customer_consents')->insert([
            'shop_id' => $shop->getKey(), 'plan_id' => $plan->id, 'consent_context' => 'recurring',
            'customer_email' => $plan->customer_email, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotNull($plan->fresh()->consent_blocked_at);

        // Inside the day: left alone.
        Queue::fake();
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        Queue::assertNotPushed(ChargeJob::class);

        // A day after the last refusal: asked again, and the consent is there.
        $this->travel(23)->hours();
        Queue::fake();
        $this->artisan('payplus:dispatch-due')->assertSuccessful();
        Queue::assertPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->planId === (int) $plan->id);

        Tenant::run($shop, fn () => app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING));
        $this->assertSame(1, $this->charges);
        $this->assertNull($plan->fresh()->consent_blocked_at);
    }

    /** Still no consent at the daily re-check: re-stamped, and nothing new written on the Timeline. */
    public function test_the_daily_recheck_of_an_unconsented_plan_is_silent(): void
    {
        $shop = $this->shop();
        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = $this->plan($shop);
            $plan->forceFill(['payment_method_id' => $this->card($shop)->id, 'next_charge_at' => now()->subDay()])->save();
            app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

            return $plan->fresh();
        });
        $first = $plan->consent_blocked_at;

        $this->travel(25)->hours();
        Tenant::run($shop, fn () => app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING));

        $this->assertTrue($plan->fresh()->consent_blocked_at->greaterThan($first));
        $this->assertSame(1, ActivityEvent::withoutGlobalScopes()->where('plan_id', $plan->id)->where('kind', Timeline::KIND_CONSENT_MISSING)->count());
        $this->assertSame(0, $this->charges);
    }

    /** The hook lifts the stop by the consent's OWN shop, whatever tenant is (not) bound. */
    public function test_a_consent_written_with_no_shop_bound_still_lifts_the_stop(): void
    {
        $shop = $this->shop();
        $plan = Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = $this->plan($shop);
            $plan->forceFill(['consent_blocked_at' => now()])->save();

            return $plan;
        });

        Tenant::clear();
        $consent = new CustomerConsent;
        $consent->forceFill([
            'shop_id' => $shop->getKey(), 'plan_id' => $plan->id, 'consent_context' => 'recurring',
            'customer_email' => $plan->customer_email,
        ])->save();

        $this->assertNull($plan->fresh()->consent_blocked_at);
    }

    // === Fixtures ===

    /**
     * The form as the page posts it after the tick: the box, and the fingerprint
     * of the terms it showed.
     *
     * @return array<string, string>
     */
    private function agreed(InstallmentPlan $plan): array
    {
        return [
            CardUpdateTerms::FIELD => '1',
            CardUpdateTerms::FINGERPRINT_FIELD => CardUpdateTerms::fingerprint(CardUpdateTerms::for($plan->fresh())),
        ];
    }

    private function shop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'consent.example.com',
            'name' => 'Consent Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'wc_shop_token' => 'wc-token-consent',
            'callback_token' => 'cb-token-consent',
        ]);
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        return $shop->fresh();
    }

    private function plan(Shop $shop, string $email = 'gideon@example.com'): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'public_id' => 'PLN-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'customer_name' => 'Gideon Subscriber',
            'customer_email' => $email,
            'shopify_customer_id' => 'cust-'.$email,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
        ])->save();

        return $plan->fresh();
    }

    private function consent(Shop $shop, InstallmentPlan $plan): CustomerConsent
    {
        return CustomerConsent::query()->create([
            'shop_id' => (int) $shop->getKey(),
            'plan_id' => $plan->getKey(),
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'customer_email' => $plan->customer_email,
            'accepted_at' => now(),
        ]);
    }

    private function card(Shop $shop): InstallmentPaymentMethod
    {
        return InstallmentPaymentMethod::query()->create([
            'shopify_customer_id' => 'cust-card',
            'payplus_card_token_uid' => 'tok-'.uniqid(),
            'card_brand' => 'visa',
            'card_last_four' => '5674',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);
    }

    /** @return array<string, mixed> */
    private function payplusBody(InstallmentPlan $plan, CardUpdateLink $link, array $card): array
    {
        return [
            'transaction_type' => 'Charge',
            'transaction' => [
                'uid' => 'txn-1',
                'payment_page_request_uid' => 'pr-1',
                'status_code' => '000',
                'more_info' => CardUpdateService::moreInfoFor($plan, $link),
            ],
            'data' => ['customer_uid' => 'c1', 'card_information' => $card],
        ];
    }

    private function fakeGateway(): void
    {
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private object $test) {}

            public function generateLink(array $payload): GatewayResult
            {
                $this->test->generated[] = $payload;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success'],
                    'data' => ['payment_page_link' => 'https://payplus.example/page/abc'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->charges++;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$idempotencyKey, 'approval_number' => 'A1']],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    private function tokenFrom(string $url): string
    {
        return (string) basename(parse_url($url, PHP_URL_PATH) ?: '');
    }
}
