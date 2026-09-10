<?php

namespace Tests\Unit\Support;

use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * The card matcher that stands between a book of 1,300 migrated members and
 * their saved cards at PayPlus.
 *
 * Recovering a token means deciding "this PayPlus card belongs to that member",
 * and the only evidence available is a last-4 and an expiry. Neither is unique
 * on its own, so the rule is deliberately strict: BOTH must agree, and a tie
 * between two candidates is refused rather than broken. A wrong match here does
 * not fail loudly — it silently bills the wrong person's card every month.
 */
final class PayPlusTokenMatchTest extends TestCase
{
    // === CONSTANTS ===
    private const TOKEN_A = 'tok-aaaa';

    private const TOKEN_B = 'tok-bbbb';

    /** @return list<array<string, mixed>> */
    private function tokens(): array
    {
        return [
            ['token' => self::TOKEN_A, 'last_4_digits' => '9963', 'card_date_mmyy' => '0230'],
            ['token' => self::TOKEN_B, 'last_4_digits' => '6729', 'card_date_mmyy' => '0532'],
        ];
    }

    public function test_it_matches_on_last_four_and_expiry(): void
    {
        $hit = PayPlusTokenDiscovery::matchCard($this->tokens(), '9963', 2, 2030);

        $this->assertSame(self::TOKEN_A, $hit['token'] ?? null);
    }

    public function test_it_pads_a_single_digit_month(): void
    {
        // exp_month 5 must become "05", or a real card silently stops matching.
        $hit = PayPlusTokenDiscovery::matchCard($this->tokens(), '6729', 5, 2032);

        $this->assertSame(self::TOKEN_B, $hit['token'] ?? null);
    }

    public function test_last_four_alone_is_not_enough(): void
    {
        // Right digits, wrong expiry: a different card that happens to collide.
        $this->assertNull(PayPlusTokenDiscovery::matchCard($this->tokens(), '9963', 11, 2027));
    }

    public function test_it_refuses_when_we_hold_no_last_four(): void
    {
        // 148 imported methods have no last-4 at all. Guessing for them would
        // charge somebody else, so the matcher must decline.
        $this->assertNull(PayPlusTokenDiscovery::matchCard($this->tokens(), '', 2, 2030));
        $this->assertNull(PayPlusTokenDiscovery::matchCard($this->tokens(), null, 2, 2030));
    }

    public function test_it_refuses_when_expiry_is_unknown(): void
    {
        $this->assertNull(PayPlusTokenDiscovery::matchCard($this->tokens(), '9963', null, 2030));
        $this->assertNull(PayPlusTokenDiscovery::matchCard($this->tokens(), '9963', 2, null));
    }

    public function test_a_tie_is_refused_rather_than_broken(): void
    {
        // Two cards agreeing on both fields is a coin toss, not a match.
        $tie = [
            ['token' => self::TOKEN_A, 'last_4_digits' => '9963', 'card_date_mmyy' => '0230'],
            ['token' => self::TOKEN_B, 'last_4_digits' => '9963', 'card_date_mmyy' => '0230'],
        ];

        $this->assertNull(PayPlusTokenDiscovery::matchCard($tie, '9963', 2, 2030));
    }

    public function test_an_empty_token_list_matches_nothing(): void
    {
        $this->assertNull(PayPlusTokenDiscovery::matchCard([], '9963', 2, 2030));
    }

    // === The RELAXED matcher — same person's cards, no last-4 to lean on ===

    public function test_relaxed_takes_the_customers_only_card_without_a_last_four(): void
    {
        $only = [['token' => self::TOKEN_A, 'last_4_digits' => '9963', 'card_date_mmyy' => '0230']];

        $pick = PayPlusTokenDiscovery::matchCardRelaxed($only, null, null);

        $this->assertSame(self::TOKEN_A, $pick['card']['token'] ?? null);
        $this->assertSame('only_card', $pick['basis'] ?? null);
    }

    public function test_relaxed_picks_the_one_card_carrying_our_expiry(): void
    {
        // Two cards, no last-4 held — but only one has the expiry we do.
        $pick = PayPlusTokenDiscovery::matchCardRelaxed($this->tokens(), 5, 2032);

        $this->assertSame(self::TOKEN_B, $pick['card']['token'] ?? null);
        $this->assertSame('expiry', $pick['basis'] ?? null);
    }

    public function test_relaxed_falls_back_to_the_only_unexpired_card(): void
    {
        $cards = [
            ['token' => self::TOKEN_A, 'last_4_digits' => '1111', 'card_date_mmyy' => '0121'], // long expired
            ['token' => self::TOKEN_B, 'last_4_digits' => '2222', 'card_date_mmyy' => '1235'],
        ];

        // Our expiry matches neither, so expiry cannot decide; liveness can.
        $pick = PayPlusTokenDiscovery::matchCardRelaxed($cards, 6, 2028);

        $this->assertSame(self::TOKEN_B, $pick['card']['token'] ?? null);
        $this->assertSame('only_unexpired', $pick['basis'] ?? null);
    }

    public function test_relaxed_still_refuses_when_two_live_cards_cannot_be_told_apart(): void
    {
        $cards = [
            ['token' => self::TOKEN_A, 'last_4_digits' => '1111', 'card_date_mmyy' => '1235'],
            ['token' => self::TOKEN_B, 'last_4_digits' => '2222', 'card_date_mmyy' => '1236'],
        ];

        // Two live cards, neither with our expiry: a guess, so no.
        $this->assertNull(PayPlusTokenDiscovery::matchCardRelaxed($cards, 6, 2028));
    }

    public function test_relaxed_refuses_when_every_card_has_expired(): void
    {
        $cards = [
            ['token' => self::TOKEN_A, 'last_4_digits' => '1111', 'card_date_mmyy' => '0120'],
            ['token' => self::TOKEN_B, 'last_4_digits' => '2222', 'card_date_mmyy' => '0621'],
        ];

        // Even the "only card" rule does not apply: there are two, both dead.
        $this->assertNull(PayPlusTokenDiscovery::matchCardRelaxed($cards, 6, 2028));
    }

    public function test_relaxed_matches_nothing_on_an_empty_list(): void
    {
        $this->assertNull(PayPlusTokenDiscovery::matchCardRelaxed([], 2, 2030));
    }

    // === Cards pooled across a person's duplicate customer records ===

    public function test_the_same_card_vaulted_on_three_records_is_one_card(): void
    {
        // ישעיהו אברהם at PayPlus: one physical card, three customer records.
        $pooled = [
            ['token' => 'tok-rec2', 'last_4_digits' => '2175', 'card_date_mmyy' => '0126'],
            ['token' => 'tok-rec3', 'last_4_digits' => '2175', 'card_date_mmyy' => '0126'],
            ['token' => 'tok-rec4', 'last_4_digits' => '2175', 'card_date_mmyy' => '0126'],
        ];

        $cards = PayPlusTokenDiscovery::dedupeCards($pooled);

        $this->assertCount(1, $cards);
        $this->assertSame('tok-rec2', $cards[0]['token'], 'The first token seen is kept; any of them charges the same card.');

        // And so it counts as the customer's ONLY card, not a three-way choice.
        $this->assertSame('only_card', PayPlusTokenDiscovery::matchCardRelaxed($cards, null, null)['basis'] ?? null);
    }

    public function test_dedupe_keeps_genuinely_different_cards_and_drops_tokenless_rows(): void
    {
        $pooled = [
            ['token' => 'a', 'last_4_digits' => '0500', 'card_date_mmyy' => '0727'],
            ['token' => 'b', 'last_4_digits' => '9118', 'card_date_mmyy' => '0929'],
            ['token' => 'c', 'last_4_digits' => '0500', 'card_date_mmyy' => '0727'], // same as a
            ['token' => '', 'last_4_digits' => '1111', 'card_date_mmyy' => '0101'], // unusable
            ['token' => 'd', 'last_4_digits' => '0500', 'card_date_mmyy' => '0725'], // same digits, older expiry: different card
        ];

        $this->assertSame(['a', 'b', 'd'], array_column(PayPlusTokenDiscovery::dedupeCards($pooled), 'token'));
    }

    public function test_the_liveness_rule_is_withheld_when_cards_came_from_several_records(): void
    {
        // Two live-looking cards from two records under one email, neither with
        // our expiry. On a single record the only-unexpired rule could decide;
        // pooled across records it must not — the second record may be a
        // relative sharing the inbox.
        $pooled = [
            ['token' => self::TOKEN_A, 'last_4_digits' => '1111', 'card_date_mmyy' => '0121'], // expired
            ['token' => self::TOKEN_B, 'last_4_digits' => '2222', 'card_date_mmyy' => '1235'],
        ];

        $this->assertNotNull(PayPlusTokenDiscovery::matchCardRelaxed($pooled, 6, 2028, allowUnexpiredRule: true));
        $this->assertNull(PayPlusTokenDiscovery::matchCardRelaxed($pooled, 6, 2028, allowUnexpiredRule: false));
    }

    public function test_the_expiry_rule_still_decides_across_records(): void
    {
        // Natan Fartuk: two records, two cards, ours expires 04/30.
        $pooled = [
            ['token' => 'tok-1', 'last_4_digits' => '0044', 'card_date_mmyy' => '0430'],
            ['token' => 'tok-2', 'last_4_digits' => '1242', 'card_date_mmyy' => '0429'],
        ];

        $pick = PayPlusTokenDiscovery::matchCardRelaxed($pooled, 4, 2030, allowUnexpiredRule: false);

        $this->assertSame('tok-1', $pick['card']['token'] ?? null);
        $this->assertSame('expiry', $pick['basis'] ?? null);
    }
}
