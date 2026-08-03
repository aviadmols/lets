<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which language the shop's CUSTOMERS read their email in.
 *
 * Not the merchant's admin language — an Israeli merchant may run an
 * English-speaking store, and until now every default template was hard-coded
 * Hebrew with `dir="rtl"` baked into the layout.
 *
 * Defaults to `he`, which is what every existing shop is already sending, so the
 * column changes nothing until a merchant chooses otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->string('email_locale', 8)->default('he');
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn('email_locale');
        });
    }
};
