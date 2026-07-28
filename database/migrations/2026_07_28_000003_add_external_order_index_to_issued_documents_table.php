<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Which documents exist for store order X?" became a real query: the WooCommerce
 * plugin's order screen asks it on render (POST /api/woocommerce/orders/documents),
 * and the Invoices screen now searches the column. external_order_id had no index —
 * fine while the only lookup was by idempotency key, a table scan once every order
 * page render asks by order id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->index(['shop_id', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropIndex(['shop_id', 'external_order_id']);
        });
    }
};
