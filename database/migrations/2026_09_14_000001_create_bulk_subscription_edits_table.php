<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE row per bulk edit a merchant asked for — the receipt of a mass mutation.
 *
 * A bulk edit is the only act in this admin where a single click changes tens of
 * thousands of subscriptions, so it is the one act that must be answerable
 * afterwards: WHAT was asked for (operation + params), WHICH subscriptions it was
 * aimed at (criteria — the filter, stored verbatim), WHO asked, and what actually
 * happened to each of them (the counters). The per-subscription detail still lands
 * on each plan's own Timeline; this row is the group the merchant reads first.
 *
 * `cursor_id` is the resumable part. The run walks the matched set in id order and
 * commits each chunk — rows, audit and cursor — in ONE transaction, so a worker
 * killed mid-run leaves a run that resumes exactly where it committed, and a
 * retried chunk redoes work that was rolled back rather than doubling work that
 * landed. That property is what makes a non-idempotent operation (shift every date
 * by a week) safe to run over forty thousand rows on a queue that can restart.
 *
 * matched_count is frozen at request time — the count the merchant confirmed. The
 * run can legitimately process a different number (a subscription cancelled in the
 * meantime is skipped), and the gap between the two is itself worth seeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_subscription_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // The verb (BulkOperationRegistry key) and its validated parameters.
            $table->string('operation', 64);
            $table->json('params')->nullable();

            // The target set, as SubscriptionCriteria::toArray() — the filter the
            // merchant confirmed, re-applied by every chunk. Stored rather than
            // resolved to ids: forty thousand ids is not a job payload.
            $table->json('criteria')->nullable();

            // queued|running|completed|failed|cancelled
            $table->string('status', 16)->default('queued');

            // What the merchant was shown before confirming.
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);

            // What actually happened, updated per committed chunk.
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // The resume point: the highest plan id whose chunk committed.
            $table->unsignedBigInteger('cursor_id')->default(0);

            // WHO. The user row for a link, the actor string for the audit trail
            // (admin:{id} / platform_admin:{id}) — the same shape the Timeline uses.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('actor')->nullable();

            // The one-line English/Hebrew summary the screen showed, frozen so a
            // later reader sees what was asked for even if the copy changes.
            $table->string('summary', 500)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The screen lists a shop's recent runs, newest first.
            $table->index(['shop_id', 'created_at']);
            // The worker asks "is this run still going" by (shop, status).
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_subscription_edits');
    }
};
