<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the initial platform-admin user.
 *
 * Uses DB::table()->insert() directly so the password cast on the User model
 * does not interfere — the value is pre-hashed with bcrypt here.
 * is_platform_admin is set to true; shop_id is null (platform admins are not
 * bound to a single tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->insert([
            'shop_id'           => null,
            'is_platform_admin' => true,
            'name'              => 'Admin',
            'email'             => 'aviadmols@gmail.com',
            'email_verified_at' => now(),
            'password'          => bcrypt('TempPassword123!'),
            'remember_token'    => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'aviadmols@gmail.com')->delete();
    }
};
