<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which typeface the personal area is set in.
 *
 * Until now the answer was "the theme's, always" — the stylesheet carries no
 * font-family at all, on purpose, so the area belongs to the merchant's shop
 * rather than looking like a widget bolted onto it. That stays the DEFAULT, and
 * the default is the recommendation. The column exists for the merchant whose
 * theme font cannot carry a page of numbers and Hebrew labels, and who would
 * otherwise have no way to say so.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'merchant_portal_appearance';
    private const COLUMN = 'font_family';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string(self::COLUMN, 20)->default('theme')->after('card_style');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
