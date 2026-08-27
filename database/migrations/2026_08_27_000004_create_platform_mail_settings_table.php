<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PLATFORM's own sending account — one row, no shop_id.
 *
 * The key was an env var, which is the right home for a platform secret and a
 * poor home for a thing the owner has to change: rotating it meant editing a
 * deploy variable and waiting for a restart, and nothing on any screen could
 * say whether it worked. This row moves that operation into the product while
 * keeping the property that mattered — the key is encrypted at rest, only a
 * platform admin can reach the screen, and it is never rendered back.
 *
 * THE ENV VAR STILL WORKS. It is the fallback when this row is empty, so an
 * existing deploy keeps sending and a fresh one can be brought up from
 * variables alone. A key saved here WINS, because otherwise the screen would be
 * a form whose value is silently ignored.
 *
 * The platform's own authenticated domain (the one its fallback From sits on)
 * is kept here too: it is the same conversation with the provider a shop has,
 * and the owner needs the same records table to finish it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table): void {
            $table->id();

            /** Encrypted at rest via the model's `encrypted` cast. */
            $table->text('sendgrid_api_key')->nullable();

            /** The envelope a shop with no verified domain of its own sends as. */
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            /** The label merchant domains are signed under: mail.theirshop.co.il. */
            $table->string('subdomain')->nullable();

            // --- the platform's OWN authenticated domain ---
            $table->string('domain')->nullable();
            $table->unsignedBigInteger('provider_domain_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->json('records')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
    }
};
