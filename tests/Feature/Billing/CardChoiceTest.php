<?php

namespace Tests\Feature\Billing;

use App\Domain\Installments\Models\TokenRecoveryResult;
use App\Domain\Installments\Models\TokenRecoveryRun;
use App\Filament\Pages\PaymentRecovery;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "PAYPLUS SHOWS TWO CARDS — WHY DID YOU SAY THERE WAS NOTHING?"
 *
 * A merchant opened their gateway, saw two saved cards against a member the report
 * had filed as "no replacement", and reasonably concluded we had missed one. We had
 * not: their second card had expired fourteen months earlier. But the report only
 * counted, so the only way to learn that was to check by hand — which is the work
 * this screen exists to remove.
 *
 * Two things follow, and these tests pin both.
 *
 * The report now says WHICH refusal, because they are different next actions:
 * nothing else in the vault is a dead end, an expired alternative is a dead end
 * worth naming, a customer we could not find is a fixable email, and several live
 * cards is a DECISION — not a dead end at all.
 *
 * And a decision needs somebody to make it. The rules refuse to choose between two
 * live cards, correctly, because guessing bills a card the customer may have
 * retired. The merchant looking at those same two rows can tell which is current,
 * and now has a way to say so — choosing only ever from that member's own live
 * cards, never a free-text token, because a pasted token from another customer
 * would bill the wrong person every month.
 */
final class CardChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'choice.example.com',
            'name' => 'Choice',
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

    // === What the merchant is offered ===

    /** Two live alternatives: the merchant is asked, and sees both. */
    public function test_a_member_with_two_live_cards_is_offered_the_choice(): void
    {
        $plan = $this->plan();
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_SEVERAL, [
            $this->card('tok-held', '0729', held: true),
            $this->card('tok-new', '0829', addedAt: '2026-06-01'),
            $this->card('tok-old', '0130', addedAt: '2024-03-04'),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionVisible('chooseCard');
    }

    /**
     * THE CASE THAT PROMPTED THIS. One other card, and it expired last year — so
     * there is nothing to choose and the button must not appear. Offering it would
     * be offering a decline.
     */
    public function test_an_expired_alternative_is_never_offered(): void
    {
        $plan = $this->plan();
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_ALL_EXPIRED, [
            $this->card('tok-held', '0926', held: true),
            $this->card('tok-expired', '0725', addedAt: '2023-06-17'),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('chooseCard');
    }

    /** Nothing in the vault but the dead card: nothing to offer. */
    public function test_a_member_with_no_other_card_is_offered_nothing(): void
    {
        $plan = $this->plan();
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_ONLY_DEAD, [
            $this->card('tok-held', '0926', held: true),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('chooseCard');
    }

    /** A member nobody ever looked up has no choice to offer. */
    public function test_a_member_with_no_lookup_is_offered_nothing(): void
    {
        $plan = $this->plan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('chooseCard');
    }

    // === Making the choice ===

    /** The chosen card is attached, and its owning record travels with it. */
    public function test_choosing_a_card_attaches_it(): void
    {
        $plan = $this->plan();
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_SEVERAL, [
            $this->card('tok-held', '0729', held: true),
            $this->card('tok-new', '0829', addedAt: '2026-06-01', customerUid: 'cust-other'),
            $this->card('tok-old', '0130', addedAt: '2024-03-04'),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('chooseCard', ['token' => 'tok-new']);

        $method = $plan->fresh()->paymentMethod;

        $this->assertSame('tok-new', $method->payplus_card_token_uid);
        $this->assertSame('cust-other', $method->payplus_customer_uid);
    }

    /**
     * RELEASE BLOCKER. A token that is not among this member's own live cards is
     * refused — the failure mode is not an error message, it is billing a
     * different person's card every month.
     */
    public function test_a_token_that_is_not_theirs_is_refused(): void
    {
        $plan = $this->plan();
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_SEVERAL, [
            $this->card('tok-held', '0729', held: true),
            $this->card('tok-new', '0829', addedAt: '2026-06-01'),
            $this->card('tok-old', '0130', addedAt: '2024-03-04'),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('chooseCard', ['token' => 'tok-somebody-elses']);

        $this->assertSame('tok-held', $plan->fresh()->paymentMethod->payplus_card_token_uid);
    }

    /**
     * An expired card cannot be chosen even when the menu is open for a live one.
     * It is in the candidate list (the report must show it) but it is not an
     * option, because choosing it could only ever produce a decline.
     */
    public function test_an_expired_card_cannot_be_chosen_directly(): void
    {
        $plan = $this->plan();
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_SEVERAL, [
            $this->card('tok-held', '0926', held: true),
            $this->card('tok-live', '0530', addedAt: '2026-06-01'),
            $this->card('tok-expired', '0725', addedAt: '2023-06-17'),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('chooseCard', ['token' => 'tok-expired']);

        $this->assertSame('tok-held', $plan->fresh()->paymentMethod->payplus_card_token_uid);
    }

    /** Choosing a card ends the hold, exactly as finding one does. */
    public function test_choosing_a_card_puts_a_held_member_back_in_the_queue(): void
    {
        $plan = $this->plan(PlanStatus::PAUSED, failedAt: now()->subDay());
        $this->resultFor($plan, TokenRecoveryResult::DETAIL_SEVERAL, [
            $this->card('tok-held', '0729', held: true),
            $this->card('tok-new', '0829', addedAt: '2026-06-01'),
            $this->card('tok-old', '0130', addedAt: '2024-03-04'),
        ]);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('chooseCard', ['token' => 'tok-new']);

        $plan->refresh();
        $this->assertSame(PlanStatus::AWAITING_PAYMENT->value, $plan->status->value);
        $this->assertNull($plan->payment_failed_at);
    }

    // === The failed-charges screen ===

    /**
     * THE COLUMN THE MERCHANT ASKED FOR. "Why it failed" is the gateway's verdict
     * on the card we hold; this says what PayPlus holds INSTEAD, which is the
     * column that tells them what to do — and which row is worth a click.
     */
    public function test_the_failed_charges_screen_shows_what_the_lookup_found(): void
    {
        $choose = $this->plan(PlanStatus::PAUSED, failedAt: now()->subDay());
        $this->resultFor($choose, TokenRecoveryResult::DETAIL_SEVERAL, [
            $this->card('tok-held', '0729', held: true),
            $this->card('tok-a', '0530'),
            $this->card('tok-b', '0631'),
        ]);

        $expired = $this->plan(PlanStatus::PAUSED, failedAt: now()->subDay());
        $this->resultFor($expired, TokenRecoveryResult::DETAIL_ALL_EXPIRED, [
            $this->card('tok-held2', '0926', held: true),
            $this->card('tok-gone', '0725'),
        ]);

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->assertSee(__('recovery.lookup.several', ['count' => 2]))
            ->assertSee(__('recovery.lookup.expired'));
    }

    /** A member nobody looked up reads as a dash, never as "nothing found". */
    public function test_a_member_never_looked_up_claims_no_answer(): void
    {
        $this->plan(PlanStatus::PAUSED, failedAt: now()->subDay());

        Livewire::test(PaymentRecovery::class)
            ->call('setTab', PaymentRecovery::TAB_STOPPED)
            ->assertDontSee(__('recovery.lookup.nothing'));
    }

    // === The model's own reading ===

    /** Held and expired cards are never choosable, whatever the detail says. */
    public function test_only_live_cards_that_are_not_ours_are_choosable(): void
    {
        $result = new TokenRecoveryResult;
        $result->forceFill(['candidates' => [
            $this->card('tok-held', '0829', held: true),
            $this->card('tok-expired', '0125'),
            $this->card('tok-live', '0530'),
        ]]);

        $this->assertSame(['tok-live'], array_column($result->choosableCards(), 'token'));
        $this->assertTrue($result->offersAChoice());
    }

    // === Fixtures ===

    private function plan(PlanStatus $status = PlanStatus::PAUSED, $failedAt = null): InstallmentPlan
    {
        $method = InstallmentPaymentMethod::create([
            'payplus_card_token_uid' => 'tok-held',
            'payplus_customer_uid' => 'cust-1',
            'card_brand' => 'visa',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.uniqid(),
            'customer_name' => 'Shmuel',
            'customer_email' => 'shmuel@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => $status->value,
            'payment_failed_at' => $failedAt,
            'payment_method_id' => $method->getKey(),
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => now()->subDay(),
        ])->save();

        return $plan;
    }

    /** @param  list<array<string, mixed>>  $candidates */
    private function resultFor(InstallmentPlan $plan, string $detail, array $candidates): void
    {
        $run = new TokenRecoveryRun;
        $run->forceFill([
            'shop_id' => $this->shop->getKey(),
            'mode' => TokenRecoveryRun::MODE_RECOVER,
            'plan_ids' => [$plan->getKey()],
            'status' => TokenRecoveryRun::STATUS_COMPLETED,
            'total' => 1,
        ])->save();

        $result = new TokenRecoveryResult;
        $result->forceFill([
            'shop_id' => $this->shop->getKey(),
            'run_id' => $run->getKey(),
            'plan_id' => $plan->getKey(),
            'outcome' => TokenRecoveryResult::OUTCOME_NONE,
            'detail' => $detail,
            'candidates' => $candidates,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function card(
        string $token,
        string $mmyy,
        bool $held = false,
        ?string $addedAt = null,
        ?string $customerUid = null,
    ): array {
        return [
            'token' => $token,
            'last_four' => substr($token, -4),
            'expiry' => $mmyy,
            'expired' => ! PayPlusTokenDiscovery::isCardUnexpired($mmyy),
            'added_at' => $addedAt,
            'held' => $held,
            'customer_uid' => $customerUid ?? 'cust-1',
        ];
    }
}
