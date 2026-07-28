<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify no longer accepts NON-EXPIRING offline tokens on the Admin API:
 *   403 "[API] Non-expiring access tokens are no longer accepted…"
 * Every token minted by the legacy redirect-OAuth flow is one of those, so an
 * app holding one is fully cut off — no product sync, no selling plans, no
 * billing — with no signal until a call fails.
 *
 * Expiring tokens must therefore be re-minted before they lapse, which needs
 * their expiry recorded. A NULL expiry means "we do not know", and is treated as
 * "refresh at the next opportunity" — that is what migrates the legacy tokens
 * already in the table without anyone having to reinstall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->timestamp('shopify_token_expires_at')->nullable()->after('shopify_scopes');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('shopify_token_expires_at');
        });
    }
};
