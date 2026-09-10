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
}
