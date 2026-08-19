<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which LETS areas a shop sees when the admin is opened INSIDE wp-admin.
 *
 * The platform owner sets this per shop (ShopResource → "Embedded menu"); it is a
 * list of EmbeddedMenu area keys. NULL — the default, and what every existing row
 * gets — means "no restriction", so nothing changes for a shop nobody has touched.
 * An empty array is a real state: only Home.
 *
 * Deliberately a menu concern, not an authorisation one: tenancy still isolates
 * the data. This decides what the merchant is OFFERED inside WordPress, where the
 * host page owns the chrome and a full settings sidebar is out of place.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'shops';

    private const COLUMN = 'embedded_menu';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->json(self::COLUMN)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
