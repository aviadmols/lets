<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio chat — and the AI patch pipeline's source of truth.
 *
 * An assistant row IS the proposal: the job writes the validated ops onto it,
 * the screen polls it by run_id, the merchant approves or discards it, and
 * `base_version` is what makes an approval honest — ops computed against
 * yesterday's document go STALE, they are never merged over someone's newer
 * work. A DB row rather than a cache entry because the proposal must survive
 * navigation, worker restarts and a merchant who reviews the diff an hour
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();

            // user | assistant
            $table->string('role', 16);

            // user rows: sent. assistant rows: pending | running | proposed |
            // applied | discarded | stale | failed
            $table->string('status', 24);

            /** The poll's handle; unique per assistant row. */
            $table->string('run_id', 32)->nullable()->index();

            /** The user's text / the assistant's Hebrew explanation. */
            $table->longText('content')->nullable();

            /** The VALIDATED proposed ops; null until proposed. */
            $table->json('ops')->nullable();

            /** document_version the ops were computed against (the stale check). */
            $table->unsignedInteger('base_version')->nullable();

            /** The version an approval created. */
            $table->unsignedInteger('applied_version')->nullable();

            $table->string('failure_reason', 64)->nullable();

            /** The block the user had selected — the edit's scope. */
            $table->string('selected_block_id', 32)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['email_campaign_id', 'id']); // the chat history scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
