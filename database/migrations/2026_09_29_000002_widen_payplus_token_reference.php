<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payplus_token_reference` moves from plaintext `string` (varchar 255) to `text`
 * so the model can cast it `encrypted` (App\Models\InstallmentPaymentMethod) —
 * MEDIUM-5, 2026-09 security audit: this column is a fallback CHARGE token (the
 * imported `recurring_token`), and sat in plaintext next to its already-encrypted
 * sibling `payplus_card_token_uid`. Widened first because Laravel's `encrypted`
 * cast's ciphertext (base64 IV + MAC + value) comfortably exceeds 255 characters
 * even for a short plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_payment_methods', function (Blueprint $table): void {
            $table->text('payplus_token_reference')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('installment_payment_methods', function (Blueprint $table): void {
            $table->string('payplus_token_reference')->nullable()->change();
        });
    }
};
