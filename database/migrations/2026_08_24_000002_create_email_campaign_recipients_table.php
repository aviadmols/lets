<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE person's place in one email campaign — and the campaign's idempotency wall.
 *
 * An SMTP server accepts the same message twice without a word, so the only thing
 * standing between a re-run and a customer's inbox getting two copies is this row:
 * UNIQUE (campaign, email) means a person is enrolled once however many
 * subscriptions they hold, and the row is CLAIMED (pending → sending, atomically)
 * before the send so a redelivered job finds it taken and stops.
 *
 * Where the person came from (plan, contract, loyalty account) is kept for the
 * merchant's list and for the Timeline — an email landing on a subscriber's own
 * feed is worth more than a line in a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();

            // Lower-cased at enrolment: the unique key below compares bytes.
            $table->string('email');
            $table->string('customer_name')->nullable();
            $table->string('customer_ref', 64)->nullable();

            // plan | contract | loyalty — which row of ours put them in the audience.
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');

            // pending | sending | sent | failed | skipped
            $table->string('status', 24)->default('pending');
            // unsubscribed | no_email | mail_error | campaign_cancelled | shop_not_live
            $table->string('reason')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->string('message_id')->nullable();

            $table->timestamps();

            // THE wall — one enrolment per address per campaign.
            $table->unique(['email_campaign_id', 'email'], 'email_campaign_recipient_unique');
            $table->index(['shop_id', 'status']);
            $table->index(['email_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_recipients');
    }
};
