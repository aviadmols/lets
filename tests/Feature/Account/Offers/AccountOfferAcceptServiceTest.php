<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Mail\ChargeFailedMail;
use App\Mail\PlanCancelledMail;
use App\Models\AccountOfferTarget;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The money path of an account offer: one click, one charge, on the card the
 * shopper already saved.
 *
 * The fixture is deliberately an IMPORTED member — shopify_customer_id is the
 * UUID their old system gave them and customer_id is null — because that is the
 * shape in which every shortcut fails. A new plan built from the visitor's own id
 * would be un-chargeable; a query that compares customer_id to that UUID would
 * abort on Postgres while passing here on sqlite.
 *
 * What must hold, in order of how much it would cost to get wrong:
 *   - a failed charge leaves the EXISTING subscription untouched;
 *   - a double-click produces ONE subscription and ONE charge;
 *   - the consent row exists before the money moves, keyed to the NEW plan;
 *   - a switch sends no cancellation email and no "we will retry" email;
 *   - a shop with live charging off refuses, rather than bypassing the wall.
 */
final class AccountOfferAcceptServiceTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    public int $payplusCalls = 0;

    public bool $payplusDeclines = false;

    /** Consent rows for the NEW plan at the instant PayPlus was entered. */
    public ?int $consentRowsAtChargeTime = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->payplusCalls = 0;
        $this->payplusDeclines = false;
        $this->consentRowsAtChargeTime = null;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private AccountOfferAcceptServiceTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->payplusCalls;

                // THE LAW, probed at the instant it matters: a saved-token charge
                // never runs without a stored consent row. Counted per-PLAN, not
                // per-shop — the member's migrated consent would satisfy the gate
                // on identity alone, so only a row bound to the NEW plan proves
                // that THIS subscription was agreed to before it was charged.
                $this->test->consentRowsAtChargeTime ??= CustomerConsent::withoutGlobalScopes()
                    ->where('consent_context', CustomerConsent::CONTEXT_RECURRING)
                    ->whereNotNull('plan_id')
                    ->count();

                if ($this->test->payplusDeclines) {
                    return GatewayResult::fromResponse([
                        'results' => ['status' => 'error', 'code' => 51, 'description' => 'Insufficient funds'],
                    ]);
                }

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n, 'approval_number' => 'A'.$n]],
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

    // === Immediate switch ===

    public function test_an_immediate_switch_charges_once_activates_the_new_plan_and_closes_the_old(): void
    {
        $this->inShop(function (Shop $shop): void {
            [$offer, $source, $visitor] = $this->scenario();

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(1, $this->payplusCalls);

            $new = $outcome->plan->fresh();
            $this->assertSame(PlanStatus::ACTIVE, $new->status);
            $this->assertSame(PlanStatus::CANCELLED, $source->fresh()->status);

            // Identity + card came from the SOURCE, not from the visitor.
            $this->assertSame(self::MEMBER_REF, $new->shopify_customer_id);
            $this->assertNull($new->customer_id);
            $this->assertSame($source->payment_method_id, $new->payment_method_id);

            // The money truth: one succeeded ledger row on the deterministic key.
            $ledger = PaymentLedger::where('plan_id', $new->getKey())
                ->where('status', LedgerStatus::SUCCEEDED->value)
                ->sole();
            $this->assertSame(
                sprintf('recurring:%d:%d:%s', $shop->getKey(), $new->getKey(), now()->toDateString()),
                $ledger->idempotency_key,
            );
            $this->assertSame(49.0, round((float) $ledger->amount, 2));

            // Consent: keyed to the NEW plan, and already there when money moved.
            $consent = CustomerConsent::where('plan_id', $new->getKey())->sole();
            $this->assertSame(CustomerConsent::CONTEXT_RECURRING, $consent->consent_context);
            $this->assertSame(self::MEMBER_REF, $consent->shopify_customer_id);
            $this->assertSame(1, $this->consentRowsAtChargeTime, 'Consent must precede the charge.');

            // The switch is recorded on BOTH plans, as a switch and not as a churn.
            foreach ([$new->getKey(), $source->getKey()] as $planId) {
                $this->assertDatabaseHas('activity_events', [
                    'shop_id' => $shop->getKey(),
                    'plan_id' => $planId,
                    'kind' => Timeline::KIND_PLAN_SWITCHED,
                ]);
                $this->assertDatabaseHas('activity_events', [
                    'plan_id' => $planId,
                    'kind' => Timeline::KIND_ACCOUNT_OFFER_ACCEPTED,
                ]);
            }

            $this->assertSame(1, (int) $offer->fresh()->accepted_count);
            $this->assertNotNull($offer->fresh()->last_accepted_at);

            // Silence toward the shopper: they upgraded, nothing of theirs failed
            // and nothing of theirs was cancelled from where they stand.
            Mail::assertNotSent(PlanCancelledMail::class);
            Mail::assertNotSent(ChargeFailedMail::class);
        });
    }

    public function test_a_declined_card_leaves_the_existing_subscription_exactly_as_it_was(): void
    {
        $this->inShop(function (Shop $shop): void {
            [$offer, $source, $visitor] = $this->scenario();
            $this->payplusDeclines = true;

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_CHARGE_FAILED, $outcome->result);
            $this->assertSame(1, $this->payplusCalls);

            // The one thing that must never move.
            $this->assertSame(PlanStatus::ACTIVE, $source->fresh()->status);
            $this->assertNotNull($source->fresh()->next_charge_at);

            // The attempt's plan is closed and the scheduler will never see it.
            $attempt = InstallmentPlan::where('id', '!=', $source->getKey())->sole();
            $this->assertSame(PlanStatus::CANCELLED, $attempt->status);
            $this->assertNull($attempt->next_charge_at);

            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $source->getKey(),
                'kind' => Timeline::KIND_ACCOUNT_OFFER_CHARGE_FAILED,
            ]);
            $this->assertSame(0, (int) $offer->fresh()->accepted_count);

            // A one-shot attempt has no retry to promise, so no mail may claim one.
            Mail::assertNotSent(ChargeFailedMail::class);
            Mail::assertNotSent(PlanCancelledMail::class);
        });
    }

    public function test_a_double_click_produces_one_subscription_and_one_charge(): void
    {
        $this->inShop(function (Shop $shop): void {
            [$offer, $source, $visitor] = $this->scenario();
            $service = $this->service();

            $first = $service->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);
            // The second click arrives after the first finished: the source is now
            // cancelled, so it is no longer a subscription this offer can come from.
            $second = $service->accept($visitor, $source->fresh(), (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $first->result);
            $this->assertSame(AccountOfferOutcome::RESULT_NOT_ELIGIBLE, $second->result);

            $this->assertSame(1, $this->payplusCalls);
            $this->assertSame(2, InstallmentPlan::count(), 'One source + one new plan, and no more.');
            $this->assertSame(1, (int) $offer->fresh()->accepted_count);
        });
    }

    public function test_a_price_that_moved_under_the_click_refuses_rather_than_charging_either_number(): void
    {
        $this->inShop(function (): void {
            [$offer, $source, $visitor] = $this->scenario();

            // The card said 39; the template says 49.
            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 39.0);

            $this->assertSame(AccountOfferOutcome::RESULT_CHANGED, $outcome->result);
            $this->assertSame(0, $this->payplusCalls);
            $this->assertSame(1, InstallmentPlan::count());
        });
    }

    // === Period end ===

    public function test_a_period_end_switch_takes_no_money_today_and_bills_on_the_old_renewal_date(): void
    {
        $this->inShop(function (Shop $shop): void {
            [$offer, $source, $visitor] = $this->scenario([
                'replace_timing' => AccountOfferTarget::TIMING_PERIOD_END,
            ]);

            $renewal = $source->next_charge_at->copy()->startOfDay();

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(0, $this->payplusCalls, 'Nothing is charged today.');

            $new = $outcome->plan->fresh();
            $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $new->status);
            $this->assertSame($renewal->toDateString(), $new->next_charge_at->toDateString());

            // The old subscription ends NOW — no proration, no double billing, and
            // no cancellation notice for a shopper who did not cancel anything.
            $this->assertSame(PlanStatus::CANCELLED, $source->fresh()->status);
            Mail::assertNotSent(PlanCancelledMail::class);

            // The ordinary scheduler bills it. No second mechanism.
            $this->travelTo($renewal->copy()->addDay());
            $this->artisan('payplus:dispatch-due')->assertSuccessful();

            $this->assertSame(1, $this->payplusCalls);
            $this->assertSame(PlanStatus::ACTIVE, $new->fresh()->status);
        });
    }

    public function test_a_renewal_date_that_already_passed_becomes_today_rather_than_the_past(): void
    {
        $this->inShop(function (): void {
            // An imported member whose renewal fell due during the migration hold.
            [$offer, $source, $visitor] = $this->scenario(
                ['replace_timing' => AccountOfferTarget::TIMING_PERIOD_END],
                ['next_charge_at' => now()->subDays(30)->startOfDay()],
            );

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(
                now()->startOfDay()->toDateString(),
                $outcome->plan->fresh()->next_charge_at->toDateString(),
            );
        });
    }

    // === Refusals ===

    public function test_a_shop_with_live_charging_off_refuses_an_immediate_offer_and_creates_nothing(): void
    {
        $this->inShop(function (): void {
            [$offer, $source, $visitor] = $this->scenario();
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_UNAVAILABLE, $outcome->result);
            $this->assertSame(0, $this->payplusCalls);
            $this->assertSame(1, InstallmentPlan::count(), 'The wall is never bypassed.');
        });
    }

    public function test_another_shops_offer_is_answered_as_if_it_did_not_exist(): void
    {
        $shopA = $this->makeShop('offers-a.example.com');
        $shopB = $this->makeShop('offers-b.example.com');

        $foreignOfferId = Tenant::run($shopB, function (): string {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'B membership', 49.0);

            return (string) $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY))->getKey();
        });

        Tenant::run($shopA, function () use ($shopA, $foreignOfferId): void {
            $this->makeProduct(self::PRODUCT_MONTHLY, 'A membership', 49.0);
            $source = $this->makeSourcePlan();
            $visitor = $this->visitor($shopA);

            $outcome = $this->service()->accept($visitor, $source, $foreignOfferId);

            $this->assertSame(AccountOfferOutcome::RESULT_INVALID, $outcome->result);
            $this->assertSame(0, $this->payplusCalls);
        });
    }

    public function test_a_non_numeric_offer_id_never_reaches_the_query(): void
    {
        $this->inShop(function (): void {
            [, $source, $visitor] = $this->scenario();

            // Postgres aborts the whole statement when a bigint key is compared to
            // a non-numeric string; sqlite shrugs. The guard is in front of both.
            foreach (['abc', self::MEMBER_REF, '', '1; drop table account_offers'] as $bad) {
                $this->assertSame(
                    AccountOfferOutcome::RESULT_INVALID,
                    $this->service()->accept($visitor, $source, $bad)->result,
                );
            }

            $this->assertSame(1, InstallmentPlan::count());
        });
    }

    // === Add mode ===

    public function test_an_add_offer_keeps_the_existing_subscription_alive(): void
    {
        $this->inShop(function (): void {
            [$offer, $source, $visitor] = $this->scenario([
                'mode' => AccountOfferTarget::MODE_ADD,
                'replace_timing' => null,
            ]);

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), '', 49.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(1, $this->payplusCalls);
            $this->assertSame(PlanStatus::ACTIVE, $source->fresh()->status, 'An add ends nothing.');
            $this->assertSame(PlanStatus::ACTIVE, $outcome->plan->fresh()->status);
        });
    }

    // === Helpers ===

    private function inShop(callable $callback): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, fn () => $callback($shop));
    }

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

    /**
     * The pilot shop's setup: a yearly member, offered the 2675 monthly plan.
     *
     * @param  array<string, mixed>  $offerAttributes
     * @param  array<string, mixed>  $planAttributes
     * @return array{0: AccountOffer, 1: InstallmentPlan, 2: AccountVisitor}
     */
    private function scenario(array $offerAttributes = [], array $planAttributes = []): array
    {
        $shop = Tenant::current();
        $product = Product::where('external_id', self::PRODUCT_MONTHLY)->first()
            ?? $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);

        $offer = $this->makeOffer(
            $this->makeTemplate($product, BillingFrequency::MONTHLY),
            $offerAttributes,
        );

        return [$offer, $this->makeSourcePlan($planAttributes), $this->visitor($shop)];
    }
}
