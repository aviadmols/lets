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
             * The ONE exception a merchant may carve out of "send no email at all":
             * the personal area's sign-in code.
             *
             * A store that sends its own receipts and reminders (from WooCommerce,
             * a CRM) closes the master tap so customers are not emailed twice — and
             * with it, until now, nobody could sign in by email. The code is not a
             * notification; it is the door. Off by default: a closed tap keeps
             * meaning exactly what it meant for every shop that closed it.
             */
            $table->boolean('login_code_while_paused')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table): void {
            $table->dropColumn('login_code_while_paused');
        });
    }
};
