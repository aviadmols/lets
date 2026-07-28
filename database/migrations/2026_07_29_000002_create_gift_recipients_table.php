<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE subscriber's place in one gift campaign — and the campaign's idempotency wall.
 *
 * Neither WooCommerce nor Shopify accepts an idempotency key on order creation, so
 * unlike a charge there is no platform-side protection against creating the same
 * gift twice. This row IS the protection: UNIQUE (campaign, source_type, source_id)
 * means a re-run cannot enrol the same subscriber a second time, and the row is
 * moved to `creating` BEFORE the API call so a redelivered job finds it and stops.
 *
 * `creating` is therefore a state a human resolves, never a retry: the order may
 * already exist in the store. A missing gift is a button click; a duplicate gift is
 * a package the merchant pays to ship twice. Same asymmetry the invoicing module
 * settles with `unresolved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('gift_campaign_id')->constrained('gift_campaigns')->cascadeOnDelete();

            // Which subscription earned the gift. Two rails, two tables — kept as a
            // (type, id) pair rather than two nullable FKs so the unique key below
            // stays one simple index.
            $table->string('source_type', 16);          // plan | contract
            $table->unsignedBigInteger('source_id');

            // Display snapshots: who this was, as we knew them at generation time.
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();

            // Audit: the cycle count that qualified them. Answers "why did they get
            // this?" months later, when the count has moved on.
            $table->unsignedInteger('cycles_at_generate')->default(0);

            // pending | creating | created | skipped | failed
            $table->string('status', 24)->default('pending');

            // Why a recipient was skipped or failed, as a code the UI translates:
            // no_address | address_access_pending | no_price | api_error
            $table->string('reason')->nullable();

            // customer_profile | origin_order — where the shipping address came from.
            $table->string('address_source', 24)->nullable();

            $table->string('external_order_id')->nullable();
            $table->char('currency', 3)->default('ILS');

            $table->timestamps();

            // THE idempotency wall — one enrolment per subscriber per campaign.
            $table->unique(['gift_campaign_id', 'source_type', 'source_id'], 'gift_recipient_unique');
            $table->index(['shop_id', 'status']);
            // The invoicing wall's lookup: "is this store order a gift?"
            $table->index('external_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_recipients');
    }
};
