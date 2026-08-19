<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer the merchant shows INSIDE a customer's personal area: "switch your
 * yearly membership to monthly", "add the coffee box to what you already get".
 *
 * The target is a subscription TEMPLATE (product_subscription_plan_id), never a
 * price typed here. A template already carries the product, the cadence, the
 * pricing mode and the discount, and it is the thing the merchant edits when the
 * price changes — a second copy of that number in this table would be a second
 * truth, and the one nobody remembers to update.
 *
 * No DB enums. `status`, `mode`, `replace_timing` and `placement` are strings
 * guarded by a PHP allow-list on the model (AccountOffer), the same shape every
 * other taxonomy column in this schema uses: adding a value is a deploy, not a
 * migration, and an unreadable value degrades to the safe default instead of
 * throwing at the driver.
 *
 * `audience` is a JSON bag of INCLUSION filters, each an empty-means-any list:
 *   {
 *     "plan_kinds":  ["recurring"],            // PlanKind values
 *     "frequencies": ["yearly"],               // BillingFrequency values
 *     "product_ids": ["2666", "2675"],         // platform product ids, as strings
 *     "statuses":    ["active", "paused"]      // PlanStatus values; default [active, paused]
 *   }
 * Anything the model cannot recognise is dropped on read, so a stale value left
 * behind by a renamed enum narrows nothing rather than hiding the offer.
 *
 * `accepted_count` / `last_accepted_at` are COUNTERS, not the record of an
 * acceptance — the acceptance itself lives on the plan it created
 * (InstallmentPlan::META_ACCOUNT_OFFER), because that is the row a merchant
 * disputes about. Counting by walking a JSON path would split by driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name');
            $table->string('status', 20)->default('draft');       // draft | active

            // WHAT is offered: the merchant's own subscription template.
            $table->foreignId('product_subscription_plan_id')
                ->constrained('product_subscription_plans')
                ->cascadeOnDelete();

            $table->string('mode', 10)->default('replace');        // add | replace
            // Only meaningful for `replace`: immediate | period_end.
            $table->string('replace_timing', 20)->nullable();

            // WHO sees it (see the class docblock for the shape).
            $table->json('audience')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('placement', 10)->default('plan');      // top | rail | plan
            $table->unsignedInteger('priority')->default(0);

            // HOW it looks. Either the designed fields, or custom_html instead.
            $table->string('heading')->nullable();
            $table->string('subtext', 300)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('button_text', 60)->nullable();
            $table->text('custom_html')->nullable();

            $table->unsignedInteger('accepted_count')->default(0);
            $table->timestamp('last_accepted_at')->nullable();

            $table->timestamps();

            // The personal area asks "which offers are live for this shop" on
            // every page load, then splits them by placement.
            $table->index(['shop_id', 'status'], 'account_offers_shop_status_idx');
            $table->index(['shop_id', 'placement'], 'account_offers_shop_placement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_offers');
    }
};
