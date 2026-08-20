<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The THINGS an account offer sells. One offer, several targets.
 *
 * The first shape of this feature put the target ON the offer — one offer, one
 * subscription template. That made "switch to monthly OR to the annual saver" two
 * offers that happen to sit beside each other, each with its own heading, image
 * and audience, and no way for the merchant to write one block of HTML with two
 * buttons in it. A target is a CHOICE inside one promotion, so it belongs in a
 * child table where the promotion can hold several.
 *
 * Two kinds, and the difference is what the shopper ends up with:
 *
 *   subscription — a ProductSubscriptionPlan template. Price and cadence come
 *                  from the template (never typed here), and accepting creates a
 *                  new recurring plan on the saved card. `mode` decides whether
 *                  it stands beside the current subscription or replaces it, and
 *                  `replace_timing` decides when the replacement bites.
 *
 *   one_time     — a PLAIN catalog product. No template, no cadence: the price is
 *                  the catalog's, times `quantity`. `fulfilment` decides whether
 *                  the saved card is charged NOW (and a paid order created) or
 *                  whether the line simply rides along on the source
 *                  subscription's next order.
 *
 * `mode` and `replace_timing` live HERE rather than on the parent because they
 * are per-target decisions: one promotion can legitimately offer "replace your
 * yearly plan with the monthly one" beside "and add the mug". A one_time target
 * is always `add` and carries a null timing — it ends no period.
 *
 * `external_product_id` / `external_variant_id` are stored for BOTH kinds. A
 * one_time target has nowhere else to name its product; a subscription target
 * stores them so the payload (and the `{{button_2675}}` token) can address the
 * target without a join back through the template.
 *
 * `token_key` is the merchant's own slug for a target — the readable half of the
 * button-token vocabulary (`{{button_upgrade}}`). Unique per offer, because two
 * targets answering to the same token is an ambiguous button, which in this
 * feature means a charge for the wrong thing.
 *
 * No DB enums. `kind`, `mode`, `replace_timing` and `fulfilment` are strings
 * guarded by a PHP allow-list on AccountOfferTarget — the same discipline every
 * other taxonomy column in this schema uses.
 *
 * The template FK is nullOnDelete and NOT cascade: deleting a template must not
 * silently remove one target from a multi-target offer and leave the merchant's
 * custom HTML pointing at a button that no longer exists. A target with no
 * template cannot be priced, so it simply stops being offered — visible in the
 * admin, fixable, and never a surprise on a shopper's page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_offer_targets', function (Blueprint $table) {
            $table->id();
            // Tenancy is carried on the row itself, not inherited through the
            // parent: every tenant-owned model in this schema answers `shop_id`
            // directly so a forgotten join can never widen a query across shops.
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('account_offers')->cascadeOnDelete();

            // 1-based, and the ORDER the cards/buttons are drawn in. Also the
            // fallback addressing scheme: {{button_2}} is the second target.
            $table->unsignedInteger('position')->default(1);

            $table->string('kind', 20)->default('subscription');   // subscription | one_time

            // subscription: WHAT is sold. Null for a one_time target.
            $table->foreignId('product_subscription_plan_id')
                ->nullable()
                ->constrained('product_subscription_plans')
                ->nullOnDelete();

            // one_time: WHAT is sold. Also stored for subscription targets so the
            // payload can name/address the product without a join.
            $table->string('external_product_id', 64)->nullable();
            $table->string('external_variant_id', 64)->nullable();

            // The merchant's slug for this target: {{button_<token_key>}}.
            $table->string('token_key', 32)->nullable();
            $table->string('button_text', 60)->nullable();

            // one_time only. A subscription is one subscription.
            $table->unsignedInteger('quantity')->default(1);

            // subscription only (a one_time target is always `add`, timing null).
            $table->string('mode', 10)->default('add');            // add | replace
            $table->string('replace_timing', 20)->nullable();      // immediate | period_end

            // one_time only: charge now, or ride the next recurring order.
            $table->string('fulfilment', 20)->nullable();          // immediate | next_order

            $table->timestamps();

            $table->index(['offer_id', 'position'], 'account_offer_targets_offer_position_idx');
            $table->index(['shop_id', 'offer_id'], 'account_offer_targets_shop_offer_idx');
            // Two targets answering to one token is an ambiguous button.
            $table->unique(['offer_id', 'token_key'], 'account_offer_targets_offer_token_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_offer_targets');
    }
};
