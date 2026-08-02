<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's membership in one shop's club.
 *
 * There is no `customers` table in this app — a "customer" is the string
 * (shop_id, customer_ref) the whole codebase already groups on: the Shopify
 * numeric customer id, the WooCommerce user id, or a billing email for a guest.
 * This row is what makes that derived person a MEMBER, and its existence is the
 * opt-in wall: no row ⇒ no points accrue, however much they spend.
 *
 * points_balance / lifetime_points / lifetime_spend are CACHES of the append-only
 * loyalty_point_events ledger, moved only under a row lock by PointsEngine.
 * birthday is new personal data — it joins the privacy redaction/export path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('customer_ref');
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();

            $table->date('birthday')->nullable();
            $table->timestamp('birthday_set_at')->nullable(); // once-only guard
            $table->timestamp('joined_at')->nullable();

            $table->integer('points_balance')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->decimal('lifetime_spend', 12, 2)->default(0);

            $table->foreignId('tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();

            $table->timestamps();

            // One membership per person per shop — the accrual idempotency anchor.
            $table->unique(['shop_id', 'customer_ref']);
            $table->index(['shop_id', 'customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};
