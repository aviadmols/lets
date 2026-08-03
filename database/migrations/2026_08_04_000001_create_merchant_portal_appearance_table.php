<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the shopper's personal area looks and what it shows — one row per shop.
 *
 * Enum-ish columns are plain strings validated in PHP against a CONST allow-list,
 * never DB enums: a DB enum turns a product decision into a migration. `sections`
 * and `banners` are JSON because both are ordered, merchant-authored lists whose
 * shape the model sanitises on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_portal_appearance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // Appearance. No font column on purpose — the area inherits the theme's.
            $table->string('accent_color', 7)->default('#111827');
            $table->string('accent_text_color', 7)->default('#ffffff');
            $table->string('theme_mode')->default('light');    // light | dark | auto
            $table->string('corner_radius')->default('soft');  // sharp | soft | pill
            $table->string('density')->default('comfortable'); // compact | comfortable
            $table->string('card_style')->default('outlined'); // flat | outlined | raised

            // Which sections show, in what order. [{key, enabled}]
            $table->json('sections')->nullable();

            // The side rail. [{heading, subtext, image_url, link_url, enabled}]
            $table->json('banners')->nullable();

            // Passwordless sign-in.
            $table->boolean('login_code_enabled')->default(false);
            $table->string('login_code_channel')->default('email'); // email | sms | both

            // Merchant copy (nullable ⇒ inherit the translated default).
            $table->string('welcome_heading')->nullable();
            $table->string('welcome_subtext', 500)->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_url', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_portal_appearance');
    }
};
