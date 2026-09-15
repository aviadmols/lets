<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One "export the gift list with addresses" request, walked by a worker.
         *
         * It used to be built inside the click: one store read per recipient, a
         * 20-second budget and a 500-row cap, so a real list came back cut short —
         * or the request died at the proxy and nothing came back at all.
         */
        Schema::create('gift_export_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // The rule as it stood on the screen at the click — the file must
            // agree with the preview the merchant was looking at.
            $table->unsignedInteger('min_cycles');
            $table->json('product_ids')->nullable();
            $table->json('emails')->nullable();

            // queued|running|completed|failed
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
        });

        /*
         * The file's lines, one per recipient, in the order the file prints them.
         *
         * Short-lived BY DESIGN. The app keeps no copy of a customer's address —
         * it reads it from the store when it needs it — so these rows exist only
         * between the worker writing them and the merchant downloading them: the
         * address fields are encrypted, a new export deletes the shop's previous
         * rows, and the hourly prune removes anything older than a day.
         */
        Schema::create('gift_export_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('gift_export_runs')->cascadeOnDelete();
            $table->unsignedInteger('position');

            // Who, as the eligibility sweep reported them (no address in it).
            $table->json('recipient');

            // The CSV line, ENCRYPTED — null until the worker resolved the address.
            // Null is also the resume point: a retried job picks up the first
            // row still without one.
            $table->text('fields')->nullable();

            $table->timestamps();

            $table->unique(['run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_export_rows');
        Schema::dropIfExists('gift_export_runs');
    }
};
