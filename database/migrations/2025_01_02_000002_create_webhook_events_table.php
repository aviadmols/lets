<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Shopify webhook dedupe + audit store. Shopify delivers each webhook
 * AT-LEAST-ONCE; we dedupe on (shop_id, source, webhook_id, topic) and guard
 * re-processing with processed_at. Tenant-scoped (shop_id), so a replayed webhook
 * for Shop A can never collide with Shop B.
 *
 * shop_id is nullable ONLY for the brief window where a webhook arrives for a
 * domain we have no Shop row for (uninstalled / never-installed) — those are
 * logged + 202'd, never processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Nullable: an unknown-shop webhook is recorded for audit but never bound.
            $table->foreignId('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();

            $table->string('source')->default('shopify');     // shopify | payplus
            $table->string('topic')->index();                 // orders/paid, app/uninstalled, ...
            $table->string('webhook_id')->nullable();         // X-Shopify-Webhook-Id
            $table->string('shopify_id')->nullable();         // resource id from payload (data_get id)
            $table->string('shop_domain')->nullable();        // routing hint header (audit)

            $table->json('raw_payload')->nullable();
            $table->json('headers')->nullable();              // masked audit headers

            $table->boolean('hmac_valid')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();    // dedupe-replay guard
            $table->string('error')->nullable();

            $table->timestamps();

            // Dedupe key — scoped by shop so a replay never crosses tenants.
            $table->unique(['shop_id', 'source', 'webhook_id', 'topic'], 'webhook_events_dedupe_unique');
            $table->index(['shop_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
