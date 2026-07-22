<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The DOCUMENT truth, in one place — the invoicing analogue of payment_ledger.
 *
 * No accounting document is ever requested without a row here FIRST (status
 * `pending`, written before the provider HTTP call), so a process death mid-issue
 * leaves a reconcilable trace instead of a silent duplicate. The result then
 * transitions that exact row to `issued` / `failed`.
 *
 * The UNIQUE (shop_id, idempotency_key) index is the double-issue wall: the key
 * is derived deterministically from the money event (the ledger row's own
 * idempotency key, or doc:wc_order:{shop}:{order} for a plain store order), so a
 * replayed webhook, a re-queued job, or a WooCommerce status flapping
 * processing → completed can never produce a second document.
 *
 * Only MASKED provider responses are stored (ResponseMasker), never raw secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Which provider issued it (green_invoice today) + the money event.
            $table->string('provider', 32)->default('green_invoice');
            // deposit|installment|final_installment|recurring|upsell|refund|
            // cancellation|platform_order
            $table->string('context', 32);
            $table->string('idempotency_key');

            // The provider's numeric/string document type actually requested.
            $table->string('document_type', 16)->nullable();

            // Linkage back to the money event (all nullable — a plain store order
            // has no plan and no ledger row).
            $table->unsignedBigInteger('ledger_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('external_order_id')->nullable();

            // pending|issued|failed|unresolved
            $table->string('status', 16)->default('pending');

            // The provider's answer.
            $table->string('provider_document_id')->nullable();
            $table->string('document_number')->nullable();
            $table->text('document_url')->nullable();

            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('ILS');

            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('raw_response_masked')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // The double-issue wall.
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'ledger_id']);
            $table->index(['shop_id', 'plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
    }
};
