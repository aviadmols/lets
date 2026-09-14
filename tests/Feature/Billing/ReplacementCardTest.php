<?php

namespace Tests\Feature\Billing;

use App\Domain\Installments\ImportedTokenRecovery;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
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
     * WITHOUT DATES IT REFUSES. If PayPlus stops sending the vaulted-at field (or
     * we never learned its real name), two candidates have no defensible order and
     * guessing would charge a card the customer may have retired.
     */
    public function test_several_candidates_with_no_dates_are_refused(): void
    {
        $pick = PayPlusTokenDiscovery::replacementCard(
            [$this->card('tok-a', '0530'), $this->card('tok-b', '0631')],
            heldToken: 'tok-ours',
            expMonth: 1,
            expYear: 2027,
        );

        $this->assertNull($pick, 'no order, no pick');
    }

    /** A tie on the timestamp is not an order either. */
    public function test_candidates_vaulted_at_the_same_moment_are_refused(): void
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

        $this->assertNull($pick);
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
