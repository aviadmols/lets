<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform's AI account — ONE row, no tenant (the PlatformMailSettings
 * arrangement, applied to the model key).
 *
 * Deliberately NOT BelongsToShop: the credential is the house's, and every
 * shop's chat runs on it. The env var is the FALLBACK, never the rival — a
 * key saved on the screen wins, a deploy configured by variables alone keeps
 * working, and the key is encrypted at rest and never rendered back.
 */
class PlatformAiSettings extends Model
{
    // === CONSTANTS ===
    protected $table = 'platform_ai_settings';

    public const PROVIDER_ANTHROPIC = 'anthropic';

    public const PROVIDERS = [self::PROVIDER_ANTHROPIC];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'anthropic_api_key' => 'encrypted',
            'model_overrides' => 'array',
            'daily_token_budget' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The single row, created empty on first read — and REFRESHED after the
     * create, because a just-inserted model holds none of its database-default
     * columns and a raw read of one falls through Eloquent's magic getter into
     * relation resolution (the PlatformMailSettings lesson, kept).
     */
    public static function current(): self
    {
        $row = static::query()->first();

        if ($row !== null) {
            return $row;
        }

        $row = new self;
        $row->save();

        return $row->refresh();
    }

    // === The effective configuration (saved value, else env) ===

    public function apiKey(): ?string
    {
        $saved = trim((string) $this->anthropic_api_key);
        if ($saved !== '') {
            return $saved;
        }

        $env = trim((string) config('ai.providers.anthropic.api_key'));

        return $env !== '' ? $env : null;
    }

    public function isConnected(): bool
    {
        return $this->apiKey() !== null;
    }

    /** True when the owner typed the key here rather than into a deploy variable. */
    public function keyIsStored(): bool
    {
        return trim((string) $this->anthropic_api_key) !== '';
    }

    /** Is the whole AI surface on? The owner's kill switch AND the config gate. */
    public function isEnabled(): bool
    {
        return (bool) config('ai.enabled', true)
            && (bool) ($this->attributes['enabled'] ?? true);
    }

    /** The model one stage runs on: DB override → settings map → config. */
    public function modelFor(string $stage): string
    {
        $overrides = (array) ($this->model_overrides ?? []);

        $fromMap = trim((string) ($overrides[$stage] ?? ''));
        if ($fromMap !== '') {
            return $fromMap;
        }

        return (string) config('ai.stages.'.$stage.'.model', 'claude-sonnet-5');
    }

    /** The platform daily token cap: settings row → env fallback → uncapped. */
    public function dailyTokenBudget(): ?int
    {
        $saved = $this->attributes['daily_token_budget'] ?? null;
        if ($saved !== null && (int) $saved > 0) {
            return (int) $saved;
        }

        $env = config('ai.budget.platform_daily_tokens');

        return $env !== null && (int) $env > 0 ? (int) $env : null;
    }
}
