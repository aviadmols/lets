<?php

namespace App\Domain\Ai;

/**
 * What came back — the validated tool input, or a typed reason it did not.
 * Never an exception: an AI failure is a state the UI describes, not a 500.
 */
final readonly class AiResult
{
    // === CONSTANTS ===
    public const FAIL_NO_KEY = 'no_key';

    public const FAIL_DISABLED = 'disabled';

    public const FAIL_OVER_BUDGET = 'over_budget';

    public const FAIL_HTTP = 'http_error';

    public const FAIL_TIMEOUT = 'timeout';

    public const FAIL_REFUSED = 'refused';

    public const FAIL_BAD_TOOL_OUTPUT = 'bad_tool_output';

    /**
     * @param  array<string, mixed>|null  $toolInput  the tool call's input, when ok
     */
    private function __construct(
        public bool $ok,
        public ?array $toolInput,
        public ?string $failureReason,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public string $model,
    ) {}

    /** @param array<string, mixed> $toolInput */
    public static function success(array $toolInput, int $inputTokens, int $outputTokens, int $latencyMs, string $model): self
    {
        return new self(true, $toolInput, null, $inputTokens, $outputTokens, $latencyMs, $model);
    }

    public static function failure(string $reason, string $model = '', int $inputTokens = 0, int $outputTokens = 0, int $latencyMs = 0): self
    {
        return new self(false, null, $reason, $inputTokens, $outputTokens, $latencyMs, $model);
    }
}
