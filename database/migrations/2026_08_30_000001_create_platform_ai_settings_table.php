<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PLATFORM's AI account — one row, no shop_id, the PlatformMailSettings
 * shape exactly: the key is the house's credential (encrypted at rest, env
 * fallback, never rendered back), the kill switch is the owner's one lever
 * over every shop's chat at once, and the daily budget is what makes "all
 * merchants get the studio" financially survivable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ai_settings', function (Blueprint $table): void {
            $table->id();

            /** Encrypted at rest via the model's `encrypted` cast. */
            $table->text('anthropic_api_key')->nullable();

            /** The active provider. v1 accepts only 'anthropic'. */
            $table->string('provider', 24)->default('anthropic');

            /** stage → model id overrides; null = config('ai.stages.*.model'). */
            $table->json('model_overrides')->nullable();

            /** Platform-wide tokens/day cap; null = uncapped. */
            $table->unsignedInteger('daily_token_budget')->nullable();

            /** The kill switch — independent of any per-plan gating. */
            $table->boolean('enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ai_settings');
    }
};
