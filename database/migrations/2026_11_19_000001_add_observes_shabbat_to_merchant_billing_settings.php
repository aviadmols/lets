<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A shop that keeps Shabbat: no automatic charge on a Saturday (Israel time). A
 * renewal, instalment or retry that falls on one is collected on the Sunday, and
 * the plan keeps its own date (App\Domain\Billing\ShabbatHold).
 *
 * OFF by default, so an upgrade changes nothing for any shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->boolean('observes_shabbat')->default(false)->after('renewal_anchor');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('observes_shabbat');
        });
    }
};
