<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account NAVIGATION, as its own list.
 *
 * `sections` says what the personal area's main column draws. It had been made
 * to mean a second thing as well — which TABS the store's account navigation
 * carries — and the two are simply different questions: a merchant may want the
 * club as a tab and not as a card on the dashboard, or the reverse. NULL here
 * means "never chosen", and the reader falls back to the sections list so every
 * existing shop keeps exactly the navigation it has today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_portal_appearance', function (Blueprint $table): void {
            $table->json('nav_tabs')->nullable()->after('sections');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_portal_appearance', function (Blueprint $table): void {
            $table->dropColumn('nav_tabs');
        });
    }
};
