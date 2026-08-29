<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editable half of every AI stage's behaviour — the OWNER's prompts.
 *
 * config/ai.php carries the shipped defaults; a row here WINS for its stage,
 * and "reset to default" is deleting the row's text, not versioning it (the
 * spec's full prompt-version/test-case machinery is a later phase — the
 * DB-wins-else-config fallback is the same shape PlatformMailSettings uses
 * for its key). Platform-level, no tenant: prompts steer every shop's
 * generation and belong to the house.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table): void {
            $table->id();

            // draft_generator | block_editor | subject_writer | brand_analyzer
            $table->string('stage', 40)->unique();

            $table->longText('system_prompt')->nullable();

            /** Per-stage model override; null falls to settings/config. */
            $table->string('model', 64)->nullable();

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
