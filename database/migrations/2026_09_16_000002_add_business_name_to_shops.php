<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            /*
             * The name the MERCHANT chose for their customers to read — on the
             * card-update page, in the From of their mail, at the top of an SMS.
             *
             * A column of its own rather than an edit of `name`, because `name`
             * belongs to the installers: the Shopify install writes the domain
             * there and a Woo provisioning writes whatever the platform admin
             * typed (or the domain). A merchant's choice written into a field
             * something else also writes is a choice that quietly reverts.
             */
            $table->string('business_name', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('business_name');
        });
    }
};
