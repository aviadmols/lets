<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A second sending provider, so the platform is not one company's customer.
 *
 * Sending was never SendGrid-specific — it leaves over plain SMTP — so what a
 * provider actually costs us is the DOMAIN paperwork: asking them to
 * authenticate a merchant's domain and reading back the records to publish.
 * That is the part these columns pay for.
 *
 * Amazon SES splits a credential in two, and the split is deliberate on their
 * side: the API keys sign the domain-identity calls, and a SEPARATE pair of
 * SMTP credentials sends the mail. They are generated differently and can be
 * revoked independently, so they are stored as what they are rather than
 * squeezed into one "api key" field that would fit neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_mail_settings', function (Blueprint $table): void {
            // sendgrid | ses — which account the platform's mail leaves through.
            $table->string('provider', 16)->default('sendgrid')->after('id');

            $table->string('ses_region', 32)->nullable()->after('sendgrid_api_key');
            $table->text('ses_access_key_id')->nullable()->after('ses_region');
            $table->text('ses_secret_access_key')->nullable()->after('ses_access_key_id');
            $table->text('ses_smtp_username')->nullable()->after('ses_secret_access_key');
            $table->text('ses_smtp_password')->nullable()->after('ses_smtp_username');
        });

        // The provider's handle on a domain is not always a number. SendGrid
        // issues an integer id; SES identifies a domain by the domain itself.
        // A bigint column can hold one and not the other, so it widens to text
        // — every existing id still reads back as the same digits.
        // Postgres needs telling; SQLite (the test database) has no column
        // types to widen — it stores whatever it is given — so it is already
        // holding a string and the statement would only be a syntax error.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['platform_mail_settings', 'shop_sender_domains'] as $table) {
            DB::statement(
                'ALTER TABLE '.$table.' ALTER COLUMN provider_domain_id TYPE VARCHAR(191) USING provider_domain_id::VARCHAR'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['platform_mail_settings', 'shop_sender_domains'] as $table) {
                DB::statement(
                    'ALTER TABLE '.$table." ALTER COLUMN provider_domain_id TYPE BIGINT USING NULLIF(provider_domain_id, '')::BIGINT"
                );
            }
        }

        Schema::table('platform_mail_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'provider',
                'ses_region',
                'ses_access_key_id',
                'ses_secret_access_key',
                'ses_smtp_username',
                'ses_smtp_password',
            ]);
        });
    }
};
