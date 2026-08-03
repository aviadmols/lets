<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seventh template: the personal area's sign-in code.
 *
 * It follows the same nullable {key}_subject + {key}_body convention as the other
 * six — null means "inherit the platform default", so an existing shop keeps
 * working the moment this lands and gets an improved default for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->string('login_code_subject')->nullable();
            $table->text('login_code_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn(['login_code_subject', 'login_code_body']);
        });
    }
};
