<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A shop's HANDLE — the left-most label of its admin host
 * (`<handle>.app.lets.co.il`). One place owns what a handle may look like, how
 * one is derived from a store's domain, and how a collision is resolved, so the
 * installers, the backfill and the platform-admin edit can never disagree.
 *
 * A handle is a single DNS label: lowercase letters, digits and dashes, 3–63
 * characters, starting and ending with a letter or digit. Reserved words (the
 * platform's own hosts) and anything shorter than three characters are refused.
 *
 * Derivation never asks a shopper: Shopify → the myshopify handle
 * (`tracki-inc-sp.myshopify.com` → `tracki-inc-sp`); WooCommerce → the store
 * domain with dots turned to dashes (`sellameir.ussl.co` → `sellameir-ussl-co`).
 * A taken or refused base gets a numeric suffix (`-2`, `-3`, …). A live ALIAS
 * (a handle a shop had until a platform admin renamed it) counts as taken, so a
 * new store can never inherit somebody's bookmarks.
 *
 * Every DB read here goes through the query builder (never the Shop model), so
 * the same code runs inside a migration without hydrating encrypted casts.
 */
final class ShopHandle
{
    // === CONSTANTS ===
    /** One DNS label, 1 or 3–63 chars (the length floor is enforced separately). */
    public const PATTERN = '/\A[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?\z/';

    public const MAX_LENGTH = 63;

    public const MIN_LENGTH = 3;

    /** The platform's own hosts — never a shop. */
    public const RESERVED = [
        'www', 'app', 'api', 'admin', 'platform', 'mail', 'static', 'assets', 'cdn',
        'status', 'help', 'docs', 'embed', 'proxy', 'horizon', 'lp',
    ];

    /** Used when a store's domain yields nothing usable (all non-ASCII, empty). */
    public const FALLBACK = 'shop';

    public const SHOPIFY_SUFFIX = '.myshopify.com';

    /** First numeric suffix tried on a collision (`acme` → `acme-2`). */
    public const FIRST_SUFFIX = 2;

    /** A hard stop for the suffix walk — reached only by a broken database. */
    public const MAX_SUFFIX = 10000;

    public const SHOPS_TABLE = 'shops';

    public const ALIASES_TABLE = 'shop_handle_aliases';

    /** Lowercase + trim. The canonical spelling every lookup uses. */
    public static function normalise(string $raw): string
    {
        return strtolower(trim($raw));
    }

    /** Is this a well-formed, non-reserved handle? (Uniqueness is separate.) */
    public static function isValid(string $handle): bool
    {
        return self::problem($handle) === null;
    }

    /**
     * Why a handle is refused, as a translation key under tenancy.handle.error,
     * or null when it is acceptable. Uniqueness is not judged here.
     */
    public static function problem(string $handle): ?string
    {
        if (strlen($handle) < self::MIN_LENGTH) {
            return 'too_short';
        }

        if (strlen($handle) > self::MAX_LENGTH || preg_match(self::PATTERN, $handle) !== 1) {
            return 'format';
        }

        if (in_array($handle, self::RESERVED, true)) {
            return 'reserved';
        }

        return null;
    }

    /** `tracki-inc-sp.myshopify.com` → `tracki-inc-sp` (a slug, not yet unique). */
    public static function fromShopifyDomain(string $domain): string
    {
        $domain = self::normalise($domain);

        if (str_ends_with($domain, self::SHOPIFY_SUFFIX)) {
            $domain = substr($domain, 0, -strlen(self::SHOPIFY_SUFFIX));
        }

        return self::slug($domain);
    }

    /** `https://www.sellameir.ussl.co/shop` → `sellameir-ussl-co` (not yet unique). */
    public static function fromWooDomain(string $domain): string
    {
        $raw = trim($domain);
        $withScheme = preg_match('#^https?://#i', $raw) === 1 ? $raw : 'https://'.$raw;
        $host = strtolower((string) (parse_url($withScheme, PHP_URL_HOST) ?: $raw));
        $host = (string) preg_replace('#^www\.#', '', $host);

        return self::slug($host);
    }

    /**
     * The base a shop's handle is derived from: its Shopify domain, else its
     * WooCommerce domain, else its name. Empty when none yields a usable label.
     */
    public static function baseFor(?string $shopifyDomain, ?string $wooDomain, ?string $name): string
    {
        $candidates = [
            filled($shopifyDomain) ? self::fromShopifyDomain((string) $shopifyDomain) : '',
            filled($wooDomain) ? self::fromWooDomain((string) $wooDomain) : '',
            filled($name) ? self::slug(Str::slug((string) $name)) : '',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Fold any string into a DNS-label shape: dots and every other character
     * outside [a-z0-9-] become dashes, runs collapse, ends are trimmed, length
     * is capped. May return '' — the caller falls back.
     */
    public static function slug(string $value): string
    {
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9-]+/', '-', $value);

        return self::trimTo($value, self::MAX_LENGTH);
    }

    /**
     * The first FREE, VALID handle for $base: the base itself, else base-2,
     * base-3, … A reserved or too-short base goes straight to its suffixed form
     * (`app` → `app-2`). $ignoreShopId lets a shop keep its own current handle.
     */
    public static function unique(string $base, ?int $ignoreShopId = null): string
    {
        $base = self::slug($base);
        if ($base === '') {
            $base = self::FALLBACK;
        }

        $taken = self::takenLike($base, $ignoreShopId);

        for ($n = self::FIRST_SUFFIX - 1; $n <= self::MAX_SUFFIX; $n++) {
            $candidate = $n < self::FIRST_SUFFIX ? $base : self::withSuffix($base, $n);

            if (self::isValid($candidate) && ! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No free shop handle for base "'.$base.'".');
    }

    /** Derive + make unique in one step (the installers' entry point). */
    public static function forShop(?string $shopifyDomain, ?string $wooDomain, ?string $name, ?int $ignoreShopId = null): string
    {
        return self::unique(self::baseFor($shopifyDomain, $wooDomain, $name), $ignoreShopId);
    }

    /**
     * Is $handle free for $shopId to take? Taken = another shop's handle, or a
     * live alias (a recently renamed shop's old address) of another shop. A
     * shop may always take back its OWN alias.
     */
    public static function isAvailable(string $handle, ?int $shopId = null): bool
    {
        $shopTaken = DB::table(self::SHOPS_TABLE)
            ->where('handle', $handle)
            ->when($shopId !== null, fn ($q) => $q->where('id', '!=', $shopId))
            ->exists();

        if ($shopTaken) {
            return false;
        }

        return ! DB::table(self::ALIASES_TABLE)
            ->where('handle', $handle)
            ->where('expires_at', '>', now())
            ->when($shopId !== null, fn ($q) => $q->where('shop_id', '!=', $shopId))
            ->exists();
    }

    /**
     * Every handle starting with $base held by a shop (other than $ignoreShopId)
     * or a live alias — ONE query each, so the suffix walk is in memory.
     *
     * @return array<string, true>
     */
    private static function takenLike(string $base, ?int $ignoreShopId): array
    {
        $shops = DB::table(self::SHOPS_TABLE)
            ->where('handle', 'like', $base.'%')
            ->when($ignoreShopId !== null, fn ($q) => $q->where('id', '!=', $ignoreShopId))
            ->pluck('handle');

        $aliases = DB::table(self::ALIASES_TABLE)
            ->where('handle', 'like', $base.'%')
            ->where('expires_at', '>', now())
            ->when($ignoreShopId !== null, fn ($q) => $q->where('shop_id', '!=', $ignoreShopId))
            ->pluck('handle');

        $taken = [];
        foreach ($shops->merge($aliases) as $handle) {
            $taken[(string) $handle] = true;
        }

        return $taken;
    }

    private static function withSuffix(string $base, int $n): string
    {
        $suffix = '-'.$n;

        return self::trimTo($base, self::MAX_LENGTH - strlen($suffix)).$suffix;
    }

    private static function trimTo(string $value, int $length): string
    {
        return trim(substr(trim($value, '-'), 0, $length), '-');
    }
}
