<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only upsell analytics. One row per funnel event: an offer shown
 * (impression), accepted, declined, then the token charge succeeded/failed.
 * Revenue is stamped on charge_succeeded. `context` is masked JSON (never raw
 * card data). The numbers behind the admin Post-Purchase Offers KPI cards +
 * Performance funnel (UpsellMetrics reads this). Tenant-scoped.
 *
 * The upsell sibling of `payment_ledger` for analytics — the ledger remains the
 * money truth; this is the conversion/funnel truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_offer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('upsell_flows')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('upsell_flow_offers')->cascadeOnDelete();

            // Optional plan linkage when the source purchase is a plan.
            $table->unsignedBigInteger('plan_id')->nullable();
            // Optional ledger linkage for the charge events.
            $table->unsignedBigInteger('payment_ledger_id')->nullable();

            // impression|accepted|declined|charge_succeeded|charge_failed
            $table->string('event_type');

            $table->decimal('revenue_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('ILS');

            // Correlation keys so funnel steps for ONE customer/offer line up.
            $table->string('parent_order_id')->nullable();
            $table->string('customer_ref')->nullable();

            $table->json('context')->nullable(); // masked

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only: no updated_at

            $table->index(['shop_id', 'flow_id', 'event_type']);
            $table->index(['shop_id', 'event_type', 'occurred_at']);
            $table->index(['shop_id', 'offer_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_offer_events');
    }
};
