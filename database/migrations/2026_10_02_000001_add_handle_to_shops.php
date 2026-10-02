<?php

use App\Domain\Tenancy\ShopHandleBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One subdomain per shop, phase 1 (docs/plans/shop-subdomains.md): every shop
 * gets a HANDLE — the left-most label of `<handle>.app.lets.co.il` — and old
 * handles live on for a while in `shop_handle_aliases`.
 *
 * Order matters and never fails the deploy:
 *   1. the column is added NULLABLE (+ unique), the alias table created;
 *   2. every existing shop is backfilled by ShopHandleBackfill — each shop in
 *      its own try + savepoint, a failure logged with the shop id and skipped;
 *   3. ONLY when no shop is left without a handle does the column become
 *      NOT NULL. Any skip leaves it nullable and logs why — the next deploy (or
 *      `ShopHandleBackfill` from tinker) finishes the job.
 *
 * IDEMPOTENT: each step checks before it acts; a re-run assigns nothing new.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'shops';

    private const ALIASES = 'shop_handle_aliases';

    private const COLUMN = 'handle';

    private const LENGTH = 63;

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string(self::COLUMN, self::LENGTH)->nullable()->unique()->after('id');
            });
        }

        if (! Schema::hasTable(self::ALIASES)) {
            Schema::create(self::ALIASES, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
                $table->string('handle', self::LENGTH)->unique();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('expires_at')->index();
            });
        }

        $result = app(ShopHandleBackfill::class)->run();

        $missing = DB::table(self::TABLE)->whereNull(self::COLUMN)->count();
        if ($missing > 0) {
            Log::warning('migration.shop_handles_left_nullable', [
                'missing' => $missing,
                'skipped' => $result['skipped'],
            ]);

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string(self::COLUMN, self::LENGTH)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::ALIASES);

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique([self::COLUMN]);
                $table->dropColumn(self::COLUMN);
            });
        }
    }
};
