<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable "update your card" link a merchant can hand to one customer.
 *
 * The PayPlus re-vault page cannot BE the link: it is minted for a moment and
 * expires on PayPlus's side, so an emailed one is dead by the time the customer
 * opens the mail. This row is our own durable handle — a landing page that mints
 * a fresh PayPlus page at the instant the customer actually clicks.
 *
 * WHAT IS STORED IS THE HASH, exactly as the campaign sign-in link does: the raw
 * token travels through mailboxes, SMS logs, browser history and proxies, and the
 * row names a real customer's subscription, so a database read must not be
 * replayable into somebody's payment details.
 *
 * The row also carries what the merchant needs to see afterwards: which channel
 * it went out on, whether it was opened, whether the card was actually replaced,
 * and whether it has been revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_update_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('installment_plans')->cascadeOnDelete();

            $table->char('token_hash', 64)->unique();

            // copy | email | sms — how the merchant sent it, for the status line.
            $table->string('channel', 16)->default('copy');
            // The address or number it went to, for "sent to …". Never the token.
            $table->string('sent_to', 191)->nullable();

            // Who minted it: a merchant user, or the system (a future dunning rule).
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('expires_at');
            // The landing page was opened. Not a completion — plenty of people
            // click and then cannot find their card.
            $table->timestamp('clicked_at')->nullable();
            // The PayPlus callback vaulted a new token against this link.
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'plan_id']);
            $table->index(['shop_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_update_links');
    }
};
