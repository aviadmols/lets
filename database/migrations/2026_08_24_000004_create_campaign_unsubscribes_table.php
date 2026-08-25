<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-shop SUPPRESSION LIST: addresses that asked not to receive campaigns.
 *
 * One row per (shop, address). Checked when an audience is built AND again in the
 * send job — a person who unsubscribes between enrolment and delivery must still
 * not be written to. Transactional plan mail (receipts, reminders, sign-in codes)
 * is unaffected: this list is about marketing, and the law draws the same line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_unsubscribes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Lower-cased, trimmed — the unique key compares bytes.
            $table->string('email');

            // Which campaign's link they used, when known (kept after its deletion).
            $table->foreignId('email_campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();

            // link | one_click | admin — how the request arrived.
            $table->string('source', 16);
            $table->char('ip_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'email'], 'campaign_unsubscribe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_unsubscribes');
    }
};
