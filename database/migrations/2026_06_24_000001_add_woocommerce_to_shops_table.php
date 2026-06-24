<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W11 Phase 0 — additive WooCommerce columns on `shops`. All nullable, so existing
 * Shopify rows are untouched and correct without a backfill:
 *   - woocommerce_domain   : the WC site host (the WC analogue of shopify_domain)
 *   - wc_shop_token        : opaque token in the WC webhook delivery URL (shop lookup
 *                            BEFORE HMAC verification)
 *   - woocommerce_credentials : encrypted bag {base_url, consumer_key, consumer_secret,
 *                            wc_webhook_secret} (EncryptedCredentials cast on the model)
 *   - lets_api_key_hash    : sha256 of the connection api_key the plugin signs with
 *                            (lookup key; the key itself is never stored)
 *   - lets_api_secret      : the per-shop HMAC secret (encrypted on the model)
 *
 * shopify_domain becomes NULLABLE so a WooCommerce shop (which has no *.myshopify.com
 * domain) is a valid row. Its UNIQUE index stays — multiple NULLs are allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('woocommerce_domain')->nullable()->unique();
            $table->string('wc_shop_token')->nullable()->unique();
            $table->text('woocommerce_credentials')->nullable();
            $table->string('lets_api_key_hash')->nullable()->index();
            $table->text('lets_api_secret')->nullable();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->string('shopify_domain')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'woocommerce_domain',
                'wc_shop_token',
                'woocommerce_credentials',
                'lets_api_key_hash',
                'lets_api_secret',
            ]);
        });
        // shopify_domain is left nullable on rollback (reverting to NOT NULL would
        // fail if any WooCommerce rows exist).
    }
};
