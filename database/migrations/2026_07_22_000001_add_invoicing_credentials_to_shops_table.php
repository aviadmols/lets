<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Green Invoice (Morning) invoicing — the per-shop ENCRYPTED credential bag.
 *
 * A third sibling of payplus_credentials / woocommerce_credentials: one `text`
 * column carrying the EncryptedCredentials cast, holding
 * {provider, api_key_id, api_secret, environment}. Nullable + additive, so every
 * existing shop row stays valid and the module is simply "not connected" until
 * the merchant pastes their keys.
 *
 * Credentials NEVER live in env/config — they are per-tenant secrets, read once
 * per job by InvoiceProviderFactory::for($shop) and held as constructor state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->text('invoicing_credentials')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('invoicing_credentials');
        });
    }
};
