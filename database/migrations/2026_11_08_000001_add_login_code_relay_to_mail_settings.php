<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            /*
             * The personal area's sign-in code, sent through the merchant's own
             * Resend account — and ONLY that email.
             *
             * A sign-in code is the one email a shopper is standing in front of a
             * screen waiting for: a code that lands in spam, or ten minutes late,
             * is a customer who cannot get in. So a merchant may route just this
             * one through a relay they trust, without moving every receipt and
             * reminder off the platform's relay with it (that is what the full
             * SMTP override is for).
             *
             * The key is a credential: encrypted at rest by the model's cast, so
             * the column is text (ciphertext is longer than the key).
             */
            $table->boolean('login_code_via_resend')->default(false)->after('smtp_password');
            $table->text('login_code_resend_key')->nullable()->after('login_code_via_resend');
            $table->string('login_code_from_address')->nullable()->after('login_code_resend_key');
            $table->string('login_code_from_name')->nullable()->after('login_code_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'login_code_via_resend',
                'login_code_resend_key',
                'login_code_from_address',
                'login_code_from_name',
            ]);
        });
    }
};
