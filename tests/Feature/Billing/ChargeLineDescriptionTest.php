<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ChargeLineDescription;
use App\Filament\Pages\ManageBillingSettings;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WHAT THE CUSTOMER'S RECEIPT SAYS.
 *
 * A PayPlus terminal set to auto-issue a document per transaction prints the line
 * from the `items` we send, and prints `more_info` when we send none — which in
 * this app is the idempotency key. The reference engine carries the scar: a real
 * customer received a חשבונית מס קבלה whose product name read
 * "payplus_installment_plan_41_payment_2". The port dropped that block; these
 * tests are the wall that stops it being dropped again.
 *
 * Everything else here is about the merchant owning the sentence — placeholders
 * that resolve, a template that cannot execute, and a line that is never empty,
 * because empty is exactly what makes PayPlus print the key.
 */
final class ChargeLineDescriptionTest extends TestCase
{
    use RefreshDatabase;

    /** The meta the gateway was handed, per call. */
    public array $chargeMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->chargeMeta = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ChargeLineDescriptionTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->chargeMeta[] = $meta;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-1', 'approval_number' => 'A1']],
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

    // === The renderer ===

    /** The default sentence names the product, which is the whole point. */
    public function test_the_default_template_names_the_product(): void
    {
        [$shop, $plan] = $this->plan('line-default.example.com');

        $line = Tenant::run($shop, fn (): string => $this->resolver()->for($plan, PaymentType::RECURRING, 3));

        $this->assertStringContainsString('Club membership', $line);
    }

    /** Every placeholder the form advertises actually resolves. */
    public function test_every_advertised_placeholder_resolves(): void
    {
        [$shop, $plan] = $this->plan('line-placeholders.example.com');

        $template = implode(' | ', array_keys(ChargeLineDescription::PLACEHOLDERS));

        $line = Tenant::run($shop, fn (): string => $this->resolver()->render($template, $plan, 4));

        $this->assertStringContainsString('Club membership', $line, '{plan}');
        $this->assertStringContainsString('4', $line, '{cycle}');
        $this->assertStringContainsString('Monthly', $line, '{frequency}');
        $this->assertStringContainsString('Dana Levi', $line, '{customer}');
        $this->assertStringContainsString((string) $plan->public_id, $line, '{id}');

        // Nothing was left unsubstituted — a raw "{plan}" on a tax receipt is the
        // failure this test is really guarding against.
        foreach (array_keys(ChargeLineDescription::PLACEHOLDERS) as $token) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    /** The merchant's own sentence wins — the whole feature in one assertion. */
    public function test_the_merchants_template_is_what_gets_sent(): void
    {
        [$shop, $plan] = $this->plan('line-merchant.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()
                ->forceFill(['recurring_charge_description' => 'הזמנת מנוי - {plan}'])
                ->save();

            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $this->assertSame('הזמנת מנוי - Club membership', $this->chargeMeta[0]['item_name'] ?? null);
    }

    /**
     * A merchant template is DATA, never a program. strtr, not a template engine —
     * the same rule the email bodies live under.
     */
    public function test_a_template_is_substituted_and_never_executed(): void
    {
        [$shop, $plan] = $this->plan('line-blade.example.com');

        $line = Tenant::run($shop, fn (): string => $this->resolver()->render(
            '{{ 2 + 2 }} @php echo 9; @endphp {plan}',
            $plan,
            1,
        ));

        $this->assertStringContainsString('{{ 2 + 2 }}', $line, 'left verbatim');
        $this->assertStringNotContainsString('4', $line, 'nothing was evaluated');
        $this->assertStringContainsString('Club membership', $line);
    }

    /** One line. A newline in a template is a broken printed document. */
    public function test_the_line_is_flattened_and_clamped(): void
    {
        [$shop, $plan] = $this->plan('line-flat.example.com');

        $line = Tenant::run($shop, fn (): string => $this->resolver()->render(
            "first\nsecond\tthird ".str_repeat('x', 200),
            $plan,
            1,
        ));

        $this->assertStringNotContainsString("\n", $line);
        $this->assertStringNotContainsString("\t", $line);
        $this->assertLessThanOrEqual(ChargeLineDescription::MAX_LENGTH, mb_strlen($line));
    }

    /**
     * NEVER EMPTY. An empty line is what makes PayPlus fall back to more_info and
     * print the idempotency key, so a template that resolves to nothing has to
     * degrade to the plan's reference instead.
     */
    public function test_a_template_that_collapses_to_nothing_still_sends_a_line(): void
    {
        [$shop, $plan] = $this->plan('line-empty.example.com');

        $line = Tenant::run($shop, fn (): string => $this->resolver()->render('   ', $plan, 1));

        $this->assertNotSame('', $line);
        $this->assertStringContainsString((string) $plan->public_id, $line);
    }

    /** An imported member whose product is in no catalog still gets a readable line. */
    public function test_a_plan_with_no_product_falls_back_to_its_reference(): void
    {
        [$shop, $plan] = $this->plan('line-noproduct.example.com');

        Tenant::run($shop, static function () use ($plan): void {
            $plan->forceFill(['meta' => [], 'external_product_id' => null])->save();
        });

        $line = Tenant::run($shop, fn (): string => $this->resolver()->for($plan->fresh(), PaymentType::RECURRING, 2));

        $this->assertStringContainsString((string) $plan->public_id, $line);
        $this->assertStringNotContainsString('{plan}', $line);
    }

    /** A deposit gets a written line too — it is the same tax document. */
    public function test_a_deposit_charge_still_carries_a_human_line(): void
    {
        [$shop, $plan] = $this->plan('line-deposit.example.com');

        $line = Tenant::run($shop, fn (): string => $this->resolver()->for($plan, PaymentType::DEPOSIT, 1));

        $this->assertStringContainsString('Club membership', $line);
        $this->assertStringNotContainsString('idempotency', $line);
    }

    /** Per shop. One merchant's wording can never label another's charge. */
    public function test_the_template_is_read_from_the_plans_own_shop(): void
    {
        [$mine, $minePlan] = $this->plan('line-mine.example.com');
        [$theirs] = $this->plan('line-theirs.example.com');

        Tenant::run($theirs, static function (): void {
            MerchantBillingSettings::current()
                ->forceFill(['recurring_charge_description' => 'THEIR WORDING {plan}'])
                ->save();
        });

        // Resolved while the OTHER shop is bound — the resolver must still read the
        // plan's own shop, which is the shape a queued job runs in.
        $line = Tenant::run($theirs, fn (): string => $this->resolver()->for($minePlan, PaymentType::RECURRING, 1));

        $this->assertStringNotContainsString('THEIR WORDING', $line);
    }

    // === The gateway payload ===

    /**
     * THE REGRESSION WALL. `items` carries the line; `more_info` stays the
     * idempotency key, because StuckChargeResolver looks a lost transaction up by
     * it. Trading one for the other would swap a reconciliation tool for a label.
     */
    public function test_the_charge_payload_carries_items_and_keeps_more_info(): void
    {
        $sent = [];

        PayPlusGatewayFactory::clearFake();
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response([
                'results' => ['status' => 'success', 'code' => 0],
                'data' => ['transaction' => ['uid' => 'txn-1', 'approval_number' => 'A1']],
            ]);
        });

        [$shop, $plan] = $this->plan('line-payload.example.com');

        Tenant::run($shop, function () use ($plan): void {
            MerchantBillingSettings::current()
                ->forceFill(['recurring_charge_description' => 'Subscription — {plan}'])
                ->save();

            app(ChargeOrchestrator::class)->charge((int) $plan->id, PaymentType::RECURRING);
        });

        $charge = collect($sent)->first(fn (array $body): bool => isset($body['use_token']));

        $this->assertNotNull($charge, 'the charge request was made');
        $this->assertSame('Subscription — Club membership', $charge['items'][0]['name'] ?? null);
        $this->assertSame(1, $charge['items'][0]['quantity'] ?? null);
        $this->assertSame(39.0, $charge['items'][0]['price'] ?? null);
        $this->assertNotSame(
            $charge['items'][0]['name'],
            $charge['more_info'] ?? null,
            'more_info is still the correlation key, not the label',
        );
    }

    // === The screen ===

    public function test_the_settings_screen_saves_and_previews_the_template(): void
    {
        [$shop, $plan] = $this->plan('line-screen.example.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $component = Livewire::test(ManageBillingSettings::class)
            // Untouched shows EMPTY, so a merchant can tell "I never set this" from
            // "I set it to exactly the default".
            ->assertSet('data.recurring_charge_description', null)
            ->set('data.recurring_charge_description', 'דמי חבר — {plan} (חיוב {cycle})')
            ->call('save')
            ->assertHasNoErrors();

        $saved = MerchantBillingSettings::current()->fresh();
        $this->assertSame('דמי חבר — {plan} (חיוב {cycle})', $saved->recurring_charge_description);

        // And the preview renders through the REAL resolver, so it cannot promise a
        // sentence the charge would not send.
        $component->assertSee('דמי חבר — Club membership (חיוב 2)');
    }

    /** Blank means "use the default", stored as NULL — never as today's wording. */
    public function test_clearing_the_template_restores_the_default(): void
    {
        [$shop] = $this->plan('line-clear.example.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        MerchantBillingSettings::current()->forceFill(['recurring_charge_description' => 'Mine {plan}'])->save();

        Livewire::test(ManageBillingSettings::class)
            ->set('data.recurring_charge_description', '   ')
            ->call('save');

        $saved = MerchantBillingSettings::current()->fresh();
        $this->assertNull($saved->recurring_charge_description);
        $this->assertSame(__('billing.settings.recurring.description_default'), $saved->recurringChargeDescription());
    }

    // === Fixtures ===

    private function resolver(): ChargeLineDescription
    {
        return app(ChargeLineDescription::class);
    }

    /** @return array{0: Shop, 1: InstallmentPlan} */
    private function plan(string $domain): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return [$shop, Tenant::run($shop, static function (): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-1',
                'payplus_customer_uid' => 'cust-1',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            foreach ([CustomerConsent::CONTEXT_RECURRING, CustomerConsent::CONTEXT_INSTALLMENTS] as $context) {
                CustomerConsent::create([
                    'shopify_customer_id' => 'cust-1',
                    'consent_context' => $context,
                    'accepted_at' => now(),
                ]);
            }

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'cust-1',
                'customer_name' => 'Dana Levi',
                'installment_amount' => 39.00,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
                'next_charge_at' => now(),
                'meta' => ['item_title' => 'Club membership'],
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        })];
    }
}
