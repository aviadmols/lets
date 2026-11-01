<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SHELF layout's headline ("Thank you for your purchase", over the countdown),
 * merchant-editable like the grid's words: blank = the card's localized default.
 *
 * Nullable: no existing shop's thank-you page changes wording on upgrade alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->string('thanks_title', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->dropColumn('thanks_title');
        });
    }
};
