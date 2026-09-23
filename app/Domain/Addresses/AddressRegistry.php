<?php

namespace App\Domain\Addresses;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The country's localities and streets, so an address typed in the admin is
 * picked from the same closed list a shopper picks from at checkout.
 *
 * The store's checkout already works this way — the WordPress plugin downloads
 * these lists and makes the city a dropdown, enforced again server-side. An
 * admin typing the same address into a free-text box produced the one thing that
 * list exists to prevent: two spellings of one city, and a street the registry
 * has never heard of, on an address a courier has to deliver to.
 *
 * WHY NOT READ THE STORE'S OWN COPY. The obvious route — ask the plugin, which
 * already holds the lists — is closed: its address endpoints are guarded by a
 * WordPress REST nonce (`lets_payplus_rest_permission`), a browser-session
 * credential this server does not have and cannot mint. Reaching them would take
 * a new signed route in the plugin and a release every store has to install. The
 * registry is the SAME public source the plugin downloads from, with the same
 * resource ids and the same fields, so reading it directly gives the same list —
 * for every shop, including the Shopify ones the plugin never runs on.
 *
 * OUTBOUND POLICY. This is not a merchant-steered request (the wall
 * SafeSiteFetcher exists for): the host is a constant in this file and nothing a
 * merchant types reaches the URL except a numeric city code the registry itself
 * issued. It needs no SSRF walls because there is no address here to steer.
 *
 * FAIL OPEN, deliberately, and the same way the checkout does: when the registry
 * does not answer, `cities()` returns null and the form falls back to free text.
 * An outage must not stand between a merchant and recording a member.
 */
final class AddressRegistry
{
    // === CONSTANTS ===

    /** data.gov.il CKAN datastore_search. A constant, never merchant input. */
    public const GOV_API = 'https://data.gov.il/api/3/action/datastore_search';

    /** The ישובים (localities) resource + the fields read from it. */
    public const CITIES_RESOURCE = '5c78e9fa-c2e2-4771-93ff-7f400a12f7ba';

    public const CITY_FIELD = 'שם_ישוב';

    /** The ONE reliable join between the two resources — the registry's city code. */
    public const CITY_CODE_FIELD = 'סמל_ישוב';

    /** The רחובות (streets) resource + its street-name field. */
    public const STREETS_RESOURCE = '9ad3862c-8391-4b2f-84a4-2d4c68625f4b';

    public const STREET_FIELD = 'שם_רחוב';

    /** Rows per registry page and the page ceiling. The largest city has ~2,800 streets. */
    public const PAGE_SIZE = 5000;

    public const MAX_PAGES = 4;

    /** Seconds per page. A cold list is two or three of these. */
    public const TIMEOUT_SECONDS = 15;

    /**
     * How long a downloaded list is kept. The registry changes a few times a
     * year, so three months is well inside its own pace of change — and a list
     * that survives a quiet season is one nobody waits for.
     */
    public const LIST_TTL_MINUTES = 60 * 24 * 90;

    /** How long a failed download is remembered, so a dead registry is not re-asked per keystroke. */
    public const FAIL_TTL_MINUTES = 5;

    /** Cache seeds. Bump the suffix to re-download after a shape change. */
    public const CITIES_KEY = 'address.cities.v1';

    public const STREETS_KEY = 'address.streets.v1.';

    /** What a remembered failure looks like in the cache. */
    private const FAILED = 'fail';

    /** Characters that differ between the two registry resources, and between a typed name and a listed one. */
    private const NOISE = ['"', "'", '`', '׳', '״', '־', '-', '(', ')', '.', ','];

    /**
     * Every locality, as `display name => display name` — the shape a select
     * takes, and the name is what gets STORED, exactly as the checkout stores it.
     *
     * Null means the registry did not answer. That is not an empty list: an empty
     * list would mean "there are no cities", and a form reading it that way would
     * refuse every address in the country.
     *
     * @return array<string, string>|null
     */
    public static function cities(): ?array
    {
        $cached = Cache::get(self::CITIES_KEY);

        if ($cached === self::FAILED) {
            return null;
        }

        if (is_array($cached)) {
            return self::labels($cached);
        }

        $records = self::download(self::CITIES_RESOURCE);

        if ($records === null) {
            Cache::put(self::CITIES_KEY, self::FAILED, now()->addMinutes(self::FAIL_TTL_MINUTES));

            return null;
        }

        $cities = [];

        foreach ($records as $record) {
            $name = trim((string) ($record[self::CITY_FIELD] ?? ''));
            $code = (int) ($record[self::CITY_CODE_FIELD] ?? 0);

            if ($name === '' || $code === 0) {
                continue;
            }

            $cities[self::normalise($name)] = ['name' => $name, 'code' => $code];
        }

        if ($cities === []) {
            // A 200 carrying nothing usable is an outage in a friendly wrapper.
            Cache::put(self::CITIES_KEY, self::FAILED, now()->addMinutes(self::FAIL_TTL_MINUTES));

            return null;
        }

        uasort($cities, static fn (array $a, array $b): int => strcoll($a['name'], $b['name']));
        Cache::put(self::CITIES_KEY, $cities, now()->addMinutes(self::LIST_TTL_MINUTES));

        return self::labels($cities);
    }

    /**
     * Every street of one city, as `name => name`.
     *
     * An empty array means "we have no list for this city" — never "this city has
     * no streets" — and the form reads it exactly that way: no list, no rule.
     *
     * @return array<string, string>
     */
    public static function streetsIn(?string $city): array
    {
        $city = trim((string) $city);

        if ($city === '') {
            return [];
        }

        $code = self::codeFor($city);

        if ($code === null) {
            return [];
        }

        $key = self::STREETS_KEY.$code;
        $cached = Cache::get($key);

        if ($cached === self::FAILED) {
            return [];
        }

        if (is_array($cached)) {
            return self::pairs($cached);
        }

        $records = self::download(self::STREETS_RESOURCE, $code);

        if ($records === null) {
            Cache::put($key, self::FAILED, now()->addMinutes(self::FAIL_TTL_MINUTES));

            return [];
        }

        $streets = [];

        foreach ($records as $record) {
            $name = trim((string) ($record[self::STREET_FIELD] ?? ''));

            if ($name !== '') {
                $streets[self::normalise($name)] = $name;
            }
        }

        $streets = array_values($streets);
        sort($streets, SORT_LOCALE_STRING);
        Cache::put($key, $streets, now()->addMinutes(self::LIST_TTL_MINUTES));

        return self::pairs($streets);
    }

    /** Is this a locality the registry lists? False also when it did not answer. */
    public static function knowsCity(?string $city): bool
    {
        return self::codeFor((string) $city) !== null;
    }

    /** The registry's own code for a city, or null (unknown city, or no list). */
    private static function codeFor(string $city): ?int
    {
        if (self::cities() === null) {
            return null;
        }

        /** @var array<string, array{name: string, code: int}> $cities */
        $cities = (array) Cache::get(self::CITIES_KEY, []);
        $key = self::normalise($city);

        return isset($cities[$key]) ? (int) $cities[$key]['code'] : null;
    }

    /**
     * One resource, paged. A partial list beats none — a shopper mid-checkout and
     * a merchant mid-form both do better with most of the country than with a
     * spinner.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function download(string $resource, int $cityCode = 0): ?array
    {
        $records = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = [
                'resource_id' => $resource,
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
            ];

            if ($cityCode > 0) {
                $query['filters'] = json_encode([self::CITY_CODE_FIELD => $cityCode], JSON_UNESCAPED_UNICODE);
            }

            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->get(self::GOV_API, $query);
            } catch (\Throwable $e) {
                Log::warning('address.registry.transport_error', [
                    'resource' => $resource,
                    'error' => $e->getMessage(),
                ]);

                return $records === [] ? null : $records;
            }

            if (! $response->successful()) {
                return $records === [] ? null : $records;
            }

            $page_records = (array) $response->json('result.records', []);

            if ($page_records === []) {
                break;
            }

            $records = array_merge($records, $page_records);

            if (count($page_records) < self::PAGE_SIZE) {
                break;
            }
        }

        return $records;
    }

    /**
     * The spelling-tolerant form of a place name, for KEYS and matching only —
     * never for display and never for what gets saved. Hyphens, quotes and
     * repeated spaces are what differ between the two registry resources, and
     * between what somebody types and what the list holds.
     */
    public static function normalise(string $value): string
    {
        $value = str_replace(self::NOISE, ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param  array<string, array{name: string, code: int}>  $cities
     * @return array<string, string>
     */
    private static function labels(array $cities): array
    {
        $out = [];

        foreach ($cities as $city) {
            $out[$city['name']] = $city['name'];
        }

        return $out;
    }

    /**
     * @param  list<string>  $streets
     * @return array<string, string>
     */
    private static function pairs(array $streets): array
    {
        return array_combine($streets, $streets) ?: [];
    }
}
