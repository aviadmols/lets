<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A passwordless "enter my account" link, as minted into one email for one person.
 *
 * WHAT IS STORED IS THE HASH. The raw token travels in a URL (mailboxes, history,
 * referrers, proxy logs) and the row names a real customer, so a database read
 * must not be replayable: `token_hash` is sha256(token) and the token itself is
 * never written anywhere but the email.
 *
 * SINGLE USE. `consumed_at` is set by ONE conditional UPDATE (… WHERE consumed_at
 * IS NULL AND revoked_at IS NULL AND expires_at > now) — the second click, the
 * forwarded copy, the mail scanner's prefetch all find nothing to spend.
 *
 * SHOP-BOUND. The shop is on the row and is what the landing page binds the
 * tenant from; the platform branch (WooCommerce ticket vs hosted page) is taken
 * from the row too, never from the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->char('token_hash', 64)->unique();

            $table->foreignId('email_campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('email_campaign_recipients')->nullOnDelete();

            // Who the link is for — the same derived identity the personal area
            // uses, plus the address the email went to (server-side data, so it is
            // a safe second matcher, exactly as on the admin's view-as page).
            $table->string('customer_ref', 64)->nullable();
            $table->string('email');
            $table->string('customer_name')->nullable();

            // shopify | woocommerce — frozen at mint time.
            $table->string('platform', 16);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Audit of the one redemption: hashed IP + user agent, never the token.
            $table->char('consumed_ip_hash', 64)->nullable();
            $table->string('consumed_user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'expires_at']);
            $table->index('email_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_tokens');
    }
};
