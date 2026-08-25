<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Domain\Account\Offers\AccountOfferPresenter;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Domain\Account\Offers\ReplaceProration;
use App\Filament\Resources\AccountOfferResource;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The PRORATED subscription switch: the difference now, the full price from the
 * old renewal date.
 *
 * The two numbers that must never be wrong: the amount charged today (the
 * remainder-of-period difference, re-derived server-side) and the date the full
 * price starts (the OLD renewal — the orchestrator's advance-from-today must be
 * undone). And the one number that must never move: installment_amount stays the
 * full per-cycle price, whatever the first charge was.
 */
final class ReplaceProrationTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    /** Amounts the fake gateway was asked to charge, in order. */
    public array $chargedAmounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->chargedAmounts = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ReplaceProrationTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->chargedAmounts[] = $amount;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.count($this->test->chargedAmounts)]],
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

    // === The formula ===

    public function test_the_formula_charges_the_remaining_share_of_the_difference(): void
    {
        // Fixed clock: Sep 1 → renewal Sep 11, monthly cycle Aug 11 → Sep 11
        // (31 days), 10 remaining. Difference 49 − 30 = 19 → 19 × 10/31 = 6.13.
        $this->travelTo('2026-09-01 08:00:00');

        $shop = $this->makeShop('proration-math.example.com');

        Tenant::run($shop, function (): void {
            $source = $this->makeSourcePlan([
                'installment_amount' => 30,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'next_charge_at' => '2026-09-11 00:00:00',
            ]);

            $target = $this->proratedTarget();

            $this->assertSame(6.13, ReplaceProration::dueNow($target, $source, 49.0));
        });
    }

    public function test_a_downgrade_and_a_missing_renewal_prorate_to_zero_and_other_timings_to_null(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $shop = $this->makeShop('proration-edges.example.com');

        Tenant::run($shop, function (): void {
            $target = $this->proratedTarget();

            // Downgrade: new price below the current one → nothing to charge.
            $source = $this->makeSourcePlan([
                'installment_amount' => 80,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'next_charge_at' => '2026-09-11 00:00:00',
            ]);
            $this->assertSame(0.0, ReplaceProration::dueNow($target, $source, 49.0));

            // No renewal date → nothing to prorate against.
            $bare = $this->makeSourcePlan(['next_charge_at' => null]);
            $this->assertSame(0.0, ReplaceProration::dueNow($target, $bare, 49.0));

            // A non-prorated target does not prorate at all.
            $immediate = $this->immediateTarget();
            $this->assertNull(ReplaceProration::dueNow($immediate, $source, 49.0));
        });
    }

    // === The accept path ===

    public function test_a_prorated_switch_charges_the_difference_and_keeps_the_old_renewal_date(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $shop = $this->makeShop('proration-accept.example.com');

        Tenant::run($shop, function (): void {
            [$offer, $source, $visitor] = $this->proratedScenario();

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);

            // Exactly the difference moved — not the full price.
            $this->assertSame([6.13], $this->chargedAmounts);

            $new = $outcome->plan->fresh();
            $this->assertSame(PlanStatus::ACTIVE, $new->status);
            $this->assertSame(PlanStatus::CANCELLED, $source->fresh()->status);

            // The full per-cycle price is untouched by the prorated first charge…
            $this->assertSame(49.0, round((float) $new->installment_amount, 2));
            // …and the full price resumes on the OLD renewal date, not today+1mo.
            $this->assertSame('2026-09-11', $new->next_charge_at->toDateString());

            // The acceptance record carries the one-off.
            $this->assertSame(6.13, (float) $new->accountOfferMeta()[InstallmentPlan::META_OFFER_FIRST_CHARGE]);

            // The consent names the one-off beside the per-cycle price.
            $consent = CustomerConsent::query()
                ->where('plan_id', $new->getKey())
                ->first();
            $this->assertStringContainsString('6.13', (string) $consent->billing_amount_description);
            $this->assertStringContainsString('49', (string) $consent->billing_amount_description);
        });
    }

    public function test_the_next_cycle_bills_the_full_price_on_the_old_renewal_date(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $shop = $this->makeShop('proration-cycle.example.com');

        $newPlanId = Tenant::run($shop, function (): int {
            [$offer, $source, $visitor] = $this->proratedScenario();
            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            return (int) $outcome->plan->getKey();
        });

        // The renewal day arrives; the ordinary scheduler picks the plan up.
        $this->travelTo('2026-09-11 09:00:00');
        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        $this->assertSame([6.13, 49.0], $this->chargedAmounts, 'the difference, then the full price');

        Tenant::run($shop, function () use ($newPlanId): void {
            $plan = InstallmentPlan::query()->find($newPlanId);
            $this->assertSame('2026-10-11', $plan->next_charge_at->toDateString(), 'the cadence continues from the old schedule');
        });
    }

    public function test_a_downgrade_switches_with_no_charge_and_waits_for_the_renewal(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $shop = $this->makeShop('proration-downgrade.example.com');

        Tenant::run($shop, function (): void {
            [$offer, $source, $visitor] = $this->proratedScenario(sourceAmount: 80.0);

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame([], $this->chargedAmounts, 'nothing moved today');

            $new = $outcome->plan->fresh();
            $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $new->status);
            $this->assertSame('2026-09-11', $new->next_charge_at->toDateString());
            $this->assertSame(PlanStatus::CANCELLED, $source->fresh()->status, 'the switch itself completed');
        });
    }

    /** The behaviour that shipped first must not move: full price now, cycle restarts. */
    public function test_an_immediate_switch_is_unchanged(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $shop = $this->makeShop('proration-regress.example.com');

        Tenant::run($shop, function (): void {
            [$offer, $source, $visitor] = $this->scenarioWith(['replace_timing' => AccountOfferTarget::TIMING_IMMEDIATE]);

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame([49.0], $this->chargedAmounts);
            $this->assertSame('2026-10-01', $outcome->plan->fresh()->next_charge_at->toDateString());
        });
    }

    // === What the shopper reads ===

    public function test_the_card_shows_the_same_due_now_the_charge_will_take(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $shop = $this->makeShop('proration-card.example.com');

        Tenant::run($shop, function (): void {
            [$offer, $source] = $this->proratedScenario();
            $target = $offer->orderedTargets()[0];
            $quote = AccountOfferQuote::forTarget($target, $source, Tenant::current());

            $payload = app(AccountOfferPresenter::class)->present(
                $offer,
                [[$target, $quote]],
                (string) $source->public_id,
                $source,
            );

            $card = $payload['targets'][0];

            $this->assertSame(6.13, $card['due_now']);
            $this->assertStringContainsString('6.13', $card['due_now_display']);
            // The disclosure says both numbers and the date.
            $this->assertStringContainsString('6.13', $card['disclosure']);
            $this->assertStringContainsString('49', $card['disclosure']);
            $this->assertStringContainsString('2026-09-11', $card['disclosure']);
            $this->assertSame('2026-09-11', $card['first_charge_at']);
        });
    }

    // === The admin form ===

    public function test_the_third_timing_value_survives_normalization(): void
    {
        $row = AccountOfferResource::normalizeTarget([
            'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => AccountOfferTarget::TIMING_PRORATED,
            'product_subscription_plan_id' => 1,
        ], 1);

        $this->assertSame(AccountOfferTarget::TIMING_PRORATED, $row['replace_timing']);

        // Junk still falls back to today's behaviour.
        $junk = AccountOfferResource::normalizeTarget([
            'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => 'someday',
            'product_subscription_plan_id' => 1,
        ], 1);

        $this->assertSame(AccountOfferTarget::TIMING_IMMEDIATE, $junk['replace_timing']);
    }

    // === helpers ===

    private function service(): AccountOfferAcceptService
    {
        return app(AccountOfferAcceptService::class);
    }

    private function visitor(Shop $shop): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: self::MEMBER_REF,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: self::MEMBER_EMAIL,
        );
    }

    /** A throw-away prorated target for the pure-formula tests. */
    private function proratedTarget(): AccountOfferTarget
    {
        return new AccountOfferTarget([
            'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => AccountOfferTarget::TIMING_PRORATED,
        ]);
    }

    private function immediateTarget(): AccountOfferTarget
    {
        return new AccountOfferTarget([
            'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => AccountOfferTarget::TIMING_IMMEDIATE,
        ]);
    }

    /**
     * A 49 ₪/month offer, prorated, over a 30 ₪/month source renewing Sep 11.
     *
     * @return array{0: AccountOffer, 1: InstallmentPlan, 2: AccountVisitor}
     */
    private function proratedScenario(float $sourceAmount = 30.0): array
    {
        return $this->scenarioWith(
            ['replace_timing' => AccountOfferTarget::TIMING_PRORATED],
            $sourceAmount,
        );
    }

    /** @return array{0: AccountOffer, 1: InstallmentPlan, 2: AccountVisitor} */
    private function scenarioWith(array $offerAttributes, float $sourceAmount = 30.0): array
    {
        $shop = Tenant::current();

        $product = Product::where('external_id', self::PRODUCT_MONTHLY)->first()
            ?? $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);

        $offer = $this->makeOffer(
            $this->makeTemplate($product, BillingFrequency::MONTHLY),
            array_merge(['mode' => AccountOfferTarget::MODE_REPLACE], $offerAttributes),
        );

        $source = $this->makeSourcePlan([
            'installment_amount' => $sourceAmount,
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'next_charge_at' => '2026-09-11 00:00:00',
        ]);

        return [$offer, $source, $this->visitor($shop)];
    }
}
