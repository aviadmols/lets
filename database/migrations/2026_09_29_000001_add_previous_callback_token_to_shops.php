<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grace-period storage for callback-token rotation (App\Console\Commands\RotateCallbackTokens).
 *
 * `callback_token` / `wc_shop_token` route PayPlus's server-to-server callback to a
 * shop; old pages minted before PayPlusReturnRef carried them into the BROWSER-facing
 * return URL too. Rotating either value on its own would 404 any page a shopper (or
 * PayPlus) already holds a link for — including a card-update reminder sent days ago.
 * `previous_callback_token` + its expiry let a rotation mint a fresh token while the
 * OLD one keeps resolving for a bounded grace window (default 14 days), the same
 * pattern APP_KEY already has via APP_PREVIOUS_KEYS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('previous_callback_token', 64)->nullable()->after('callback_token');
            $table->timestamp('previous_callback_token_expires_at')->nullable()->after('previous_callback_token');
            $table->index('previous_callback_token');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropIndex(['previous_callback_token']);
            $table->dropColumn(['previous_callback_token', 'previous_callback_token_expires_at']);
        });
    }
};
