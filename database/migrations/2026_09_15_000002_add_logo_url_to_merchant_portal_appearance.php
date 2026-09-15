<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_portal_appearance', function (Blueprint $table): void {
            /*
             * The MERCHANT'S own logo, for the pages their customers land on.
             *
             * A URL rather than an upload, deliberately. The app runs on
             * containers whose filesystem does not survive a deploy, so a file
             * written to the local disk would vanish silently — and a logo that
             * disappears from a payment page next Tuesday is worse than one that
             * was never set. A URL is durable, costs no object storage, and a
             * merchant already has their logo on their own storefront.
             */
            $table->string('logo_url', 2048)->nullable()->after('accent_text_color');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_portal_appearance', function (Blueprint $table): void {
            $table->dropColumn('logo_url');
        });
    }
};
