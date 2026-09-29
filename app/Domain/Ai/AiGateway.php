<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiUsageEvent;
use App\Domain\Ai\Providers\AiProviderFactory;
use App\Models\PlatformAiSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The ONE door to a model. Everything above it (the chat, the brand analyzer,
 * whatever comes next) calls complete() and receives structured data or a
 * typed failure — and pays its way through the ledger either way.
 *
 * Order of walls, cheapest first: the kill switch, the key, the shop's per-minute
 * rate, then the BUDGETS — the platform's daily cap AND this shop's own daily cap
 * — and only then the provider.
 *
 * RESERVE, THEN CALL. The budget used to be check-then-call: parallel requests
 * all read "under budget" together and all went through. Now the call's usage
 * row is written BEFORE the provider is asked, holding the call's worst-case
 * cost (reserved_tokens), under one lock — so the check and the claim are one
 * step and N parallel calls cannot overspend a budget one of them fits in. The
 * row is settled with the real usage afterwards. The platform cap is no longer
 * optional: unset, a conservative default applies. One merchant looping the
 * studio now runs out of THEIR quota, not everyone's.
 *
 * The usage event is recorded WIN OR LOSE: recording only successes is how an
 * outage becomes invisible in the ledger, and how a budget gets quietly poked
 * through by failed-but-billed calls. Never throws.
 */
final class AiGateway
{
    // === CONSTANTS ===
    /** Platform daily token cap when none is configured — the cap is mandatory. */
    public const DEFAULT_PLATFORM_DAILY_TOKENS = 2_000_000;

    /** One shop's daily token cap when none is configured (config ai.budget.shop_daily_tokens). */
    public const DEFAULT_SHOP_DAILY_TOKENS = 250_000;

    /** Calls one shop may make per minute. */
    public const SHOP_CALLS_PER_MINUTE = 20;

    /** Output reservation when a stage names no max_tokens. */
    private const DEFAULT_MAX_OUTPUT_TOKENS = 2048;

    /** Rough input estimate: characters per token, plus a fixed allowance for system prompt + tool schema. */
    private const CHARS_PER_TOKEN = 3;

    private const INPUT_OVERHEAD_TOKENS = 1500;

    private const LOCK_KEY = 'ai:budget:reserve';

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    private const RATE_KEY_PREFIX = 'ai:shop-rate:';

    public function complete(AiRequest $request): AiResult
    {
        $settings = PlatformAiSettings::current();

        if (! $settings->isEnabled()) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_DISABLED));
        }

        if (! $settings->isConnected()) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_NO_KEY));
        }

        if (! RateLimiter::attempt(self::RATE_KEY_PREFIX.$request->shopId, self::SHOP_CALLS_PER_MINUTE, static fn (): bool => true, 60)) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_RATE_LIMITED));
        }

        $reservation = $this->reserve($request, $settings);
        if ($reservation === null) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_OVER_BUDGET));
        }

        try {
            $result = AiProviderFactory::current()->complete($request);
        } catch (\Throwable $e) {
            Log::warning('ai.provider_threw', ['shop_id' => $request->shopId, 'error' => $e->getMessage()]);
            $result = AiResult::failure(AiResult::FAIL_HTTP);
        }

        return $this->settle($reservation, $result);
    }

    /** The platform's daily cap — the saved/env value, else the mandatory default. */
    public static function platformDailyCap(PlatformAiSettings $settings): int
    {
        return $settings->dailyTokenBudget() ?? self::DEFAULT_PLATFORM_DAILY_TOKENS;
    }

    /** One shop's daily cap. */
    public static function shopDailyCap(): int
    {
        $configured = config('ai.budget.shop_daily_tokens');

        return $configured !== null && (int) $configured > 0 ? (int) $configured : self::DEFAULT_SHOP_DAILY_TOKENS;
    }

    /**
     * Claim this call's worst case against both budgets, atomically: the check
     * and the reservation row happen under one lock. Null = over a budget (or
     * the lock could not be had — refusing is the safe answer for spend).
     */
    private function reserve(AiRequest $request, PlatformAiSettings $settings): ?AiUsageEvent
    {
        $estimate = $this->estimate($request);

        try {
            return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                function () use ($request, $settings, $estimate): ?AiUsageEvent {
                    if (AiUsageEvent::platformTokensToday() + $estimate > self::platformDailyCap($settings)) {
                        return null;
                    }

                    if (AiUsageEvent::shopTokensToday($request->shopId) + $estimate > self::shopDailyCap()) {
                        return null;
                    }

                    $event = new AiUsageEvent;
                    $event->forceFill([
                        'shop_id' => $request->shopId,
                        'email_campaign_id' => $request->campaignId,
                        'stage' => $request->stage,
                        'provider' => PlatformAiSettings::current()->provider,
                        'model' => '',
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'reserved_tokens' => $estimate,
                        'latency_ms' => 0,
                        'status' => AiUsageEvent::STATUS_RESERVED,
                    ])->save();

                    return $event;
                },
            );
        } catch (LockTimeoutException) {
            Log::warning('ai.budget_lock_timeout', ['shop_id' => $request->shopId]);

            return null;
        }
    }

    /** Worst-case tokens for this call: the stage's output ceiling plus an input estimate. */
    private function estimate(AiRequest $request): int
    {
        $output = (int) config('ai.stages.'.$request->stage.'.max_tokens', self::DEFAULT_MAX_OUTPUT_TOKENS);
        $chars = mb_strlen((string) json_encode($request->messages, JSON_UNESCAPED_UNICODE));

        return max(1, $output) + intdiv($chars, self::CHARS_PER_TOKEN) + self::INPUT_OVERHEAD_TOKENS;
    }

    /** Replace the reservation with what the call really cost. */
    private function settle(AiUsageEvent $event, AiResult $result): AiResult
    {
        $event->forceFill([
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'reserved_tokens' => 0,
            'latency_ms' => $result->latencyMs,
            'status' => $this->statusFor($result),
            'failure_reason' => $result->failureReason,
        ])->save();

        return $result;
    }

    /** The ledger row for a call refused before any reservation — then the result, untouched. */
    private function record(AiRequest $request, AiResult $result): AiResult
    {
        $event = new AiUsageEvent;
        $event->forceFill([
            'shop_id' => $request->shopId,
            'email_campaign_id' => $request->campaignId,
            'stage' => $request->stage,
            'provider' => PlatformAiSettings::current()->provider,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_ms' => $result->latencyMs,
            'status' => $this->statusFor($result),
            'failure_reason' => $result->failureReason,
        ])->save();

        return $result;
    }

    private function statusFor(AiResult $result): string
    {
        return match ($result->failureReason) {
            null => AiUsageEvent::STATUS_OK,
            AiResult::FAIL_OVER_BUDGET => AiUsageEvent::STATUS_OVER_BUDGET,
            AiResult::FAIL_RATE_LIMITED => AiUsageEvent::STATUS_RATE_LIMITED,
            AiResult::FAIL_REFUSED => AiUsageEvent::STATUS_REFUSED,
            default => AiUsageEvent::STATUS_FAILED,
        };
    }
}
