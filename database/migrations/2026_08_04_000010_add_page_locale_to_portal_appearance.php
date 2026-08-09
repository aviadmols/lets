<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language of the shopper's personal area, per shop.
 *
 * It had none: the area borrowed the LOYALTY club's page language, which is the
 * wrong owner twice over — a shop with no club still got its default (Hebrew),
 * and a merchant who wanted an English account area had to change a members-club
 * setting to get it.
 *
 * NULL means "follow the store" — the WordPress site language the plugin sends —
 * which is why the column is nullable rather than defaulted to a language.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'merchant_portal_appearance';
    private const COLUMN = 'page_locale';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string(self::COLUMN, 5)->nullable()->after('card_style');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
