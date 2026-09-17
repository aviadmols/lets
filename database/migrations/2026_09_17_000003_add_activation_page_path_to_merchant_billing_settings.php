<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a Shopify store's activation link opens.
 *
 * NULL (every existing shop): the LETS page at app.lets.co.il, as today. A path such as
 * /pages/activate: a page in the merchant's own store that carries the "Subscription
 * activation" theme block (StoreActivationPage). Additive and null by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->string('activation_page_path', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('activation_page_path');
        });
    }
};
