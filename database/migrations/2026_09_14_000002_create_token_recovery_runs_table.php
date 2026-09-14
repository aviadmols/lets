<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_recovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // recover | recover_and_charge — whether a fixed card is also billed.
            $table->string('mode', 24);

            // The SELECTED set, as an explicit list of plan ids.
            //
            // The bulk editor stores a criteria filter instead, because forty
            // thousand ids is not a job payload. This is the opposite case: a
            // merchant ticking rows on a recovery screen has chosen PEOPLE, not a
            // rule, and re-running a filter later would silently pick up members
            // who failed after they clicked.
            $table->json('plan_ids');

            // queued|running|completed|failed|cancelled
            $table->string('status', 16)->default('queued');

            $table->unsignedInteger('total')->default(0);

            // The resume point: how many of plan_ids have been committed.
            $table->unsignedInteger('cursor')->default(0);

            // What happened, committed per member alongside the cursor.
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('fixed')->default(0);
            $table->unsignedInteger('already_valid')->default(0);
            $table->unsignedInteger('ambiguous')->default(0);
            $table->unsignedInteger('no_last_four')->default(0);
            $table->unsignedInteger('not_found')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            // Charged without a lookup: their token was never in doubt.
            $table->unsignedInteger('not_probed')->default(0);
            $table->unsignedInteger('charges_queued')->default(0);

            // THE ONE THING THAT MUST NOT BE A NUMBER: members PayPlus is still
            // billing on its own schedule, who must never be charged from here.
            $table->json('double_billing')->nullable();

            // WHO — user row for a link, actor string for the audit trail.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('actor')->nullable();

            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The screen asks "is a run going, and what did the last one say?"
            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_recovery_runs');
    }
};
