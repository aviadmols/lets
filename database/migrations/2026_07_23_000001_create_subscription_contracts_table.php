<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local MIRROR of Shopify's SubscriptionContract — deliberately not a source of
 * truth, and the inversion that separates this rail from the PayPlus one.
 *
 * On the PayPlus rail, `installment_plans` + `payment_ledger` ARE the money truth:
 * we hold the token, we charge, we record. Here Shopify holds the card, Shopify
 * processes the payment, and the contract lives in Shopify. This table exists only
 * so the merchant screen, the due-cycle scanner and the customer's personal area
 * can read without an API round trip per row. Shopify always wins a disagreement;
 * `synced_at` says how stale our copy is.
 *
 * Scoped to the app that OWNS the contract: `write_own_subscription_contracts`
 * means contracts our app created, so nothing here can ever mirror a contract
 * belonging to Shopify Subscriptions or another app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Shopify's ids. The GID is the handle every mutation takes.
            $table->string('shopify_gid')->index();
            $table->string('shopify_customer_gid')->nullable()->index();

            // ACTIVE | PAUSED | CANCELLED | EXPIRED | FAILED — Shopify's vocabulary,
            // mirrored verbatim rather than mapped onto PlanStatus. Two different
            // state machines owned by two different systems must not share an enum.
            $table->string('status', 32)->default('ACTIVE');

            // The billing cadence, for display and for the due-cycle scan.
            $table->unsignedInteger('interval_count')->default(1);
            $table->string('interval', 16)->nullable();   // DAY | WEEK | MONTH | YEAR
            $table->timestamp('next_billing_date')->nullable();

            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            // Denormalised for the list screens; never for money decisions.
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->json('lines')->nullable();

            // How stale this copy is. A row we have never synced is not trustworthy.
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One mirror row per contract per shop.
            $table->unique(['shop_id', 'shopify_gid']);
            // The scanner's hot path: contracts due for billing, per shop.
            $table->index(['shop_id', 'status', 'next_billing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_contracts');
    }
};
