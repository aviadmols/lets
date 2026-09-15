<?php

namespace Tests\Feature\Billing;

use App\Domain\Installments\ImportedTokenRecovery;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A TOKEN THAT EXISTS IS NOT A CARD THAT WORKS.
 *
 * `/Token/Check` answers whether the VAULT still holds the entry. It knows nothing
 * about what the issuer thinks of the card behind it, so a stolen card's token
 * answers VALID forever. The recovery believed otherwise and stopped there, and a
 * real run showed the cost: 83 members asked, 62 came back "already valid", 0
 * cards found, and all 78 charges declined again with the identical message.
 *
 * One of them had FOUR cards saved at PayPlus. We were holding the one added in
 * July 2025; the one added in June 2026 was two rows above it, never looked at.
 *
 * So when the ISSUER has declared the card dead — stolen, blocked, expired — the
 * question inverts: not "which of these is the card we hold?" but "which of these
 * is the card that replaced it?". These tests pin that inversion, and pin the
 * refusals that keep it honest.
 */
final class ReplacementCardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The predicate ===

    /** The three the ISSUER kills. */
    public function test_the_issuer_killing_a_card_is_recognised(): void
    {
        foreach (['גנוב, החרם כרטיס', 'כרטיס חסום', 'עסקה נדחתה: הכרטיס אינו בתוקף'] as $message) {
            $this->assertTrue(ImportedTokenRecovery::cardIsDead($message), $message);
        }
    }

    /**
     * "token-not-exist" is NOT a dead card — there is no card here at all, only a
     * missing vault entry. Kept out so the two ideas cannot drift together.
     */
    public function test_a_missing_vault_entry_is_not_a_dead_card(): void
    {
        $this->assertFalse(ImportedTokenRecovery::cardIsDead('this-token-not-exist'));
        $this->assertTrue(ImportedTokenRecovery::declineIsRecoverable('this-token-not-exist'));
    }

    /** An issuer refusing a card it recognises has not killed it. */
    public function test_a_plain_refusal_is_not_a_dead_card(): void
    {
        $this->assertFalse(ImportedTokenRecovery::cardIsDead('סירוב. העסקה לא אושרה'));
        $this->assertFalse(ImportedTokenRecovery::cardIsDead('התקשר לחברת האשראי'));
        $this->assertFalse(ImportedTokenRecovery::cardIsDead(null));
        $this->assertFalse(ImportedTokenRecovery::cardIsDead('a wording nobody has seen'));
    }

    // === Choosing the replacement ===

    /**
     * THE REAL CASE, from the merchant's own PayPlus screen. Four cards; we hold
     * the one vaulted in July 2025; the issuer has blocked it.
     *
     * The answer must be the card added in JUNE 2026 — not the one expiring 01/30,
     * which carries the furthest expiry and was vaulted back in 2024.
     */
    public function test_it_picks_the_newest_vaulted_card_not_the_furthest_expiry(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [
                $this->card('tok-4825', '0829', '2026-06-01 00:00:00'),
                $this->card('tok-0887', '0729', '2025-07-28 00:00:00'), // ours
                $this->card('tok-9363', '0130', '2024-03-04 00:00:00'), // latest expiry, oldest but one
                $this->card('tok-7285', '0728', '2023-11-27 00:00:00'), // expired
            ],
            heldToken: 'tok-0887',
            expMonth: 7,
            expYear: 2029,
        );

        $this->assertNotNull($pick);
        $this->assertSame('tok-4825', $pick['card']['token']);
        $this->assertSame('newest_added', $pick['basis']);
    }

    /** The dead card is never handed back, even when it is the newest thing there. */
    public function test_it_never_returns_the_card_we_already_hold(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [$this->card('tok-held', '0829', '2026-06-01 00:00:00')],
            heldToken: 'tok-held',
            expMonth: 8,
            expYear: 2029,
        );

        $this->assertNull($pick, 'the only card there is the dead one');
    }

    /**
     * The same physical card re-vaulted under another customer record carries a
     * DIFFERENT token uid. Excluded by its expiry, or we would swap a dead card
     * for itself and call it a recovery.
     */
    public function test_it_excludes_the_same_card_vaulted_under_another_uid(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [$this->card('tok-elsewhere', '0729', '2026-06-01 00:00:00')],
            heldToken: 'tok-ours',
            expMonth: 7,
            expYear: 2029,
        );

        $this->assertNull($pick);
    }

    /** One other live card is not a choice — it is the answer. */
    public function test_a_single_other_live_card_is_taken_without_dates(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [
                $this->card('tok-other', '0530'),
                $this->card('tok-dead', '0125'), // expired in Jan 2025 — not a replacement
            ],
            heldToken: 'tok-ours',
            expMonth: 1,
            expYear: 2027,
        );

        $this->assertNotNull($pick);
        $this->assertSame('tok-other', $pick['card']['token']);
        $this->assertSame('only_other_card', $pick['basis']);
    }

    /**
     * WITHOUT DATES IT TAKES THE FIRST, and says so.
     *
     * Refusing was the safer-looking choice and the wrong one: it left the
     * merchant a list of cards, no recommendation, and a subscription nobody was
     * billing. PayPlus returns the newest card first — their own saved-cards
     * screen reads that way — and the basis records that the pick rested on order
     * rather than on a date. A wrong pick costs one decline on a card that was
     * already dead.
     */
    public function test_several_candidates_with_no_dates_take_the_first(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [$this->card('tok-a', '0530'), $this->card('tok-b', '0631')],
            heldToken: 'tok-ours',
            expMonth: 1,
            expYear: 2027,
        );

        $this->assertNotNull($pick);
        $this->assertSame('tok-a', $pick['card']['token']);
        $this->assertSame('list_order', $pick['basis'], 'weaker evidence must be labelled');
    }

    /** A date on some but not all cannot rank them — order decides instead. */
    public function test_partially_dated_candidates_fall_back_to_order(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [
                $this->card('tok-a', '0530'),
                $this->card('tok-b', '0631', '2026-06-01 00:00:00'),
            ],
            heldToken: 'tok-ours',
            expMonth: 1,
            expYear: 2027,
        );

        $this->assertNotNull($pick);
        $this->assertSame('list_order', $pick['basis']);
    }

    /** A tie on the timestamp is not an order — the list's own order decides. */
    public function test_candidates_vaulted_at_the_same_moment_fall_back_to_order(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [
                $this->card('tok-a', '0530', '2026-06-01 09:00:00'),
                $this->card('tok-b', '0631', '2026-06-01 09:00:00'),
            ],
            heldToken: 'tok-ours',
            expMonth: 1,
            expYear: 2027,
        );

        $this->assertNotNull($pick);
        $this->assertSame('tok-a', $pick['card']['token']);
        $this->assertSame('list_order', $pick['basis']);
    }

    /** Every other card has expired: there is nothing here to move to. */
    public function test_only_expired_alternatives_is_no_replacement(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [$this->card('tok-old', '0124', '2024-01-01 00:00:00')],
            heldToken: 'tok-ours',
            expMonth: 1,
            expYear: 2027,
        );

        $this->assertNull($pick);
    }

    /** Each spelling PayPlus might use for the vaulted-at column is read. */
    public function test_the_vaulted_at_date_is_read_under_any_of_its_names(): void
    {
        foreach (PayPlusTokenDiscovery::ADDED_AT_KEYS as $key) {
            $this->assertSame(
                strtotime('2026-06-01 00:00:00'),
                PayPlusTokenDiscovery::addedAt(['token' => 'x', $key => '2026-06-01 00:00:00']),
                "key {$key} should be read",
            );
        }

        $this->assertNull(PayPlusTokenDiscovery::addedAt(['token' => 'x']));
        $this->assertNull(PayPlusTokenDiscovery::addedAt(['token' => 'x', 'created_at' => 'not a date']));
    }

    // === A newer card than ours ===

    /**
     * THE REAL CASE. We hold a Visa vaulted 2025-12-10; the customer added a
     * Mastercard on 2026-08-31. Both tokens valid, and every charge refused —
     * because ours is the old one. "Is our token valid?" answered yes and stopped.
     */
    public function test_it_finds_a_card_the_customer_vaulted_after_ours(): void
    {
        $pick = PayPlusTokenDiscovery::newerCard(
            [
                $this->card('tok-2756', '1128', '2025-12-10 00:00:00'), // ours
                $this->card('tok-0798', '0830', '2026-08-31 00:00:00'),
            ],
            heldToken: 'tok-2756',
            expMonth: 11,
            expYear: 2028,
        );

        $this->assertNotNull($pick);
        $this->assertSame('tok-0798', $pick['card']['token']);
        $this->assertSame('newer_than_held', $pick['basis']);
    }

    /** Only moves FORWARD. An older card is not an upgrade. */
    public function test_an_older_card_is_never_taken(): void
    {
        $pick = PayPlusTokenDiscovery::newerCard(
            [
                $this->card('tok-ours', '1128', '2026-08-31 00:00:00'),
                $this->card('tok-old', '0830', '2024-01-01 00:00:00'),
            ],
            heldToken: 'tok-ours',
            expMonth: 11,
            expYear: 2028,
        );

        $this->assertNull($pick, 'a card vaulted before ours is not newer');
    }

    /**
     * WITHOUT DATES IT USES POSITION — the merchant asked never to be made to
     * choose, and the list itself ranks them: PayPlus returns the newest first.
     */
    public function test_an_undatable_held_card_falls_back_to_position(): void
    {
        $pick = PayPlusTokenDiscovery::newerCard(
            [
                $this->card('tok-newer', '0830'),
                $this->card('tok-ours', '1128'), // ours is second, so something is newer
            ],
            heldToken: 'tok-ours',
            expMonth: 11,
            expYear: 2028,
        );

        $this->assertNotNull($pick);
        $this->assertSame('tok-newer', $pick['card']['token']);
        $this->assertSame('list_order', $pick['basis']);
    }

    /**
     * THE SAFETY THAT SURVIVES WITH NO DATES AT ALL. Ours is already at the top of
     * the list, so it IS the newest — and a missing date must never walk a
     * subscription backwards onto an older card.
     */
    public function test_our_card_being_first_means_nothing_is_newer(): void
    {
        $pick = PayPlusTokenDiscovery::newerCard(
            [
                $this->card('tok-ours', '1128'),
                $this->card('tok-older', '0830'),
            ],
            heldToken: 'tok-ours',
            expMonth: 11,
            expYear: 2028,
        );

        $this->assertNull($pick);
    }

    /** A newer card that has already expired is not somewhere to move to. */
    public function test_a_newer_but_expired_card_is_skipped(): void
    {
        $pick = PayPlusTokenDiscovery::newerCard(
            [
                $this->card('tok-ours', '1128', '2025-12-10 00:00:00'),
                $this->card('tok-dead', '0125', '2026-08-31 00:00:00'),
            ],
            heldToken: 'tok-ours',
            expMonth: 11,
            expYear: 2028,
        );

        $this->assertNull($pick);
    }

    // === Naming the refusal ===

    /**
     * AN EMPTY VAULT IS NOT AMBIGUITY.
     *
     * A migrated member whose exported token PayPlus never held, and who has no
     * card vaulted either, was filed as "could not identify the card" — which
     * sends somebody to compare a list that is empty. The two readings lead to
     * different work: one is "look again", the other is "ask them for a card".
     */
    public function test_no_cards_at_all_is_reported_as_empty_not_ambiguous(): void
    {
        $reason = $this->reasonFor([]);

        $this->assertSame('no_cards_at_payplus', $reason);
    }

    /** Their vault holds only the card the issuer killed. */
    public function test_only_the_dead_card_is_named_as_such(): void
    {
        $this->assertSame('only_the_dead_card', $this->reasonFor([
            $this->candidate('tok-held', '0926', held: true),
        ]));
    }

    /** There IS another card and it is past its date — a dead end worth naming. */
    public function test_an_expired_alternative_is_named_as_expired(): void
    {
        $this->assertSame('other_cards_all_expired', $this->reasonFor([
            $this->candidate('tok-held', '0926', held: true),
            $this->candidate('tok-old', '0725'),
        ]));
    }

    /** Live alternatives: a decision waiting for a human, not a dead end. */
    public function test_live_alternatives_are_named_as_a_choice(): void
    {
        $this->assertSame('several_possible_cards', $this->reasonFor([
            $this->candidate('tok-held', '0926', held: true),
            $this->candidate('tok-a', '0530'),
            $this->candidate('tok-b', '0631'),
        ]));
    }

    /** @param  list<array<string, mixed>>  $candidates */
    private function reasonFor(array $candidates): string
    {
        $method = new \ReflectionMethod(ImportedTokenRecovery::class, 'whyNoReplacement');
        $method->setAccessible(true);

        return $method->invoke(app(ImportedTokenRecovery::class), $candidates);
    }

    /** @return array<string, mixed> */
    private function candidate(string $token, string $mmyy, bool $held = false): array
    {
        return [
            'token' => $token,
            'expiry' => $mmyy,
            'expired' => ! PayPlusTokenDiscovery::isCardUnexpired($mmyy),
            'held' => $held,
        ];
    }

    // === What attaching a card does to the subscription ===

    /**
     * A NEW CARD ENDS THE HOLD. The merchant asked for this in one sentence: if a
     * replacement is found, the member should move out of "stopped" and back into
     * "waiting to be charged again" — not sit there until somebody remembers them.
     */
    public function test_attaching_a_card_moves_a_held_member_back_into_the_charge_queue(): void
    {
        $plan = $this->heldPlan();
        $payment = $plan->latestPayment()->first();

        app(ImportedTokenRecovery::class)->apply($plan, [
            'route' => ImportedTokenRecovery::ROUTE_REPLACEMENT,
            'token' => 'tok-fresh',
            'customer_uid' => 'cust-1',
            'recurring_live' => false,
        ]);

        $plan->refresh();

        $this->assertSame(PlanStatus::AWAITING_PAYMENT->value, $plan->status->value);
        $this->assertNull($plan->payment_failed_at, 'our hold is lifted');
        $this->assertContains($plan->status->value, PlanStatus::chargeable(), 'the scheduler must be able to see it');

        // The slot has to be waiting again, or the scheduler skips it and the
        // failed-charges screen files it under no group at all.
        $payment->refresh();
        $this->assertSame(PaymentStatus::RETRY_SCHEDULED, $payment->status);
        $this->assertSame(0, (int) $payment->attempt_count, 'a different card gets its own ladder');
        $this->assertNotNull($payment->next_retry_at);
    }

    /**
     * A NEW CARD MEANS A CHARGE ATTEMPT, NOW.
     *
     * Leaving it to the scheduler was technically enough — the plan is chargeable
     * and its slot is waiting — but that is how a merchant ends up watching a
     * screen wondering whether anything happened, which is what they did.
     */
    public function test_attaching_a_card_queues_a_charge(): void
    {
        Queue::fake();

        $plan = $this->heldPlan();

        app(ImportedTokenRecovery::class)->apply($plan, [
            'route' => ImportedTokenRecovery::ROUTE_REPLACEMENT,
            'token' => 'tok-fresh',
            'customer_uid' => 'cust-1',
            'recurring_live' => false,
        ]);

        Queue::assertPushed(
            ChargeJob::class,
            fn (ChargeJob $job): bool => $job->planId === (int) $plan->getKey(),
        );
    }

    /**
     * EXCEPT when PayPlus is still billing them itself. The card is fixed and the
     * plan is live again, but asking here would take the money twice.
     */
    public function test_a_member_payplus_still_bills_is_not_charged_here(): void
    {
        Queue::fake();

        $plan = $this->heldPlan();

        app(ImportedTokenRecovery::class)->apply($plan, [
            'route' => ImportedTokenRecovery::ROUTE_REPLACEMENT,
            'token' => 'tok-fresh',
            'customer_uid' => 'cust-1',
            'recurring_live' => true,
        ]);

        Queue::assertNotPushed(ChargeJob::class);

        // The card IS still fixed and the hold IS still lifted.
        $this->assertSame(PlanStatus::AWAITING_PAYMENT->value, $plan->fresh()->status->value);
    }

    /** The debt does NOT move. It is still owed on the day it was owed. */
    public function test_reviving_a_member_does_not_move_the_date_they_owe(): void
    {
        $owed = now()->subDays(4)->startOfDay();

        $plan = $this->heldPlan();
        $plan->forceFill(['next_charge_at' => $owed])->save();

        app(ImportedTokenRecovery::class)->apply($plan, [
            'route' => ImportedTokenRecovery::ROUTE_REPLACEMENT,
            'token' => 'tok-fresh',
            'customer_uid' => 'cust-1',
            'recurring_live' => false,
        ]);

        $this->assertSame($owed->toDateString(), $plan->fresh()->next_charge_at->toDateString());
    }

    /**
     * A pause the CUSTOMER asked for is not ours to lift. The stamp is what tells
     * them apart, and finding a card is not permission to restart somebody's
     * subscription.
     */
    public function test_a_customer_requested_pause_survives_a_new_card(): void
    {
        $plan = $this->heldPlan();
        $plan->forceFill(['payment_failed_at' => null])->save(); // they asked to pause

        app(ImportedTokenRecovery::class)->apply($plan, [
            'route' => ImportedTokenRecovery::ROUTE_REPLACEMENT,
            'token' => 'tok-fresh',
            'customer_uid' => 'cust-1',
            'recurring_live' => false,
        ]);

        $this->assertSame(PlanStatus::PAUSED->value, $plan->fresh()->status->value);
    }

    /** A cancelled subscription is not revived by finding a card. */
    public function test_a_cancelled_member_is_not_revived(): void
    {
        $plan = $this->heldPlan();
        $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

        app(ImportedTokenRecovery::class)->apply($plan, [
            'route' => ImportedTokenRecovery::ROUTE_REPLACEMENT,
            'token' => 'tok-fresh',
            'customer_uid' => 'cust-1',
            'recurring_live' => false,
        ]);

        $this->assertSame(PlanStatus::CANCELLED->value, $plan->fresh()->status->value);
    }

    /** A member held for an unpaid cycle, with a spent ladder. */
    private function heldPlan(): InstallmentPlan
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'revive-'.uniqid().'.example.com',
            'name' => 'Revive',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($shop);

        $method = InstallmentPaymentMethod::create([
            'payplus_card_token_uid' => 'tok-dead',
            'payplus_customer_uid' => 'cust-1',
            'card_brand' => 'visa',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.uniqid(),
            'customer_name' => 'Held',
            'customer_email' => 'held@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::PAUSED->value,
            'payment_failed_at' => now()->subDay(),
            'payment_method_id' => $method->getKey(),
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => now()->subDay(),
        ])->save();

        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $shop->getKey(),
            'plan_id' => $plan->getKey(),
            'sequence' => 1,
            'amount' => 39,
            'currency' => 'ILS',
            'status' => PaymentStatus::FAILED->value,
            'attempt_count' => 7,
            'failure_code' => '1',
            'failure_message' => 'כרטיס חסום',
        ])->save();

        return $plan;
    }

    /** @return array<string, mixed> */
    private function card(string $token, string $mmyy, ?string $addedAt = null): array
    {
        return array_filter([
            'token' => $token,
            'card_date_mmyy' => $mmyy,
            'last_4_digits' => substr($token, -4),
            'created_at' => $addedAt,
        ], static fn ($v): bool => $v !== null);
    }
}
