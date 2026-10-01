<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Models\MerchantBillingSettings;

/**
 * Payments › Recovery — the numbers (spec §3.2), all from PaymentJourneys:
 * the two recovery stages, what each recovery strategy handled and brought
 * back, the retry-vs-card-update split (and its trend), recovery by retry
 * number and by decline reason.
 *
 * Strategies LETS actually runs: the retry ladder (the shop's max attempts,
 * MerchantBillingSettings) and the card-update link (card_update_link_sent /
 * card_updated on the Timeline). A backup card does not exist in LETS — the
 * screen lists it as not tracked rather than as a zero.
 */
final class PaymentsRecoveryQuery
{
    // === CONSTANTS ===
    public const CHART_TREND = 'recovery_trend';

    public const STRATEGY_RETRY = 'retry_ladder';

    public const STRATEGY_CARD_UPDATE = 'card_update';

    public function __construct(private readonly Context $context) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_TREND, Granularity::WEEKLY);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $period = $this->context->period;
        $journeys = (new JourneyStats(PaymentJourneys::for($period, $this->context->filters)))->in($period);
        $recovery = $journeys->recovery();

        return [
            'first_cycle' => $journeys->cycle(JourneyStats::CYCLE_FIRST),
            'subsequent_cycle' => $journeys->cycle(JourneyStats::CYCLE_SUBSEQUENT),
            'recovery' => $recovery,
            'strategies' => $this->strategies($journeys),
            'max_attempts' => $this->maxAttempts(),
            'by_retry' => $journeys->byRetry(),
            'by_reason' => $journeys->recoveryByReason(),
            'trend' => $journeys->recoverySeries($period, self::grain($this->context)),
            'has_data' => $recovery['failed'] > 0,
        ];
    }

    /** @return list<array{key: string, failed: int, under: int, recovered: int, rate: ?float}> */
    private function strategies(JourneyStats $journeys): array
    {
        $failed = array_values(array_filter(
            $journeys->all(),
            static fn (array $j): bool => $j['failures'] > 0 && $j['source'] === PaymentJourneys::SOURCE_PAYPLUS,
        ));
        $carded = array_values(array_filter($failed, static fn (array $j): bool => $j['card_path']));

        $row = static function (string $key, array $rows, string $via): array {
            $recovered = count(array_filter($rows, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::RECOVERED && $j['via'] === $via));

            return [
                'key' => $key,
                'failed' => count($rows),
                'under' => count(array_filter($rows, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::UNDER_RECOVERY)),
                'recovered' => $recovered,
                'rate' => $rows === [] ? null : round($recovered / count($rows) * 100, 1),
            ];
        };

        return [
            $row(self::STRATEGY_RETRY, $failed, PaymentJourneys::VIA_RETRY),
            $row(self::STRATEGY_CARD_UPDATE, $carded, PaymentJourneys::VIA_CARD_UPDATE),
        ];
    }

    private function maxAttempts(): int
    {
        return MerchantBillingSettings::query()->first()?->maxChargeAttempts()
            ?? max(1, (int) config('payplus.retry_daily_attempts', 7));
    }
}
