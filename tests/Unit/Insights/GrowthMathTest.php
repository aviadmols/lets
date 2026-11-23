<?php

namespace Tests\Unit\Insights;

use App\Domain\Insights\GrowthMath;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind "how many keep paying", "is the book growing" and "if the pace
 * holds" — pinned with hand-written rows.
 */
final class GrowthMathTest extends TestCase
{
    public function test_passing_a_month_means_paying_the_next_one_too(): void
    {
        $today = CarbonImmutable::parse('2026-11-23');
        $group = [
            ['day' => '2026-09-15', 'paid' => 3],   // paid months 1, 2, 3
            ['day' => '2026-09-20', 'paid' => 1],   // paid month 1 only
            ['day' => '2026-11-10', 'paid' => 1],   // too new to be asked about month 2
        ];

        $ladder = GrowthMath::ladder($group, $today, graceDays: 5, months: 12);

        $this->assertSame(['month' => 1, 'eligible' => 2, 'passed' => 1], $ladder[0]);
        // Month 2 is due on 15/11 + 5 days grace = 20/11 for the first; 20/11 + 5 = 25/11 is not yet.
        $this->assertSame(['month' => 2, 'eligible' => 1, 'passed' => 1], $ladder[1]);
        $this->assertCount(2, $ladder, 'nobody has had the chance to pass month three yet');
    }

    public function test_the_book_is_rebuilt_backwards_from_today(): void
    {
        $book = GrowthMath::book(
            activeNow: 10,
            joinDays: ['2026-11-22', '2026-11-23', '2026-11-23'],
            leaveDays: ['2026-11-22'],
            start: CarbonImmutable::parse('2026-11-21'),
            today: CarbonImmutable::parse('2026-11-23'),
        );

        $this->assertSame([8, 8, 10], array_column($book, 'count'));
        $this->assertSame(['2026-11-21', '2026-11-22', '2026-11-23'], array_column($book, 'day'));
        $this->assertSame([0, 1, 2], array_column($book, 'joined'));
    }

    public function test_the_pace_carries_last_months_net_forward(): void
    {
        $today = CarbonImmutable::parse('2026-11-30');
        $pace = GrowthMath::pace(
            joinDays: ['2026-11-10', '2026-11-11', '2026-11-12', '2026-10-15'],
            leaveDays: ['2026-11-20'],
            activeNow: 100,
            activeAmount: 5000.0,
            today: $today,
            start: CarbonImmutable::parse('2026-09-01'),
        );

        $this->assertSame(3, $pace['joined']);
        $this->assertSame(1, $pace['left']);
        $this->assertSame(2, $pace['net']);
        // The month before had a net of +1: +100%.
        $this->assertSame(100, $pace['change']);
        $this->assertTrue($pace['comparable']);
        $this->assertSame([100, 102, 104], array_column($pace['steps'], 'subscribers'));
        $this->assertSame([5000.0, 5100.0, 5200.0], array_column($pace['steps'], 'revenue'));
    }

    public function test_a_period_before_the_start_is_not_a_fair_comparison(): void
    {
        $pace = GrowthMath::pace(['2026-11-20'], [], 1, 50.0, CarbonImmutable::parse('2026-11-30'), CarbonImmutable::parse('2026-11-01'));

        $this->assertFalse($pace['comparable']);
    }
}
