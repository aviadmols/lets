<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\PromptRepository;
use App\Models\PlatformAiSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic's Messages API, spoken only here.
 *
 * FORCED TOOL USE is the whole trick: the request carries one tool and
 * `tool_choice: {type: "tool"}`, so the model cannot answer in prose — it
 * answers through the schema or it fails, and the caller receives structured
 * data or a typed reason, never text to parse.
 *
 * The house client pattern (GreenInvoice/SendGrid): hand-rolled Http::, one
 * retry on an overload answer, typed failure constants, never throws to the
 * caller, and the key is never in a log line.
 */
final class AnthropicProvider implements AiProvider
{
    // === CONSTANTS ===
    private const MESSAGES_PATH = '/v1/messages';

    /** Statuses worth ONE more try — Anthropic's overload + the gateway blips. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 529];

    public function __construct(private readonly PromptRepository $prompts = new PromptRepository) {}

    public function complete(AiRequest $request): AiResult
    {
        $settings = PlatformAiSettings::current();
        $key = $settings->apiKey();

        if ($key === null) {
            return AiResult::failure(AiResult::FAIL_NO_KEY);
        }

        $prompt = $this->prompts->promptFor($request->stage);
        $model = $prompt['model'];

        $payload = [
            'model' => $model,
            'max_tokens' => (int) config('ai.stages.'.$request->stage.'.max_tokens', 2048),
            'temperature' => (float) config('ai.stages.'.$request->stage.'.temperature', 0.5),
            'system' => $prompt['system'],
            'messages' => $request->messages,
            'tools' => [[
                'name' => $request->toolName,
                'description' => 'The ONLY way to answer. Fill the schema; free text is refused.',
                'input_schema' => $request->toolSchema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $request->toolName],
        ];

        $started = hrtime(true);

        $response = $this->post($key, $payload);
        if ($response === null) {
            return AiResult::failure(AiResult::FAIL_TIMEOUT, $model, latencyMs: $this->elapsed($started));
        }

        // One considered retry: an overloaded provider often answers on the
        // second ask, and a merchant mid-chat deserves that one more try.
        if (in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
            $response = $this->post($key, $payload) ?? $response;
        }

        $latency = $this->elapsed($started);

        if (! $response->successful()) {
            Log::warning('ai.anthropic.call_failed', [
                'stage' => $request->stage,
                'status' => $response->status(),
            ]);

            return AiResult::failure(
                $response->status() === 429 || $response->status() === 529
                    ? AiResult::FAIL_REFUSED
                    : AiResult::FAIL_HTTP,
                $model,
                latencyMs: $latency,
            );
        }

        $body = (array) $response->json();
        $usage = (array) ($body['usage'] ?? []);
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        // The forced tool call, out of the content list.
        foreach ((array) ($body['content'] ?? []) as $piece) {
            if (is_array($piece) && ($piece['type'] ?? '') === 'tool_use' && is_array($piece['input'] ?? null)) {
                return AiResult::success($piece['input'], $inputTokens, $outputTokens, $latency, $model);
            }
        }

        return AiResult::failure(AiResult::FAIL_BAD_TOOL_OUTPUT, $model, $inputTokens, $outputTokens, $latency);
    }

    // === Internals ===

    /** @param array<string, mixed> $payload */
    private function post(string $key, array $payload): ?Response
    {
        try {
            return Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => (string) config('ai.providers.anthropic.version'),
            ])
                ->acceptJson()
                ->timeout((int) config('ai.providers.anthropic.timeout', 90))
                ->post(rtrim((string) config('ai.providers.anthropic.base_url'), '/').self::MESSAGES_PATH, $payload);
        } catch (\Throwable $e) {
            // The key is never in the message — only that the transport died.
            Log::warning('ai.anthropic.transport_error', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    private function elapsed(int|float $startedHr): int
    {
        return (int) ((hrtime(true) - $startedHr) / 1_000_000);
    }
}
