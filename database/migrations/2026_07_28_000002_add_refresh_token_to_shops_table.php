<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An EXPIRING offline access token lives about a day. Background work — the
 * billing scheduler, webhook registration, product sync — runs on days the
 * merchant never opens the admin, so re-minting from a session token (the only
 * thing token exchange can use) is not available when it is most needed.
 *
 * Shopify issues a REFRESH token alongside every expiring access token exactly
 * for that case: it buys a new access token with no merchant present, and is
 * itself good for months. Storing it is what keeps a shop billable between
 * admin visits — without it the app is live only while someone is looking at it.
 *
 * The refresh token is a credential and is stored ENCRYPTED (the model's cast).
 * Its own expiry is recorded so an unusable one can be told apart from a missing
 * one: the first needs a merchant to open the app, the second is just a legacy
 * install that never had one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->text('shopify_refresh_token')->nullable()->after('shopify_token_expires_at');
            $table->timestamp('shopify_refresh_token_expires_at')->nullable()->after('shopify_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['shopify_refresh_token', 'shopify_refresh_token_expires_at']);
        });
    }
};
