<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription that waits for its customer to start it.
 *
 * `requires_activation` on the product's subscription plan: the checkout still takes the
 * first cycle, but the subscription is held at `awaiting_activation` with no charge date
 * until the customer opens the activation link — and the next charge is counted from that
 * day, one cycle on. The link's nonce lives in the plan's meta; nothing else is stored.
 *
 * `plan_activation_*` on mail_settings: the email that carries the link is editable like
 * every other customer email.
 *
 * Additive, and false/null by default: every existing plan and email is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_subscription_plans', function (Blueprint $table): void {
            $table->boolean('requires_activation')->default(false)->after('expire_after_charges');
        });

        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->string('plan_activation_subject')->nullable();
            $table->text('plan_activation_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('requires_activation');
        });

        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->dropColumn(['plan_activation_subject', 'plan_activation_body']);
        });
    }
};
