<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The SQL fragments the Products screens need to attribute a subscription to a
 * product, on both rails. Kept beside the Products queries (not in the shared
 * Support\Sql) because only these screens read them; the dialect switch is
 * Sql::isSqlite(), the same one every Analytics query uses.
 *
 *   PayPlus plan   — one product per plan: external_product_id (WooCommerce) or
 *                    shopify_product_id; the variant likewise.
 *   Shopify contract — one row per LINE of subscription_contracts.lines (a JSON
 *                    array mirrored from Shopify), product/variant ids are gids
 *                    whose numeric tail is the id the catalog stores.
 *
 * Every method returns a RAW fragment built from constants and column names
 * only (never user input).
 */
final class ProductSql
{
    // === CONSTANTS ===
    /** Product key of a subscription that names no product at all. */
    public const NONE = 'none';

    public const PRODUCT_GID_PREFIX = 'gid://shopify/Product/';

    public const VARIANT_GID_PREFIX = 'gid://shopify/ProductVariant/';

    /** Alias of the unnested contract line. */
    public const LINE = 'pl_line';

    /** Plan meta keys that carry the product title (the rails write different ones). */
    public const TITLE_KEYS = ['item_title', 'product_title'];

    public static function planProductKey(string $table = 'installment_plans'): string
    {
        return 'REPLACE(COALESCE('
            ."NULLIF({$table}.external_product_id, ''), NULLIF({$table}.shopify_product_id, ''), '".self::NONE."'), "
            ."'".self::PRODUCT_GID_PREFIX."', '')";
    }

    public static function planVariantKey(string $table = 'installment_plans'): string
    {
        return 'REPLACE(COALESCE('
            ."NULLIF({$table}.external_variant_id, ''), NULLIF({$table}.shopify_variant_id, ''), ''), "
            ."'".self::VARIANT_GID_PREFIX."', '')";
    }

    /** The plan's own product title (meta), a fallback when the catalog has no row. */
    public static function planTitle(string $table = 'installment_plans'): string
    {
        $parts = array_map(static fn (string $key): string => 'NULLIF('.Sql::jsonText("{$table}.meta", $key).", '')", self::TITLE_KEYS);

        return 'COALESCE('.implode(', ', $parts).')';
    }

    /**
     * LEFT JOIN every line of a contract (a contract whose lines are not synced
     * yet keeps one row with a NULL line — it still renews something).
     */
    public static function joinContractLines(Builder $query, string $table = 'subscription_contracts'): Builder
    {
        $line = self::LINE;
        $source = Sql::isSqlite()
            ? "json_each(CASE WHEN json_type({$table}.lines) = 'array' THEN {$table}.lines ELSE '[]' END) as {$line}"
            : "LATERAL json_array_elements(CASE WHEN json_typeof({$table}.lines::json) = 'array' "
                ."THEN {$table}.lines::json ELSE '[]'::json END) as {$line}";

        return $query->leftJoin(DB::raw($source), DB::raw('1'), '=', DB::raw('1'));
    }

    /** One field of the joined line, as text. */
    public static function lineText(string $field): string
    {
        $line = self::LINE;

        return Sql::isSqlite()
            ? "json_extract({$line}.value, '$.{$field}')"
            : "({$line}.value->>'{$field}')";
    }

    public static function lineProductKey(): string
    {
        return "REPLACE(COALESCE(NULLIF(CAST(".self::lineText('product_id')." AS TEXT), ''), '".self::NONE."'), '"
            .self::PRODUCT_GID_PREFIX."', '')";
    }

    public static function lineVariantKey(): string
    {
        return "REPLACE(COALESCE(NULLIF(CAST(".self::lineText('variant_id')." AS TEXT), ''), ''), '"
            .self::VARIANT_GID_PREFIX."', '')";
    }

    /** Units on the line (a line with no quantity is 1). */
    public static function lineQuantity(): string
    {
        return 'COALESCE(CAST('.self::lineText('quantity').' AS INTEGER), 1)';
    }

    /**
     * The line's share of the contract's MRR: contract MRR × line units ÷ the
     * contract's units. A contract with one line keeps all of it.
     */
    public static function lineMrr(string $table = 'subscription_contracts'): string
    {
        return '('.Sql::contractMrr($table).' * 1.0 * '.self::lineQuantity().' / '.Sql::contractQuantity($table).')';
    }

    /** A product/variant id with the Shopify gid prefix removed (PHP twin of the SQL). */
    public static function stripGid(?string $id): string
    {
        return str_replace([self::PRODUCT_GID_PREFIX, self::VARIANT_GID_PREFIX], '', trim((string) $id));
    }
}
