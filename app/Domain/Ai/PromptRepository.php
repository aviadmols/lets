<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiPrompt;
use App\Models\PlatformAiSettings;

/**
 * What one stage says to the model, and on which model it says it.
 *
 * The ladder, most deliberate first: the owner's ai_prompts row → the settings
 * row's model map → config/ai.php's shipped default. "Reset to default" is
 * clearing the row, and the default is always there to come back to — the
 * PlatformMailSettings fallback shape, applied to words.
 */
final class PromptRepository
{
    /**
     * @return array{system: string, model: string}
     */
    public function promptFor(string $stage): array
    {
        $row = AiPrompt::query()->where('stage', $stage)->first();

        $system = trim((string) ($row?->system_prompt ?? ''));
        if ($system === '') {
            $system = (string) config('ai.stages.'.$stage.'.system', '');
        }

        $model = trim((string) ($row?->model ?? ''));
        if ($model === '') {
            $model = PlatformAiSettings::current()->modelFor($stage);
        }

        return ['system' => $system, 'model' => $model];
    }

    /** The shipped default, for the admin's "reset" and its placeholder. */
    public function defaultFor(string $stage): string
    {
        return (string) config('ai.stages.'.$stage.'.system', '');
    }

    /** The owner's words for a stage; clearing them is the reset. */
    public function saveFor(string $stage, ?string $systemPrompt, ?string $model, ?int $userId): void
    {
        if (! in_array($stage, AiPrompt::STAGES, true)) {
            return;
        }

        AiPrompt::query()->updateOrCreate(
            ['stage' => $stage],
            [
                'system_prompt' => trim((string) $systemPrompt) !== '' ? trim((string) $systemPrompt) : null,
                'model' => trim((string) $model) !== '' ? trim((string) $model) : null,
                'updated_by_user_id' => $userId,
            ],
        );
    }
}
