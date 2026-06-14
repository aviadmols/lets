<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant table. One row per installed Shopify store, holding that store's
 * own ENCRYPTED PayPlus + Shopify credentials. Not scoped by BelongsToShop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_domain')->unique();
            $table->string('name')->nullable();
            $table->string('status')->default('installed')->index();
            $table->string('plan')->nullable();                 // SaaS tier
            $table->timestamp('trial_ends_at')->nullable();

            // Encrypted secrets (see App\Casts\EncryptedCredentials + 'encrypted' cast).
            $table->text('shopify_access_token')->nullable();
            $table->string('shopify_scopes')->nullable();
            $table->text('payplus_credentials')->nullable();    // encrypted JSON bag

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
