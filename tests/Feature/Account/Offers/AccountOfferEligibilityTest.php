<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\Offers\AccountOfferEligibility;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
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
 * Who is shown an offer, from which of their subscriptions, and — since an offer
 * became a list — which of its targets are actually on sale.
 *
 * Every case here is a way to show the WRONG person the WRONG thing, which in
 * this feature means a saved card charged for something nobody wanted. The
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
            [$offer] = $this->monthlyOffer(['audience' => ['frequencies' => [BillingFrequency::YEARLY->value]]]);

            $yearly = $this->makeSourcePlan();
            $monthlyMember = $this->makeSourcePlan(['billing_frequency' => BillingFrequency::MONTHLY->value]);

            $eligibility = new AccountOfferEligibility;

            $this->assertTrue($eligibility->matches($offer, $yearly));
            $this->assertFalse($eligibility->matches($offer, $monthlyMember));
        });
    }

    public function test_the_product_filter_narrows_to_the_named_products(): void
    {
        $this->inShop(function (): void {
            [$offer] = $this->monthlyOffer(['audience' => ['product_ids' => [self::PRODUCT_YEARLY]]]);

            $onTarget = $this->makeSourcePlan();
            $elsewhere = $this->makeSourcePlan(['external_product_id' => '4242']);

            $eligibility = new AccountOfferEligibility;

            $this->assertTrue($eligibility->matches($offer, $onTarget));
            $this->assertFalse($eligibility->matches($offer, $elsewhere));
        });
    }

    public function test_a_paused_subscriber_is_still_a_subscriber_but_a_cancelled_one_is_not(): void
    {
        $this->inShop(function (): void {
            [$offer] = $this->monthlyOffer();

            $paused = $this->makeSourcePlan(['status' => PlanStatus::PAUSED->value]);
            $cancelled = $this->makeSourcePlan(['status' => PlanStatus::CANCELLED->value]);

            $eligibility = new AccountOfferEligibility;

            // Default audience is [active, paused]: somebody who will pay again the
            // moment they resume is exactly who a switch offer is for.
            $this->assertTrue($eligibility->matches($offer, $paused));
            $this->assertFalse($eligibility->matches($offer, $cancelled));
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
            [$offer] = $this->monthlyOffer();
            $eligibility = new AccountOfferEligibility;

            $noCard = $this->makeSourcePlan(withCard: false);
            $this->assertFalse($eligibility->matches($offer, $noCard));

            // A revoked token would fail at the gateway, and the shopper would have
            // been told their subscription changed when it did not.
            $withDeadCard = $this->makeSourcePlan();
            $withDeadCard->paymentMethod->forceFill(['status' => InstallmentPaymentMethod::STATUS_REVOKED])->save();
            $this->assertFalse($eligibility->matches($offer, $withDeadCard->fresh()));
        });
    }

    public function test_a_member_with_no_usable_identity_is_never_offered_anything(): void
    {
        $this->inShop(function (): void {
            [$offer] = $this->monthlyOffer();

            // Email only. The consent gate matches on customer_id / shopify_customer_id
            // and never on email, so this plan can never legally be charged again.
            $anonymous = $this->makeSourcePlan([
                'shopify_customer_id' => null,
                'external_customer_id' => null,
                'customer_id' => null,
            ]);

            $this->assertFalse((new AccountOfferEligibility)->matches($offer, $anonymous));
        });
    }

    public function test_somebody_already_on_the_offered_cadence_is_not_offered_that_target_again(): void
    {
        $this->inShop(function (): void {
            [$offer, $quote, $target] = $this->monthlyOffer();
            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current();

            // Same product, same cadence — this is the thing being offered.
            $already = $this->makeSourcePlan([
                'external_product_id' => self::PRODUCT_MONTHLY,
                'billing_frequency' => BillingFrequency::MONTHLY->value,
            ]);

            $this->assertTrue($eligibility->holdsTarget([$already], $quote));
            $this->assertFalse($eligibility->targetIsOfferable($target, $quote, [$already], $settings, $already));

            // The PLAN still matches the audience. That split is the whole point:
            // an offer with three targets must not vanish because one is taken.
            $this->assertTrue($eligibility->matches($offer, $already));
        });
    }

    public function test_the_same_product_on_a_different_cadence_is_still_a_candidate(): void
    {
        $this->inShop(function (): void {
            // The 2666 case: the member holds 2666 YEARLY and is offered 2666
            // MONTHLY. Same product — the cadence is the whole offer.
            $product = $this->makeProduct(self::PRODUCT_YEARLY, 'Membership 2666', 40.0);
            $offer = $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY));
            $target = $offer->orderedTargets()[0];
            $quote = AccountOfferQuote::forTarget($target, null, Tenant::current());

            $yearly = $this->makeSourcePlan(['external_product_id' => self::PRODUCT_YEARLY]);

            $eligibility = new AccountOfferEligibility;

            $this->assertFalse($eligibility->holdsTarget([$yearly], $quote));
            $this->assertTrue($eligibility->matches($offer, $yearly));
        });
    }

    public function test_charging_paused_hides_an_immediate_target_but_not_a_period_end_one(): void
    {
        $this->inShop(function (): void {
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();

            [, , $immediate] = $this->monthlyOffer();
            [, , $periodEnd] = $this->monthlyOffer(['replace_timing' => AccountOfferTarget::TIMING_PERIOD_END]);

            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current()->fresh();

            // Never bypass the wall: an immediate target would be refused, and a
            // button that is always refused is worse than no button.
            $this->assertFalse($eligibility->targetIsOpen($immediate, $settings));
            // Nothing is charged today, so this one is honest either way.
            $this->assertTrue($eligibility->targetIsOpen($periodEnd, $settings));
        });
    }

    /**
     * The same wall, over the one-time kind. `immediate` moves money on the click
     * exactly as a switch does; `next_order` moves none, so a shop mid-migration
     * can still let subscribers add things to a box that has not been billed yet.
     */
    public function test_charging_paused_hides_a_buy_now_target_but_not_a_next_order_one(): void
    {
        $this->inShop(function (): void {
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();

            $mug = $this->makeProduct('4242', 'Club mug', 39.0);
            $offer = $this->makeOffer();
            $buyNow = $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => $mug->external_id,
                'fulfilment' => AccountOfferTarget::FULFILMENT_IMMEDIATE,
            ]);
            $rideAlong = $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => $mug->external_id,
                'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
            ]);

            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current()->fresh();

            $this->assertFalse($eligibility->targetIsOpen($buyNow, $settings));
            $this->assertTrue($eligibility->targetIsOpen($rideAlong, $settings));
        });
    }

    public function test_one_subscription_per_customer_hides_an_add_subscription_but_not_a_one_time_product(): void
    {
        $this->inShop(function (): void {
            MerchantBillingSettings::current()->forceFill(['single_active_subscription' => true])->save();

            [, , $add] = $this->monthlyOffer(['mode' => AccountOfferTarget::MODE_ADD, 'replace_timing' => null]);
            [, , $replace] = $this->monthlyOffer();

            $mug = $this->makeProduct('4242', 'Club mug', 39.0);
            $product = $this->makeOffer();
            $oneTime = $this->addTarget($product, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => $mug->external_id,
            ]);

            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current()->fresh();

            // The merchant's own rule, not ours to break from the inside.
            $this->assertFalse($eligibility->targetIsOpen($add, $settings));
            $this->assertTrue($eligibility->targetIsOpen($replace, $settings));
            // …but a mug is not a subscription, and the rule says nothing about it.
            $this->assertTrue($eligibility->targetIsOpen($oneTime, $settings));
        });
    }

    public function test_an_add_target_reports_no_timing_but_still_charges_immediately(): void
    {
        $this->inShop(function (): void {
            [, , $add] = $this->monthlyOffer(['mode' => AccountOfferTarget::MODE_ADD, 'replace_timing' => null]);

            // The payload says null — an add ends no period, so it must not imply
            // one. The money question is a different one, and it says "now".
            $this->assertNull($add->timing());
            $this->assertTrue($add->chargesNow());
        });
    }

    /** An add-on with nothing to ride on is never drawn, rather than drawn and refused. */
    public function test_a_next_order_target_needs_a_next_order(): void
    {
        $this->inShop(function (): void {
            $mug = $this->makeProduct('4242', 'Club mug', 39.0);
            $offer = $this->makeOffer();
            $target = $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => $mug->external_id,
                'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
            ]);

            $quote = AccountOfferQuote::forTarget($target, null, Tenant::current());
            $this->assertNotNull($quote);

            $eligibility = new AccountOfferEligibility;
            $settings = MerchantBillingSettings::current();

            $scheduled = $this->makeSourcePlan();
            $unscheduled = $this->makeSourcePlan(['next_charge_at' => null]);

            $this->assertTrue($eligibility->targetIsOfferable($target, $quote, [$scheduled], $settings, $scheduled));
            $this->assertFalse($eligibility->targetIsOfferable($target, $quote, [$unscheduled], $settings, $unscheduled));
        });
    }

    /** Buying the same mug twice is allowed; holding the same subscription twice is not. */
    public function test_holding_a_one_time_product_never_hides_it(): void
    {
        $this->inShop(function (): void {
            $mug = $this->makeProduct('4242', 'Club mug', 39.0);
            $offer = $this->makeOffer();
            $target = $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => $mug->external_id,
            ]);

            $quote = AccountOfferQuote::forTarget($target, null, Tenant::current());

            // A live plan on the very same product. For a subscription target this
            // would be "you already have it"; for a mug it is nothing at all.
            $onMug = $this->makeSourcePlan([
                'external_product_id' => '4242',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
            ]);

            $eligibility = new AccountOfferEligibility;

            $this->assertFalse($eligibility->holdsTarget([$onMug], $quote));
            $this->assertTrue($eligibility->targetIsOfferable(
                $target, $quote, [$onMug], MerchantBillingSettings::current(), $onMug,
            ));
        });
    }

    public function test_an_unsellable_target_produces_no_quote_at_all(): void
    {
        $this->inShop(function (): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $shop = Tenant::current();

            $draft = $this->makeOffer($this->makeTemplate($product, status: PlanTemplateStatus::DRAFT));
            $this->assertNull($this->quoteFor($draft, $shop), 'A draft template is not published.');

            $shopifyRail = $this->makeOffer($this->makeTemplate($product, rail: ProductSubscriptionPlan::RAIL_SHOPIFY_PAYMENTS));
            $this->assertNull($this->quoteFor($shopifyRail, $shop), 'Shopify owns that rail; we hold no token.');

            $free = $this->makeProduct('0000', 'Free thing', 0.0);
            $priceless = $this->makeOffer($this->makeTemplate($free));
            $this->assertNull($this->quoteFor($priceless, $shop), 'No price, no charge, no offer.');

            // A one-time target naming a product this shop does not sell.
            $foreign = $this->makeOffer();
            $this->addTarget($foreign, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => 'not-in-this-catalog',
            ]);
            $this->assertNull($this->quoteFor($foreign, $shop), 'A product we do not stock cannot be priced.');
        });
    }

    public function test_a_half_finished_switch_hides_every_offer_until_it_is_repaired(): void
    {
        $this->inShop(function (): void {
            [$offer] = $this->monthlyOffer();

            $source = $this->makeSourcePlan();
            $stranded = $this->makeSourcePlan([
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'meta' => ['account_offer' => ['replace_pending' => true, 'mode' => AccountOfferTarget::MODE_REPLACE]],
            ]);

            $eligibility = new AccountOfferEligibility;

            $this->assertTrue($eligibility->hasPendingReplacement([$source, $stranded]));
            $this->assertSame([], $eligibility->sourcesFor($offer, [$source, $stranded]));
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
     * @return array{0: AccountOffer, 1: AccountOfferQuote, 2: AccountOfferTarget}
     */
    private function monthlyOffer(array $attributes = []): array
    {
        $this->target ??= $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);

        $offer = $this->makeOffer($this->makeTemplate($this->target, BillingFrequency::MONTHLY), $attributes);
        $target = $offer->orderedTargets()[0];
        $quote = AccountOfferQuote::forTarget($target, null, Tenant::current());

        $this->assertNotNull($quote, 'The fixture offer must be sellable.');

        return [$offer, $quote, $target];
    }

    private function quoteFor(AccountOffer $offer, $shop): ?AccountOfferQuote
    {
        return AccountOfferQuote::forTarget($offer->orderedTargets()[0], null, $shop);
    }
}
