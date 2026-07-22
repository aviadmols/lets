<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop invoicing policy (App\Models\MerchantInvoicingSettings). Exactly ONE
 * row per shop, tenant-scoped (shop_id + BelongsToShop on the model). A direct
 * sibling of merchant_billing_settings — preference storage, no secrets (the
 * Green Invoice keys live in the encrypted shops.invoicing_credentials bag).
 *
 * What it governs:
 *   - enabled           : the master switch for the whole invoicing module;
 *   - scope             : plans_only (LETS plan/subscription/upsell money only)
 *                         vs all_orders (every order the storefront receives);
 *   - trigger_statuses  : in all_orders mode, WHICH WooCommerce order statuses
 *                         fire a document (merchant-picked, default processing +
 *                         completed);
 *   - document_type_map : context → Green Invoice numeric document type, so an
 *                         Osek Patur (who may not issue 305) or a merchant with
 *                         different accounting habits can override every row;
 *   - delivery/format   : whether Morning emails the document to the customer,
 *                         the document language, VAT type, rounding, and whether
 *                         the document URL is written back onto the store order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_invoicing_settings', function (Blueprint $table) {
            $table->id();
            // One row per shop — unique so current() can firstOrCreate safely.
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // Master switch + provider discriminator (green_invoice is the only
            // provider today; the column exists so a second one needs no migration).
            $table->boolean('enabled')->default(false);
            $table->string('provider', 32)->default('green_invoice');

            // Scope: plans_only | all_orders, plus the WC statuses that trigger.
            $table->string('scope', 32)->default('plans_only');
            $table->json('trigger_statuses')->nullable();   // ["processing","completed"]

            // context → Green Invoice numeric type (300/305/320/330/400/405).
            $table->json('document_type_map')->nullable();

            // Delivery + document formatting.
            $table->boolean('send_email_to_customer')->default(false);
            $table->string('document_language', 8)->default('he');
            $table->unsignedTinyInteger('default_vat_type')->default(0);
            $table->boolean('rounding')->default(false);
            $table->boolean('attach_to_order')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_invoicing_settings');
    }
};
