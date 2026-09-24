<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GRID layout of the post-purchase card: a bundle's products laid out in rows the
 * shopper sees at once (no slider), a headline beside a large countdown, and a bar
 * with the price and the button. Built for an offer with many products.
 *
 * `grid_columns` and `display_font` are its two tokens; the five text columns are the
 * words that exist only in that layout, merchant-editable and blank = the card's
 * localized default (the same rule eyebrow_text / trust_text follow).
 *
 * All nullable: no existing shop changes layout or wording on upgrade alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->unsignedTinyInteger('grid_columns')->nullable();
            $table->string('display_font', 12)->nullable();
            $table->string('timer_label', 48)->nullable();
            $table->string('timer_note', 120)->nullable();
            $table->string('facts_text', 160)->nullable();
            $table->string('picker_title', 48)->nullable();
            $table->string('picker_hint', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->dropColumn([
                'grid_columns', 'display_font',
                'timer_label', 'timer_note', 'facts_text', 'picker_title', 'picker_hint',
            ]);
        });
    }
};
