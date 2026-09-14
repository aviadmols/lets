<?php

namespace Tests\Feature\Billing;

use App\Domain\Installments\ImportedTokenRecovery;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WHICH DECLINES ARE WORTH ASKING PAYPLUS ABOUT.
 *
 * "Find saved card" used to need two marks together: an imported token reference
 * with no PayPlus customer uid, AND a charge that came back "token-not-exist".
 * Too narrow, and a real migrated book showed why. A member can hold MORE THAN ONE
 * record at PayPlus — one per spelling of their name — each with its own saved
 * card. Their method is properly vaulted, their customer uid is set, and the token
 * we imported still points at the card they replaced. A live card then answers
 * "not valid" / "blocked" / "stolen, confiscate", and the one button that would
 * have fixed them was hidden.
 *
 * Widening is safe, and that is the property worth stating plainly: probe() checks
 * the token we already hold FIRST, so a card that still works comes back
 * ROUTE_ALREADY_VALID and is never swapped for another. The cost of a wrong guess
 * is one read-only lookup.
 *
 * Matched on PayPlus's own Hebrew text, because every decline in this family
 * arrives as `failure_code = 1` and the message is the only signal there is. An
 * unrecognised wording hides the button rather than offering a wrong one.
 */
final class RecoverableDeclinesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'declines.example.com',
            'name' => 'Declines',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The predicate ===

    /** The four a stale token can cause, in PayPlus's own words. */
    public function test_the_declines_a_stale_token_can_cause_are_recoverable(): void
    {
        foreach ([
            'this-token-not-exist',
            'גנוב, החרם כרטיס',
            'עסקה נדחתה: הכרטיס אינו בתוקף',
            'כרטיס חסום',
        ] as $message) {
            $this->assertTrue(
                ImportedTokenRecovery::declineIsRecoverable($message),
                "should be recoverable: {$message}",
            );
        }
    }

    /**
     * The issuer refusing a card it recognises is NOT a token problem. Swapping a
     * good token for another good token fixes nothing and costs a call.
     */
    public function test_an_issuer_refusal_is_not_recoverable(): void
    {
        foreach ([
            'התקשר לחברת האשראי',
            'סירוב. העסקה לא אושרה',
        ] as $message) {
            $this->assertFalse(
                ImportedTokenRecovery::declineIsRecoverable($message),
                "should NOT be recoverable: {$message}",
            );
        }
    }

    /** Nothing to read is not a reason to offer anything. */
    public function test_an_absent_or_unknown_message_is_not_recoverable(): void
    {
        $this->assertFalse(ImportedTokenRecovery::declineIsRecoverable(null));
        $this->assertFalse(ImportedTokenRecovery::declineIsRecoverable(''));
        $this->assertFalse(ImportedTokenRecovery::declineIsRecoverable('   '));
        // A wording PayPlus has not used before hides the button rather than
        // offering a wrong one.
        $this->assertFalse(ImportedTokenRecovery::declineIsRecoverable('some new wording nobody has seen'));
    }

    // === The button ===

    /**
     * THE CASE THIS WAS WIDENED FOR. Properly vaulted, customer uid set, and the
     * card reads expired — because the token points at the record the member
     * replaced. Before, this member could not reach the button at all.
     */
    public function test_a_vaulted_card_declined_as_expired_now_offers_the_button(): void
    {
        $plan = $this->plan(customerUid: 'cust-vaulted');
        $this->decline($plan, 'עסקה נדחתה: הכרטיס אינו בתוקף');

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionVisible('recoverToken');
    }

    public function test_a_vaulted_card_declined_as_stolen_or_blocked_offers_it_too(): void
    {
        foreach (['גנוב, החרם כרטיס', 'כרטיס חסום'] as $message) {
            $plan = $this->plan(customerUid: 'cust-'.md5($message));
            $this->decline($plan, $message);

            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->assertActionVisible('recoverToken');
        }
    }

    /** An issuer refusal still hides it — nothing here a new token would fix. */
    public function test_a_vaulted_card_the_issuer_refused_still_hides_it(): void
    {
        $plan = $this->plan(customerUid: 'cust-refused');
        $this->decline($plan, 'התקשר לחברת האשראי');

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('recoverToken');
    }

    /** An imported reference that was never vaulted is worth asking about alone. */
    public function test_a_never_vaulted_import_offers_it_without_a_decline(): void
    {
        $plan = $this->plan(customerUid: null);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionVisible('recoverToken');
    }

    /** No card row at all — there is nothing to re-point. */
    public function test_a_plan_with_no_card_never_offers_it(): void
    {
        $plan = $this->plan(customerUid: 'irrelevant', withCard: false);
        $this->decline($plan, 'כרטיס חסום');

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('recoverToken');
    }

    /**
     * Read off the LATEST attempt. A blocked card a year ago on a plan that has
     * billed cleanly since is history, not a reason to offer to change its card.
     */
    public function test_an_old_decline_on_a_plan_that_recovered_does_not_offer_it(): void
    {
        $plan = $this->plan(customerUid: 'cust-recovered');
        $this->decline($plan, 'כרטיס חסום', sequence: 1);
        $this->succeeded($plan, sequence: 2);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('recoverToken');
    }

    // === Fixtures ===

    private function plan(?string $customerUid, bool $withCard = true): InstallmentPlan
    {
        $method = $withCard
            ? InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-'.uniqid(),
                'payplus_customer_uid' => $customerUid,
                'payplus_token_reference' => 'rec_'.uniqid(),
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ])
            : null;

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.uniqid(),
            'customer_name' => 'Dana',
            'customer_email' => 'dana@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::AWAITING_PAYMENT->value,
            'payment_method_id' => $method?->getKey(),
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => now(),
            'import_key' => 'legacy-'.uniqid(),
        ])->save();

        return $plan;
    }

    private function decline(InstallmentPlan $plan, string $message, int $sequence = 1): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'sequence' => $sequence,
            'amount' => 39,
            'currency' => 'ILS',
            'status' => PaymentStatus::FAILED->value,
            'attempt_count' => 7,
            'failure_code' => '1',
            'failure_message' => $message,
        ])->save();
    }

    private function succeeded(InstallmentPlan $plan, int $sequence): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'sequence' => $sequence,
            'amount' => 39,
            'currency' => 'ILS',
            'status' => PaymentStatus::SUCCEEDED->value,
            'attempt_count' => 1,
        ])->save();
    }
}
