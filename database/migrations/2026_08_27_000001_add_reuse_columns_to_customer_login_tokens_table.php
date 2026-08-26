<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign sign-in link is no longer burnt by its first click.
 *
 * The merchant's ask, and it is the right one for their customers: the same
 * person opens the email on their phone in the morning and on their laptop at
 * night, and "this link was already used" between those two moments reads as a
 * broken shop. The link now works for the campaign's whole TTL window
 * RE-ANCHORED at the first click (`consumed_at` becomes that anchor; the
 * expiry column is moved to first_use + window at that moment) — these two
 * columns keep the audit trail the single-use design used to get for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_login_tokens', function (Blueprint $table): void {
            $table->unsignedInteger('use_count')->default(0)->after('consumed_at');
            $table->timestamp('last_used_at')->nullable()->after('use_count');
        });
    }

    public function down(): void
    {
        Schema::table('customer_login_tokens', function (Blueprint $table): void {
            $table->dropColumn(['use_count', 'last_used_at']);
        });
    }
};
