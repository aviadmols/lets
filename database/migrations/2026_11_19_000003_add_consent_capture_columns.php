<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two halves of one problem: a subscription with no stored consent can never be
 * charged, and until now nobody could see it or fix it.
 *
 * installment_plans.consent_blocked_at: the moment a due charge was refused for
 * want of a consent. The scheduler skips a stamped plan instead of asking again
 * every five minutes (one plan was refused 1,599 times), and the Payment
 * recovery screen lists it. Any consent recorded for the plan clears it.
 *
 * card_update_links.terms_*: what the customer agreed to on a card-update page
 * that showed them the subscription's terms. Kept on the link, and turned into a
 * consent row only once the card is actually saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->timestamp('consent_blocked_at')->nullable()->after('payment_failed_at');
        });

        Schema::table('card_update_links', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->string('terms_accepted_ip', 64)->nullable();
            $table->string('terms_accepted_user_agent', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropColumn('consent_blocked_at');
        });

        Schema::table('card_update_links', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_snapshot', 'terms_accepted_ip', 'terms_accepted_user_agent']);
        });
    }
};
