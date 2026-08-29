<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every state a studio document has ever been in — the undo, the audit trail,
 * and the wall against a destructive AI, all in one table.
 *
 * FULL SNAPSHOTS, not deltas, deliberately: a document is capped well under
 * 256KB of JSON and the table is pruned per campaign, so deltas would buy a
 * few megabytes at the price of a reconstruction path that can be wrong.
 * Restore is one read; "undo" is "restore the previous version", and a restore
 * is ITSELF a new version — history is only ever appended, never rewritten,
 * which is also what makes redo free.
 *
 * `cause` says who moved the document: a person's edit, an approved AI patch
 * (with the chat message that proposed it linked for the audit), a restore, or
 * the initial seed. Rows are immutable — created_at only, no updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();

            /** Copy of email_campaigns.document_version at snapshot time. */
            $table->unsignedInteger('version');

            /** The document, whole. */
            $table->json('document');

            // manual | ai_patch | restore | init
            $table->string('cause', 24);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /** The approved chat proposal behind an ai_patch version, for the audit. */
            $table->unsignedBigInteger('ai_chat_message_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // One row per version per campaign — the ladder has no duplicate rungs.
            $table->unique(['email_campaign_id', 'version'], 'campaign_document_version_unique');
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_document_versions');
    }
};
