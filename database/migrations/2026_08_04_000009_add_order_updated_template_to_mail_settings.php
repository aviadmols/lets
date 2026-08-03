<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The eighth template: "your order was updated", sent when an add-on window
 * closes on an order the shopper actually added to.
 *
 * Same nullable {key}_subject + {key}_body convention as the rest — null means
 * "inherit the platform default", so nothing changes for a shop until it turns
 * the hold window on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->string('order_updated_subject')->nullable();
            $table->text('order_updated_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn(['order_updated_subject', 'order_updated_body']);
        });
    }
};
