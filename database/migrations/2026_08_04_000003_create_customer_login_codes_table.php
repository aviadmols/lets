<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time sign-in codes for the shopper's personal area.
 *
 * Neither the code nor the destination is stored in the clear. The code is hashed
 * so a database read cannot sign anyone in; the destination is hashed so this table
 * is not a harvestable list of the shop's customer emails and phone numbers — we
 * only ever need to match a destination the caller already knows, never to read one
 * back. That is also why there is no unique index on the destination: rows are
 * short-lived, and lookup is by (shop_id, destination_hash) + not-yet-expired.
 *
 * `attempts` is on the ROW, not in a cache, so a restart cannot reset a brute force
 * halfway through, and the row itself is the record of how many guesses were spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('channel', 16);           // email | sms
            $table->string('destination_hash', 64);  // sha256(shop_id + normalised destination)
            $table->string('code_hash');             // password-hashed, never the digits
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'destination_hash', 'expires_at'], 'cust_login_codes_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_codes');
    }
};
