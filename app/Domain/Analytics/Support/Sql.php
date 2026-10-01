<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Support\Frequency;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place Analytics SQL branches per driver. Production is Postgres; the
 * test suite and the local preview are SQLite. Every expression a query class
 * needs that the two dialects spell differently lives here, so a query class
 * stays a single portable shape.
 *
 * Every method returns a RAW SQL fragment built only from constants and the
 * column names the caller passes (never user input) — safe to interpolate into
 * selectRaw()/groupByRaw()/whereRaw().
 */
final class Sql
{
    // === CONSTANTS ===
    public const DRIVER_SQLITE = 'sqlite';

    /** Shopify customer gids carry this prefix; the tail is the id plans store. */
    public const CUSTOMER_GID_PREFIX = 'gid://shopify/Customer/';

    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function isSqlite(): bool
    {
        return self::driver() === self::DRIVER_SQLITE;
    }

    /** The calendar day of a timestamp column as 'YYYY-MM-DD' text. */
    public static function day(string $column): string
    {
        return self::isSqlite()
            ? "strftime('%Y-%m-%d', {$column})"
            : "to_char({$column}, 'YYYY-MM-DD')";
    }

    /** The calendar month of a timestamp column as 'YYYY-MM' text. */
    public static function month(string $column): string
    {
        return self::isSqlite()
            ? "strftime('%Y-%m', {$column})"
            : "to_char({$column}, 'YYYY-MM')";
    }

    /** A top-level JSON key as text (activity_events.details, payment_ledger meta…). */
    public static function jsonText(string $column, string $key): string
    {
        return self::isSqlite()
            ? "json_extract({$column}, '$.{$key}')"
            : "({$column}->>'{$key}')";
    }

    /**
     * One human, one key, on the PayPlus rail. Platform customer id first, then
     * the WooCommerce id, then the (lower-cased) email, then the plan itself —
     * a plan with no identity at all is its own subscriber, never merged.
     */
    public static function planCustomerKey(string $table = 'installment_plans'): string
    {
        return "COALESCE(NULLIF({$table}.shopify_customer_id, ''), NULLIF({$table}.external_customer_id, ''), "
            ."LOWER(NULLIF({$table}.customer_email, '')), 'plan:' || {$table}.id)";
    }

    /**
     * The same human on the Shopify-Payments rail: the gid's numeric tail is the
     * id plans store, so one shopper on both rails counts once.
     */
    public static function contractCustomerKey(string $table = 'subscription_contracts'): string
    {
        $prefix = self::CUSTOMER_GID_PREFIX;

        return "COALESCE(NULLIF(REPLACE({$table}.shopify_customer_gid, '{$prefix}', ''), ''), "
            ."LOWER(NULLIF({$table}.customer_email, '')), 'contract:' || {$table}.id)";
    }

    /**
     * Units a contract renews per cycle: the sum of its line quantities (a line
     * with no quantity is 1), and 1 when the lines are not synced yet — a
     * contract always renews something.
     */
    public static function contractQuantity(string $table = 'subscription_contracts'): string
    {
        $sum = self::isSqlite()
            ? "(SELECT COALESCE(SUM(COALESCE(json_extract(j.value, '$.quantity'), 1)), 0) FROM json_each({$table}.lines) j)"
            : "(SELECT COALESCE(SUM(COALESCE((j->>'quantity')::int, 1)), 0) FROM json_array_elements("
                ."CASE WHEN json_typeof({$table}.lines::json) = 'array' THEN {$table}.lines::json ELSE '[]'::json END) j)";

        return "(CASE WHEN {$sum} > 0 THEN {$sum} ELSE 1 END)";
    }

    /** A plan's cycle amount normalised to a month (0 for a comped plan). @see Mrr */
    public static function planMrr(string $table = 'installment_plans'): string
    {
        $cases = [];
        foreach (Mrr::PLAN_MONTHLY_FACTOR as $frequency => $factor) {
            $cases[] = "WHEN '{$frequency}' THEN ".self::decimal($factor);
        }
        $factor = 'CASE '.$table.'.billing_frequency '.implode(' ', $cases).' ELSE 1 END';

        return "(CASE WHEN {$table}.no_charge THEN 0 ELSE "
            ."COALESCE({$table}.installment_amount, 0) * ({$factor}) / ".self::intervalCount($table)
            .' END)';
    }

    /** A contract's cycle amount normalised to a month. @see Mrr */
    public static function contractMrr(string $table = 'subscription_contracts'): string
    {
        $cases = [];
        foreach (Mrr::CONTRACT_MONTHLY_FACTOR as $interval => $factor) {
            $cases[] = "WHEN '{$interval}' THEN ".self::decimal($factor);
        }
        $factor = 'CASE UPPER('.$table.'."interval") '.implode(' ', $cases).' ELSE 1 END';

        return "(COALESCE({$table}.amount, 0) * ({$factor}) / ".self::intervalCount($table).')';
    }

    /** interval_count, never below 1. */
    public static function intervalCount(string $table): string
    {
        return "(CASE WHEN {$table}.interval_count > 1 THEN {$table}.interval_count ELSE 1 END)";
    }

    /** A float as a SQL decimal literal (no locale, no exponent). */
    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * The canonical frequency key of a plan, in SQL (Frequency::key mirrors it
     * in PHP): 'm<N>' for month-based cadences, 'd<N>' for day-based ones.
     */
    public static function planFrequencyKey(string $table = 'installment_plans'): string
    {
        $n = self::intervalCount($table);
        $cases = [];
        foreach (Frequency::PLAN_UNITS as $frequency => [$unit, $multiplier]) {
            $cases[] = "WHEN '{$frequency}' THEN '{$unit}' || CAST({$n} * {$multiplier} AS TEXT)";
        }

        return 'CASE '.$table.'.billing_frequency '.implode(' ', $cases)." ELSE 'm1' END";
    }

    /** The canonical frequency key of a contract, in SQL. */
    public static function contractFrequencyKey(string $table = 'subscription_contracts'): string
    {
        $n = self::intervalCount($table);
        $cases = [];
        foreach (Frequency::CONTRACT_UNITS as $interval => [$unit, $multiplier]) {
            $cases[] = "WHEN '{$interval}' THEN '{$unit}' || CAST({$n} * {$multiplier} AS TEXT)";
        }

        return 'CASE UPPER('.$table.'."interval") '.implode(' ', $cases)." ELSE 'm1' END";
    }
}
