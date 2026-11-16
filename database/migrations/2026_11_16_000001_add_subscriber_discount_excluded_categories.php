<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscribers' discount: categories it must NOT touch. A product in (or under)
 * one of these never gets the discount, even when it also sits in a covered
 * category — e.g. "Books" covered, "Bestsellers" kept at full price.
 *
 * Nullable and empty by default, so every existing rule keeps working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->json('subscriber_discount_excluded_category_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('subscriber_discount_excluded_category_ids');
        });
    }
};
