<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop SMS provider credentials — the same shape as merchant_invoicing_settings:
 * the merchant brings their own account, we never send on a shared one. A shop with
 * no row (or an incomplete one) simply has no SMS channel, and SmsSenderFactory
 * returns null so every caller is a clean no-op.
 *
 * The token is encrypted at rest (cast on the model), never logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            $table->boolean('enabled')->default(false);
            $table->string('provider')->default('019');  // allow-listed in PHP
            $table->string('username')->nullable();      // 019 account name
            $table->text('api_token')->nullable();       // encrypted
            $table->string('sender', 11)->nullable();    // 019 caps the source at 11 chars

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_sms_settings');
    }
};
