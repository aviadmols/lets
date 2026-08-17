<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which buttons a subscriber gets, and whether they may hold more than one plan.
 *
 * Pause and cancel already had merchant switches; skip, "change date" and editing
 * the next order did not — they were drawn for every shop that sells a recurring
 * plan. A shop that packs a fixed monthly box has no way to honour "skip the next
 * delivery", and a button that cannot be honoured is worse than a missing one.
 *
 * The switches default to TRUE so no existing shop loses a control it already
 * offered. `single_active_subscription` defaults to FALSE for the same reason:
 * turning it on is a policy a merchant chooses, never one a migration imposes.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'merchant_billing_settings';

    /** Self-service verbs, in the order they appear on the card. */
    private const ACTION_COLUMNS = [
        'allow_customer_skip',
        'allow_customer_reschedule',
        'allow_customer_edit_items',
    ];

    private const SINGLE_COLUMN = 'single_active_subscription';

    private const AFTER = 'allow_customer_cancel';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $after = self::AFTER;

            foreach (self::ACTION_COLUMNS as $column) {
                $table->boolean($column)->default(true)->after($after);
                $after = $column;
            }

            $table->boolean(self::SINGLE_COLUMN)->default(false)->after($after);
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(array_merge(self::ACTION_COLUMNS, [self::SINGLE_COLUMN]));
        });
    }
};
