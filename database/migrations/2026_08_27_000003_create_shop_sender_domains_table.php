<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shop's own SENDING DOMAIN on the platform's SendGrid account.
 *
 * The merchant's mail should say it came from the merchant. Sending it as
 * `noreply@lets.co.il` works, but every customer sees our name where the shop's
 * should be, and SPF/DKIM/DMARC on the shop's real domain never gets to vouch
 * for it. SendGrid's domain authentication answers that: the merchant adds a
 * handful of CNAMEs, and from then on their mail is signed as their own domain
 * while it still leaves through OUR account — one relay, one reputation to
 * manage, one bill.
 *
 * The DNS records SendGrid asks for are stored verbatim (`records`) because the
 * merchant needs them again every time they open the screen, and re-fetching
 * them from the API on every page view would put a third party in the path of a
 * settings page. `status` is OUR reading; `last_checked_at` says how fresh it
 * is, so the screen never implies a verification that happened days ago is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_sender_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            /** The registrable domain the merchant typed: "sellameir.co.il". */
            $table->string('domain');
            /** The label SendGrid signs under it: mail.sellameir.co.il. */
            $table->string('subdomain')->nullable();

            /** The id this domain has on the PLATFORM's SendGrid account. */
            $table->unsignedBigInteger('provider_domain_id')->nullable();

            // pending | verified | failed
            $table->string('status', 16)->default('pending');

            /** The CNAMEs the merchant must add, as the provider described them. */
            $table->json('records')->nullable();

            /** Why the last check said no — shown to the merchant, not a log line. */
            $table->string('failure_reason')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // One sending domain per shop: the From address is one identity, and
            // two half-verified domains would make "which one are we sending as"
            // a question with no answer.
            $table->unique('shop_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_sender_domains');
    }
};
