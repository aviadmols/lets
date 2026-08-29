<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiUsageEvent;
use App\Domain\Ai\Providers\AiProviderFactory;
use App\Models\PlatformAiSettings;

/**
 * The ONE door to a model. Everything above it (the chat, the brand analyzer,
 * whatever comes next) calls complete() and receives structured data or a
 * typed failure — and pays its way through the ledger either way.
 *
 * Order of walls, cheapest first: the kill switch, the key, TODAY'S BUDGET
 * (a sum over the usage ledger against the platform cap — checked BEFORE the
 * provider is called, so an over-budget platform spends zero more), then the
 * provider. The usage event is recorded WIN OR LOSE: recording only successes
 * is how an outage becomes invisible in the ledger, and how a budget gets
 * quietly poked through by failed-but-billed calls.
 *
 * Never throws. An AI problem is a state the UI describes, not a 500.
 */
final class AiGateway
{
    public function complete(AiRequest $request): AiResult
    {
        $settings = PlatformAiSettings::current();

        if (! $settings->isEnabled()) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_DISABLED));
        }

        if (! $settings->isConnected()) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_NO_KEY));
        }

        $budget = $settings->dailyTokenBudget();
        if ($budget !== null && AiUsageEvent::platformTokensToday() >= $budget) {
            return $this->record($request, AiResult::failure(AiResult::FAIL_OVER_BUDGET));
        }

        return $this->record($request, AiProviderFactory::current()->complete($request));
    }

    /** The ledger row, win or lose — then the result, untouched. */
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
            'status' => match ($result->failureReason) {
                null => AiUsageEvent::STATUS_OK,
                AiResult::FAIL_OVER_BUDGET => AiUsageEvent::STATUS_OVER_BUDGET,
                AiResult::FAIL_REFUSED => AiUsageEvent::STATUS_REFUSED,
                default => AiUsageEvent::STATUS_FAILED,
            },
            'failure_reason' => $result->failureReason,
        ])->save();

        return $result;
    }
}
