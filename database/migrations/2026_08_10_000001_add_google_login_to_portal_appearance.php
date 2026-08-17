<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with Google, next to the one-time codes.
 *
 * The merchant pastes the OAuth client id of their OWN Google Cloud project —
 * a public identifier, not a secret, which is why it may ride the shell payload
 * to a logged-out browser. Its presence is what turns the button on: a client id
 * the button cannot verify against is the only thing a separate toggle could
 * add, and that is a misconfiguration, not a feature.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'merchant_portal_appearance';

    private const COLUMN = 'login_google_client_id';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string(self::COLUMN, 200)->nullable()->after('login_code_channel');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
