<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ninth template: "please update your payment card", carrying the durable
 * card-update link a merchant sends from the subscription screen.
 *
 * Same nullable {key}_subject + {key}_body convention as the rest — null means
 * "inherit the platform default", so nothing changes for a shop until they edit
 * the copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->string('card_update_subject')->nullable();
            $table->text('card_update_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->dropColumn(['card_update_subject', 'card_update_body']);
        });
    }
};
