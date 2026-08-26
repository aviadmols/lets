<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gifts section's heading, merchant-editable like the welcome heading: a
 * bookshop sends books, not "gifts", and the shelf should say so. NULL means
 * the lang default ("מתנות שקיבלתם מאיתנו" / "Gifts from us").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_portal_appearance', function (Blueprint $table): void {
            $table->string('gifts_heading')->nullable()->after('welcome_subtext');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_portal_appearance', function (Blueprint $table): void {
            $table->dropColumn('gifts_heading');
        });
    }
};
