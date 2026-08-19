<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\Offers\AccountOfferEligibility;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Models\AccountOffer;
use App\Models\InstallmentPaymentMethod;
use App\Models\MerchantBillingSettings;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who is shown an offer, and from which of their subscriptions.
 *
 * Every case here is a way to show the WRONG person an offer, which in this
 * feature means a saved card charged for a plan somebody did not want. The
 * driving one is the pilot shop's: 1,154 imported YEARLY members on products
 * 2666/2675, offered the monthly plan and nobody else.
 */
final class AccountOfferEligibilityTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    /** The offered product, made once per test (the catalog key is unique). */
    private ?Product $target = null;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_yearly_only_offer_reaches_yearly_members_and_nobody_else(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer(['audience' => ['frequencies' => [BillingFrequency::YEARLY->value]]]);

            $yearly = $this->makeSourcePlan();
            $monthlyMember = $this->makeSourcePlan(['billing_frequency' => BillingFrequency::MONTHLY->value]);

            $eligibility = new AccountOfferEligibility;

            $this->assertTrue($eligibility->matches($offer, $yearly, $quote));
            $this->assertFalse($eligibility->matches($offer, $monthlyMember, $quote));
        });
    }

    public function test_the_product_filter_narrows_to_the_named_products(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer(['audience' => ['product_ids' => [self::PRODUCT_YEARLY]]]);

            $onTarget = $this->makeSourcePlan();
            $elsewhere = $this->makeSourcePlan(['external_product_id' => '4242']);

            $eligibility = new AccountOfferEligibility;

            $this->assertTrue($eligibility->matches($offer, $onTarget, $quote));
            $this->assertFalse($eligibility->matches($offer, $elsewhere, $quote));
        });
    }

    public function test_a_paused_subscriber_is_still_a_subscriber_but_a_cancelled_one_is_not(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer();

            $paused = $this->makeSourcePlan(['status' => PlanStatus::PAUSED->value]);
            $cancelled = $this->makeSourcePlan(['status' => PlanStatus::CANCELLED->value]);

            $eligibility = new AccountOfferEligibility;

            // Default audience is [active, paused]: somebody who will pay again the
            // moment they resume is exactly who a switch offer is for.
            $this->assertTrue($eligibility->matches($offer, $paused, $quote));
            $this->assertFalse($eligibility->matches($offer, $cancelled, $quote));
        });
    }

    public function test_the_merchants_date_window_opens_and_closes_the_offer(): void
    {
        $this->inShop(function (): void {
            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current();

            [$live] = $this->monthlyOffer();
            $this->assertTrue($eligibility->isOpen($live, now(), $settings));

            [$notYet] = $this->monthlyOffer(['starts_at' => now()->addDays(3)]);
            $this->assertFalse($eligibility->isOpen($notYet, now(), $settings));

            [$over] = $this->monthlyOffer(['ends_at' => now()->subDay()]);
            $this->assertFalse($eligibility->isOpen($over, now(), $settings));

            [$draft] = $this->monthlyOffer(['status' => AccountOffer::STATUS_DRAFT]);
            $this->assertFalse($eligibility->isOpen($draft, now(), $settings));
        });
    }

    public function test_no_card_or_a_dead_card_is_the_same_answer_as_no_offer(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer();
            $eligibility = new AccountOfferEligibility;

            $noCard = $this->makeSourcePlan(withCard: false);
            $this->assertFalse($eligibility->matches($offer, $noCard, $quote));

            // A revoked token would fail at the gateway, and the shopper would have
            // been told their subscription changed when it did not.
            $withDeadCard = $this->makeSourcePlan();
            $withDeadCard->paymentMethod->forceFill(['status' => InstallmentPaymentMethod::STATUS_REVOKED])->save();
            $this->assertFalse($eligibility->matches($offer, $withDeadCard->fresh(), $quote));
        });
    }

    public function test_a_member_with_no_usable_identity_is_never_offered_anything(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer();

            // Email only. The consent gate matches on customer_id / shopify_customer_id
            // and never on email, so this plan can never legally be charged again.
            $anonymous = $this->makeSourcePlan([
                'shopify_customer_id' => null,
                'external_customer_id' => null,
                'customer_id' => null,
            ]);

            $this->assertFalse((new AccountOfferEligibility)->matches($offer, $anonymous, $quote));
        });
    }

    public function test_somebody_already_on_the_offered_cadence_is_not_offered_it_again(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer();
            $eligibility = new AccountOfferEligibility;

            // Same product, same cadence — this is the thing being offered.
            $already = $this->makeSourcePlan([
                'external_product_id' => self::PRODUCT_MONTHLY,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
            ]);

            $this->assertTrue($eligibility->holdsTarget([$already], $quote));
            $this->assertSame([], $eligibility->sourcesFor($offer, [$already], $quote));
        });
    }

    public function test_the_same_product_on_a_different_cadence_is_still_a_candidate(): void
    {
        $this->inShop(function (): void {
            // The 2666 case: the member holds 2666 YEARLY and is offered 2666
            // MONTHLY. Same product — the cadence is the whole offer.
            $product = $this->makeProduct(self::PRODUCT_YEARLY, 'Membership 2666', 40.0);
            $offer = $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY));
            $quote = AccountOfferQuote::for($offer, null, Tenant::current());

            $yearly = $this->makeSourcePlan(['external_product_id' => self::PRODUCT_YEARLY]);

            $eligibility = new AccountOfferEligibility;

            $this->assertFalse($eligibility->holdsTarget([$yearly], $quote));
            $this->assertTrue($eligibility->matches($offer, $yearly, $quote));
        });
    }

    public function test_charging_paused_hides_an_immediate_offer_but_not_a_period_end_one(): void
    {
        $this->inShop(function (): void {
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();

            [$immediate] = $this->monthlyOffer();
            [$periodEnd] = $this->monthlyOffer(['replace_timing' => AccountOffer::TIMING_PERIOD_END]);

            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current()->fresh();

            // Never bypass the wall: an immediate offer would be refused, and a
            // button that is always refused is worse than no button.
            $this->assertFalse($eligibility->isOpen($immediate, now(), $settings));
            // Nothing is charged today, so this one is honest either way.
            $this->assertTrue($eligibility->isOpen($periodEnd, now(), $settings));
        });
    }

    public function test_one_subscription_per_customer_hides_an_add_offer_but_not_a_replace(): void
    {
        $this->inShop(function (): void {
            MerchantBillingSettings::current()->forceFill(['single_active_subscription' => true])->save();

            [$add] = $this->monthlyOffer(['mode' => AccountOffer::MODE_ADD, 'replace_timing' => null]);
            [$replace] = $this->monthlyOffer();

            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current()->fresh();

            // The merchant's own rule, not ours to break from the inside.
            $this->assertFalse($eligibility->isOpen($add, now(), $settings));
            $this->assertTrue($eligibility->isOpen($replace, now(), $settings));
        });
    }

    public function test_an_add_offer_reports_no_timing_but_still_charges_immediately(): void
    {
        $this->inShop(function (): void {
            [$add] = $this->monthlyOffer(['mode' => AccountOffer::MODE_ADD, 'replace_timing' => null]);

            // The payload says null — an add ends no period, so it must not imply
            // one. The money question is a different one, and it says "now".
            $this->assertNull($add->timing());
            $this->assertTrue($add->isImmediate());
        });
    }

    public function test_an_unsellable_template_produces_no_quote_at_all(): void
    {
        $this->inShop(function (): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $shop = Tenant::current();

            $draft = $this->makeOffer($this->makeTemplate($product, status: PlanTemplateStatus::DRAFT));
            $this->assertNull(AccountOfferQuote::for($draft, null, $shop), 'A draft template is not published.');

            $shopifyRail = $this->makeOffer($this->makeTemplate($product, rail: ProductSubscriptionPlan::RAIL_SHOPIFY_PAYMENTS));
            $this->assertNull(AccountOfferQuote::for($shopifyRail, null, $shop), 'Shopify owns that rail; we hold no token.');

            $free = $this->makeProduct('0000', 'Free thing', 0.0);
            $priceless = $this->makeOffer($this->makeTemplate($free));
            $this->assertNull(AccountOfferQuote::for($priceless, null, $shop), 'No price, no charge, no offer.');
        });
    }

    public function test_a_half_finished_switch_hides_every_offer_until_it_is_repaired(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote] = $this->monthlyOffer();

            $source = $this->makeSourcePlan();
            $stranded = $this->makeSourcePlan([
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'meta' => ['account_offer' => ['replace_pending' => true, 'mode' => AccountOffer::MODE_REPLACE]],
            ]);

            $eligibility = new AccountOfferEligibility;

            $this->assertTrue($eligibility->hasPendingReplacement([$source, $stranded]));
            $this->assertSame([], $eligibility->sourcesFor($offer, [$source, $stranded], $quote));
        });
    }

    public function test_the_eligible_count_never_compares_the_bigint_customer_id_to_a_uuid(): void
    {
        $this->inShop(function (): void {
            [$yearlyOnly, $quote] = $this->monthlyOffer(['audience' => ['frequencies' => [BillingFrequency::YEARLY->value]]]);

            // Two imported YEARLY members with a live card: the answer.
            $this->makeSourcePlan();
            $this->makeSourcePlan();
            // Monthly on another product: outside the frequency filter.
            $this->makeSourcePlan(['billing_frequency' => BillingFrequency::MONTHLY->value]);
            // Yearly with no saved card: cannot be charged one-click.
            $this->makeSourcePlan(withCard: false);
            // Already on the offered product AND cadence.
            $this->makeSourcePlan([
                'external_product_id' => self::PRODUCT_MONTHLY,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
            ]);

            $eligibility = new AccountOfferEligibility;

            $this->assertSame(2, $eligibility->eligibleNowCount($yearlyOnly, $quote));

            // With no frequency filter the monthly member on another product comes
            // back too — but NOT the one already on the offered product and
            // cadence, whose null shopify_product_id must not smuggle it past the
            // exclusion on SQL's three-valued logic.
            [$anyone] = $this->monthlyOffer();
            $this->assertSame(3, $eligibility->eligibleNowCount($anyone, $quote));
        });
    }

    // === Helpers ===

    private function inShop(callable $callback): void
    {
        Tenant::run($this->makeShop(), $callback);
    }

    /**
     * The pilot shop's offer: the 2675 MONTHLY template, offered as a replacement.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: AccountOffer, 1: AccountOfferQuote}
     */
    private function monthlyOffer(array $attributes = []): array
    {
        $this->target ??= $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);

        $offer = $this->makeOffer($this->makeTemplate($this->target, BillingFrequency::MONTHLY), $attributes);
        $quote = AccountOfferQuote::for($offer, null, Tenant::current());

        $this->assertNotNull($quote, 'The fixture offer must be sellable.');

        return [$offer, $quote];
    }
}
