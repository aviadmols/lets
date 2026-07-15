<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop APPEARANCE for the post-purchase upsell card (Phase 3). One row per shop, mirroring
 * merchant_billing_settings / merchant_checkout_settings: shop_id unique + BelongsToShop, lazily
 * created with house-style defaults on first read (MerchantUpsellAppearance::current()).
 *
 * These are NOT secrets and NOT money — they tune the LOOK of the shared card only. The card's
 * price / CTA / consent disclosure are LOCKED (force-injected + enabled in the model accessor), so
 * a merchant can never design away the money, the buy button, or the legal disclosure.
 *
 * All enum-ish columns are plain strings validated by the model against a CONST allow-list (bad
 * input → the default), never a DB enum — same discipline as the sibling settings tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_upsell_appearance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();

            // Brand / theme.
            $table->string('theme_mode')->default('light');            // light | dark
            $table->string('accent_color', 7)->default('#000000');     // #rrggbb (house monochrome anchor)
            $table->string('accent_text_color', 7)->default('#ffffff');// text ON the accent (solid CTA)
            $table->string('button_style')->default('solid');          // solid | outline
            $table->string('corner_radius')->default('sharp');         // sharp | soft | pill (CTA radius)
            $table->string('card_shadow')->default('soft');            // none | soft | elevated
            $table->string('theme_font')->default('heebo');            // heebo | system (webfont vs host)

            // Layout.
            $table->string('layout')->default('stacked');              // stacked | media_side
            $table->string('image_ratio')->default('natural');         // natural | square
            $table->string('decline_style')->default('link');          // link | button

            // Ordered, toggleable element list [{key,enabled}] — the "builder". Nullable so a fresh
            // row falls back to DEFAULT_ELEMENTS via the accessor; the accessor also force-injects
            // the LOCKED_ELEMENTS (price/cta/disclosure) enabled, so a bad/edited value is safe.
            $table->json('elements')->nullable();

            // Reusable copy (blank → the localized default in the accessor).
            $table->string('eyebrow_text', 48)->nullable();
            $table->string('badge_text', 48)->nullable();
            $table->string('trust_text', 80)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_upsell_appearance');
    }
};
