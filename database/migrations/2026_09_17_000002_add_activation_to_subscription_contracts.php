<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activation links on the Shopify Payments rail.
 *
 * A contract Shopify creates from a plan with `requires_activation` is held: paused at
 * Shopify, and marked here as awaiting its customer. These three columns are LETS state,
 * not mirrored Shopify data — ContractMirror never writes them, so a webhook can never
 * clear a hold.
 *
 *   activation_nonce        the link's per-contract secret; a new one revokes every old link
 *   awaiting_activation_at  set once, when the contract is held (null = never held)
 *   activated_at            set once, when the customer (or merchant) starts it
 *
 * Additive and null by default: every existing contract is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_contracts', function (Blueprint $table): void {
            $table->string('activation_nonce', 64)->nullable();
            $table->timestamp('awaiting_activation_at')->nullable();
            $table->timestamp('activated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_contracts', function (Blueprint $table): void {
            $table->dropColumn(['activation_nonce', 'awaiting_activation_at', 'activated_at']);
        });
    }
};
