<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An EMAIL CAMPAIGN: one merchant-authored message, one audience rule, one run.
 *
 * `audience` is the same JSON bag shape the account offers use — every list is
 * INCLUSIVE and an empty list means "any":
 *
 *   {
 *     "sources":          ["subscribers", "purchasers", "loyalty_members"],
 *     "statuses":         ["active", "paused"],        // PlanStatus values
 *     "frequencies":      ["monthly", "yearly"],       // BillingFrequency values
 *     "product_ids":      ["2666", "2675"],            // platform product ids, STRINGS
 *     "loyalty_tier_ids": [3]                          // loyalty_tiers ids
 *   }
 *
 * `body_html` is MERCHANT HTML. It is substituted with strtr() and never
 * compiled — the same law every mail template in this app obeys.
 *
 * `status` moves only through the sender: draft|scheduled → sending → sent, or
 * → cancelled. The counts are caches of the recipients table, refreshed by the
 * job that settles the campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('subject', 255);
            $table->longText('body_html');

            // visual | html — which editor the merchant last used for this body.
            $table->string('editor_mode', 10)->default('visual');

            $table->json('audience')->nullable();

            // draft | scheduled | sending | sent | cancelled
            $table->string('status', 24)->default('draft');

            // A marketing message must carry an unsubscribe link and, in Hebrew,
            // the word "פרסומת" in its subject (Communications Law §30A).
            $table->boolean('is_marketing')->default(true);

            // How long the {account_login_url} in THIS campaign stays usable.
            // Null = the platform default (config campaigns.login_link_ttl_hours).
            $table->unsignedSmallInteger('login_link_ttl_hours')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Set by the merchant's "Revoke login links" — every token minted for
            // this campaign stops working at once, whatever its own expiry.
            $table->timestamp('login_links_revoked_at')->nullable();

            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            // The scheduler's scan: "which campaigns are due" across every shop.
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
