<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop email engine settings (App\Models\MerchantMailSettings). Exactly ONE
 * row per shop. Tenant-scoped (shop_id + BelongsToShop on the model).
 *
 * Each notification template gets a nullable {name}_subject + {name}_body
 * override: NULL = use the platform default from App\Support\DefaultEmailTemplates;
 * a non-null value = the merchant-edited HTML, substituted ONLY via strtr()
 * (NEVER Blade) — RCE prevention (CLAUDE.md email-template-safety law).
 *
 * Optional per-shop SMTP override lets a merchant send from their own mailbox
 * (so customer emails come from the store, not the platform). The password is
 * encrypted at rest (the model casts it `encrypted`). When override_env_smtp is
 * false the platform .env mailer is used.
 *
 * Ported + multi-tenant-refactored from the reference engine's single-tenant
 * Settings/MailSettings (which keyed off global config, not a per-shop row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            // One row per shop — unique so current() can firstOrCreate safely.
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // Per-template overrides. NULL => platform default (DefaultEmailTemplates).
            foreach ([
                'first_payment_welcome',
                'recurring_payment_reminder',
                'manual_recurring_payment',
                'charge_succeeded',
                'charge_failed',
                'plan_cancelled',
            ] as $template) {
                $table->string($template.'_subject')->nullable();
                $table->text($template.'_body')->nullable();
            }

            // Reminder behaviour (DispatchRemindersCommand).
            $table->boolean('reminder_enabled')->default(true);
            // Hours BEFORE next_charge_at to send the upcoming-charge reminder.
            $table->unsignedInteger('reminder_offset_hours')->default(72);

            // Per-shop SMTP override (off => platform .env mailer).
            $table->boolean('override_env_smtp')->default(false);
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();   // tls|ssl|null
            $table->string('smtp_username')->nullable();
            // Encrypted at rest via the model's `encrypted` cast.
            $table->text('smtp_password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            // Storefront customer-portal landing URL used in email CTAs.
            $table->string('portal_store_page_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
