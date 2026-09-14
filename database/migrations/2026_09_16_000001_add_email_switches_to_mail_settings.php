<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH emails this shop sends — a master tap and a per-email list.
 *
 * `emails_enabled` is the master: TRUE by default, because that is what every
 * existing shop does today and an upgrade must change nothing. Off, nothing this
 * app originates leaves — not a welcome, not a reminder, not a campaign. It does
 * NOT reach the accounting document's own email: that one is sent by the
 * invoicing provider, on the merchant's separate invoicing setting, and a tax
 * receipt is not a notification anybody should be able to switch off by accident.
 *
 * `emails_paused_at` is stamped on the way down and kept, like
 * `charging_paused_at` on the billing settings: the merchant has to be able to
 * answer "since when has nobody been emailed?".
 *
 * `disabled_templates` stores the OFF list rather than the on list, deliberately.
 * The template catalogue grows (it has gone from six to nine), and storing what
 * is enabled would mean a template added next release arrives silently muted for
 * every shop that saved this screen months ago. Storing what is disabled makes a
 * new email arrive ON — visible, and switchable if they do not want it.
 *
 * A JSON list rather than a boolean column per template, for the same reason: a
 * column per email is a migration per email. `allowed_frequencies` on the billing
 * settings is the precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->boolean('emails_enabled')->default(true)->after('email_locale');
            $table->timestamp('emails_paused_at')->nullable()->after('emails_enabled');
            $table->json('disabled_templates')->nullable()->after('emails_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->dropColumn(['emails_enabled', 'emails_paused_at', 'disabled_templates']);
        });
    }
};
