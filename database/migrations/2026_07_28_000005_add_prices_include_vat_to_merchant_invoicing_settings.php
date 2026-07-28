<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the prices we report already CONTAIN VAT.
 *
 * Green Invoice reads two different vatType fields: one on the document (0 =
 * "apply the correct VAT for this business", which is right) and one on each
 * income row, where 1 means "this price includes VAT" and 0 means "add VAT on
 * top". We were sending the document-level default into BOTH, so a ₪1.00 storefront
 * line was grossed up to ₪1.18 while the receipt line still said ₪1.00 — and the
 * provider rejected the document with 2422, "a mismatch between the sum of
 * receipts and the sum of payments".
 *
 * Default TRUE: a storefront price in Israel is what the shopper actually pays,
 * VAT included — and it is the only value that makes the income and the payment
 * agree with the money that really moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_invoicing_settings', function (Blueprint $table): void {
            $table->boolean('prices_include_vat')->default(true)->after('default_vat_type');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_invoicing_settings', function (Blueprint $table): void {
            $table->dropColumn('prices_include_vat');
        });
    }
};
