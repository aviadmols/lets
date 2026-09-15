<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            /*
             * The WhatsApp line a merchant sends by hand with a card-update link.
             *
             * It lives beside the card-update EMAIL template rather than in its own
             * table, because from the merchant's side they are the same job — "what
             * do we say when we ask somebody to re-enter their card" — and a second
             * screen for one sentence is a second place to forget.
             *
             * Nullable means "use the default": a blank row reads the shipped copy,
             * so the wording can be improved for every shop that never edited it.
             */
            $table->text('card_update_whatsapp')->nullable()->after('card_update_body');
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->dropColumn('card_update_whatsapp');
        });
    }
};
