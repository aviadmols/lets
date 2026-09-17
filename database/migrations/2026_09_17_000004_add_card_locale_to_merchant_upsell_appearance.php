<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language of the post-purchase card's own words — "Choose 3 for ₪9.99", "No thanks",
 * the charge disclosure. The merchant's headline and button text are theirs and untouched.
 *
 * NULL for every existing shop = English, which is what every card spoke until now: no store
 * changes language on upgrade alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->string('card_locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->dropColumn('card_locale');
        });
    }
};
