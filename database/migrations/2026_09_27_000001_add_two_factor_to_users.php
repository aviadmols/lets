<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in with an authenticator app (TOTP) for the admin panel.
 *
 * - two_factor_secret          the shared TOTP secret, ENCRYPTED at rest (model cast).
 * - two_factor_recovery_codes  sha256 hashes of the one-time recovery codes; the
 *                              plain codes are shown once and never stored.
 * - two_factor_confirmed_at    set only after the owner proved the app works — a
 *                              secret without it is not enabled.
 * - two_factor_last_timestep   the 30-second step of the last accepted code, so an
 *                              observed code cannot be replayed inside its window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_timestep',
            ]);
        });
    }
};
