<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The offer stops being a target and becomes a PROMOTION.
 *
 * Everything about WHAT is sold moves to account_offer_targets (the previous
 * migration): the template, the add/replace decision and the replacement timing
 * are per-target answers, and an offer can now carry several. What stays here is
 * everything about the promotion itself — its name, whether it is switched on,
 * who sees it, when, where on the page, how it looks, and how often it has been
 * taken.
 *
 * Before the columns drop, every existing single-target offer is carried into
 * account_offer_targets as position 1. This was first written as a clean drop
 * ("zero rows in production") — and between writing and shipping, a merchant
 * created offer #1. A migration that assumes emptiness must still be correct
 * when the assumption dies: the carry is a no-op on an empty table and a
 * rescue on a non-empty one.
 *
 * `down()` restores the columns so the migration is reversible, but it cannot
 * restore a target: the information now lives in a table this rollback drops.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Carry: one target row per offer that still names a template, preserving
        // the offer's add/replace decision and timing. Raw table access on purpose
        // — models change, a migration must read the columns as they were.
        $now = now();
        DB::table('account_offers')
            ->whereNotNull('product_subscription_plan_id')
            ->orderBy('id')
            ->each(function (object $offer) use ($now): void {
                DB::table('account_offer_targets')->insert([
                    'shop_id' => $offer->shop_id,
                    'offer_id' => $offer->id,
                    'position' => 1,
                    'kind' => 'subscription',
                    'product_subscription_plan_id' => $offer->product_subscription_plan_id,
                    'quantity' => 1,
                    'mode' => $offer->mode ?? 'replace',
                    'replace_timing' => ($offer->mode ?? 'replace') === 'replace'
                        ? ($offer->replace_timing ?? 'immediate')
                        : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('account_offers', function (Blueprint $table): void {
            // WHAT is sold — now a list, in account_offer_targets.
            $table->dropConstrainedForeignId('product_subscription_plan_id');
        });

        Schema::table('account_offers', function (Blueprint $table): void {
            // HOW it lands — a per-target decision now.
            $table->dropColumn(['mode', 'replace_timing']);
        });
    }

    public function down(): void
    {
        Schema::table('account_offers', function (Blueprint $table): void {
            $table->foreignId('product_subscription_plan_id')
                ->nullable()
                ->constrained('product_subscription_plans')
                ->cascadeOnDelete();
            $table->string('mode', 10)->default('replace');
            $table->string('replace_timing', 20)->nullable();
        });
    }
};
