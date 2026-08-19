<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commitment: how many cycles a subscriber must pay before they may pause or
 * cancel it themselves.
 *
 * The merchant sets it per SUBSCRIPTION TEMPLATE, because it is a property of
 * the deal — "three months minimum" belongs to the plan that was sold, not to
 * the shop. NULL and 0 both mean no commitment, which is what every existing
 * template and every existing plan gets: a rule nobody agreed to must not
 * appear under people who already subscribed.
 *
 * The PLAN carries its own copy, snapshotted at birth beside pricing_mode and
 * discount_cycles and for the same reason (consent law): a merchant who later
 * raises the minimum to six has changed what they OFFER, not what a subscriber
 * already agreed to. Reading the template live would silently re-write the
 * terms of every live subscription, which is exactly the thing the snapshot
 * columns exist to prevent.
 *
 * It gates the CUSTOMER's own buttons only. The merchant can always cancel from
 * the admin, and support must never be unable to end a subscription.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TEMPLATES = 'product_subscription_plans';

    private const PLANS = 'installment_plans';

    private const COLUMN = 'min_cycles_before_exit';

    public function up(): void
    {
        Schema::table(self::TEMPLATES, function (Blueprint $table): void {
            // unsignedSmallInteger: a commitment is a handful of cycles, and a
            // number that cannot be negative cannot lock somebody out backwards.
            $table->unsignedSmallInteger(self::COLUMN)->nullable()->after('discount_cycles');
        });

        Schema::table(self::PLANS, function (Blueprint $table): void {
            $table->unsignedSmallInteger(self::COLUMN)->nullable()->after('discount_cycles');
        });
    }

    public function down(): void
    {
        Schema::table(self::TEMPLATES, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });

        Schema::table(self::PLANS, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
