<?php

/**
 * LETS — Israeli addresses: a real city/street PICKER + the Israeli field order.
 *
 * With the toggle ON, the address block becomes the shape an Israeli address
 * actually has: CITY first (required, and it must be a real city — picked from
 * the registry's list, enforced again server-side), then STREET, then four
 * separate fields — building number, apartment, floor, entrance. Building and
 * apartment ride into WooCommerce's own address_1/address_2 on order creation,
 * so shipping labels, invoices and LETS keep reading a complete address, while
 * the granular values are also saved as their own order meta.
 *
 * WHY THE WHOLE LIST, NOT A SEARCH PER KEYSTROKE. The registry's search is a
 * FULL-TEXT one: it matches whole words, so "הרצ" finds nothing and only a
 * shopper who types "הרצל" exactly is rewarded. That is why the city field
 * seemed to work (people type short, complete city names) and the street field
 * did not. So we no longer search it at all — we DOWNLOAD it: every locality
 * once (1,300 rows), and every street of a city the first time somebody picks
 * that city (a few thousand rows, keyed on the registry's own city code, not on
 * its name). Both are cached here for three months, and the browser filters the
 * list it already holds — which is what makes the dropdown instant and makes
 * as-you-type PREFIX matching possible in the first place.
 *
 * THE HOUSE RULE HOLDS: the browser talks to the PLUGIN's REST (WP nonce), and
 * the plugin's SERVER talks to data.gov.il (`wp_remote_get`) — the same proxy
 * shape as the product widget.
 *
 * FAILS OPEN, ALWAYS. A dead registry degrades to plain fields and NEVER blocks
 * a checkout: the city rule is only enforced when the registry answered, and a
 * street list we do not have leaves the street field free text — an outage must
 * not stand between a shopper and paying.
 */
defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The local on/off option ("1"/"0"). Default ON. */
define('LETS_ADDRESS_OPT', 'lets_payplus_address_autocomplete');

/** data.gov.il CKAN datastore_search endpoint. */
define('LETS_ADDRESS_GOV_API', 'https://data.gov.il/api/3/action/datastore_search');

/** The ישובים (localities) resource + the fields we read from it. */
define('LETS_ADDRESS_CITIES_RESOURCE', '5c78e9fa-c2e2-4771-93ff-7f400a12f7ba');
define('LETS_ADDRESS_CITY_FIELD', 'שם_ישוב');
define('LETS_ADDRESS_CITY_LATIN_FIELD', 'שם_ישוב_לועזי');

/** The ONE reliable join between the two resources — the registry's city code. */
define('LETS_ADDRESS_CITY_CODE_FIELD', 'סמל_ישוב');

/** The רחובות (streets) resource + its street-name field. */
define('LETS_ADDRESS_STREETS_RESOURCE', '9ad3862c-8391-4b2f-84a4-2d4c68625f4b');
define('LETS_ADDRESS_STREET_FIELD', 'שם_רחוב');

/** Rows per registry page and the page ceiling. The largest city has ~2,800 streets. */
define('LETS_ADDRESS_PAGE_SIZE', 5000);
define('LETS_ADDRESS_MAX_PAGES', 4);

/** Seconds we give the registry per page. A cold list is two or three of these. */
define('LETS_ADDRESS_TIMEOUT', 15);

/**
 * How long a downloaded list is kept. The registry changes a few times a year,
 * so three months is still well inside its own pace of change — and a list that
 * survives a quiet season is a list nobody waits for at checkout.
 */
define('LETS_ADDRESS_LIST_TTL', 90 * DAY_IN_SECONDS);

/** How long a failed download is remembered, so a dead registry is not re-asked per view. */
define('LETS_ADDRESS_FAIL_TTL', 5 * MINUTE_IN_SECONDS);

/** Transient seeds. Bump the suffix to re-download after a shape change. */
define('LETS_ADDRESS_CITIES_KEY', 'lets_addr_cities_v2');
define('LETS_ADDRESS_STREETS_KEY', 'lets_addr_streets_v2_');

/**
 * Which cities have a street list cached, and when the locality list was last
 * downloaded.
 *
 * The index exists so "refresh" can DELETE those lists: a transient is not
 * always a row in the options table — with an external object cache it is a key
 * in memcached — so a prefix query cannot find them. Remembering the codes lets
 * the flush go through `delete_transient()`, which is right wherever they live.
 */
define('LETS_ADDRESS_STREETS_INDEX_OPT', 'lets_payplus_address_street_cities');
define('LETS_ADDRESS_SYNCED_OPT', 'lets_payplus_address_synced');

/** How long the refresh button may spend re-warming street lists before it stops. */
define('LETS_ADDRESS_REWARM_SECONDS', 20);

/** The four granular fields (checkout key suffix => saved order meta suffix). */
define('LETS_ADDRESS_EXTRA_FIELDS', 'building_number,apartment_number,floor,entrance');

/* -------------------------------------------------------------------------
 * 1. The toggle + language
 * ---------------------------------------------------------------------- */

function lets_payplus_address_enabled()
{
    return '0' !== get_option(LETS_ADDRESS_OPT, '1');
}

/** Hebrew storefront? Reuses the account file's helper when loaded. */
function lets_payplus_address_is_he()
{
    if (function_exists('lets_payplus_account_is_he')) {
        return lets_payplus_account_is_he();
    }

    return 0 === strpos((string) get_locale(), 'he');
}

/* -------------------------------------------------------------------------
 * 2. The Israeli field order — city → street → number/apartment/floor/entrance
 * ---------------------------------------------------------------------- */

/**
 * One filter reshapes BOTH billing and shipping (WooCommerce derives the two
 * from the default address fields), and the My Account address form with them.
 */
add_filter('woocommerce_default_address_fields', 'lets_payplus_address_fields', 20);

function lets_payplus_address_fields($fields)
{
    if (! lets_payplus_address_enabled()) {
        return $fields;
    }

    $he = lets_payplus_address_is_he();

    // CITY comes first — and must be a real one, picked from the list (the
    // dropdown enforces it in the browser, the checkout validation on the server).
    if (isset($fields['city'])) {
        $fields['city']['priority'] = 45;
        $fields['city']['label'] = $he ? 'עיר' : __('City', 'lets-payplus');
        $fields['city']['placeholder'] = $he ? 'בחרו עיר מהרשימה' : __('Choose a city from the list', 'lets-payplus');
        $fields['city']['required'] = true;
    }

    // STREET is exactly the street — the number has its own field now.
    if (isset($fields['address_1'])) {
        $fields['address_1']['priority'] = 50;
        $fields['address_1']['label'] = $he ? 'רחוב' : __('Street', 'lets-payplus');
        $fields['address_1']['placeholder'] = $he ? 'בחרו רחוב מהרשימה' : __('Choose a street from the list', 'lets-payplus');
    }

    // address_2's job is taken over by the granular fields; it is composed back
    // into the order on save, so nothing downstream loses it.
    unset($fields['address_2']);

    $fields['building_number'] = array(
        'label'       => $he ? 'מספר בניין' : __('Building number', 'lets-payplus'),
        'required'    => true,
        'class'       => array('form-row-first'),
        'priority'    => 52,
        'maxlength'   => 10,
        'autocomplete' => 'off',
    );

    $fields['apartment_number'] = array(
        'label'       => $he ? 'מספר דירה' : __('Apartment', 'lets-payplus'),
        'required'    => false,
        'class'       => array('form-row-last'),
        'priority'    => 54,
        'maxlength'   => 10,
        'autocomplete' => 'off',
    );

    $fields['floor'] = array(
        'label'       => $he ? 'קומה' : __('Floor', 'lets-payplus'),
        'required'    => false,
        'class'       => array('form-row-first'),
        'priority'    => 56,
        'maxlength'   => 10,
        'autocomplete' => 'off',
    );

    $fields['entrance'] = array(
        'label'       => $he ? 'כניסה' : __('Entrance', 'lets-payplus'),
        'required'    => false,
        'class'       => array('form-row-last'),
        'priority'    => 58,
        'maxlength'   => 10,
        'autocomplete' => 'off',
    );

    return $fields;
}

/**
 * Fold the granular fields back into WooCommerce's own address columns on the
 * ORDER: address_1 = "street building", address_2 = "apartment · floor ·
 * entrance". Everything downstream — shipping labels, invoices, LETS's own
 * order readers — keeps seeing a complete address, and the granular values are
 * ALSO kept as their own meta for anything that wants them separated.
 */
add_action('woocommerce_checkout_create_order', 'lets_payplus_address_compose', 20, 2);

function lets_payplus_address_compose($order, $data)
{
    if (! lets_payplus_address_enabled()) {
        return;
    }

    $types = array('billing');
    if (! empty($data['ship_to_different_address'])) {
        $types[] = 'shipping';
    }

    $he = lets_payplus_address_is_he();

    foreach ($types as $type) {
        $street = trim((string) ($data[$type . '_address_1'] ?? ''));
        $building = trim((string) ($data[$type . '_building_number'] ?? ''));
        $apartment = trim((string) ($data[$type . '_apartment_number'] ?? ''));
        $floor = trim((string) ($data[$type . '_floor'] ?? ''));
        $entrance = trim((string) ($data[$type . '_entrance'] ?? ''));

        if ('' !== $building && '' !== $street) {
            $setter = "set_{$type}_address_1";
            if (is_callable(array($order, $setter))) {
                $order->{$setter}(trim($street . ' ' . $building));
            }
        }

        $parts = array();
        if ('' !== $apartment) {
            $parts[] = ($he ? 'דירה ' : 'Apt. ') . $apartment;
        }
        if ('' !== $floor) {
            $parts[] = ($he ? 'קומה ' : 'Floor ') . $floor;
        }
        if ('' !== $entrance) {
            $parts[] = ($he ? 'כניסה ' : 'Entrance ') . $entrance;
        }

        if (array() !== $parts) {
            $setter = "set_{$type}_address_2";
            if (is_callable(array($order, $setter))) {
                $order->{$setter}(implode(', ', $parts));
            }
        }

        // Insurance: the granular values as their own meta, whatever WooCommerce
        // decided to do with unfamiliar field keys in this version.
        foreach (explode(',', LETS_ADDRESS_EXTRA_FIELDS) as $suffix) {
            $value = trim((string) ($data[$type . '_' . $suffix] ?? ''));
            if ('' !== $value) {
                $order->update_meta_data('_' . $type . '_' . $suffix, $value);
            }
        }
    }
}

/* -------------------------------------------------------------------------
 * 3. "The city must come from the list" — the server half of the rule
 * ---------------------------------------------------------------------- */

add_action('woocommerce_after_checkout_validation', 'lets_payplus_address_validate_city', 10, 2);

function lets_payplus_address_validate_city($data, $errors)
{
    if (! lets_payplus_address_enabled()) {
        return;
    }

    $types = array('billing');
    if (! empty($data['ship_to_different_address'])) {
        $types[] = 'shipping';
    }

    foreach ($types as $type) {
        $city = trim((string) ($data[$type . '_city'] ?? ''));
        if ('' === $city) {
            continue; // required-ness is WooCommerce's own message
        }

        // NULL = the registry did not answer → fail open. Only a confident
        // "no such city" blocks, with a sentence that says what to do.
        if (false === lets_payplus_address_city_is_known($city)) {
            $errors->add(
                $type . '_city',
                lets_payplus_address_city_error()
            );
        }
    }
}

/** The one sentence a rejected city gets, wherever the rule is enforced. */
function lets_payplus_address_city_error()
{
    return lets_payplus_address_is_he()
        ? 'יש לבחור עיר מתוך רשימת ההשלמה בשדה העיר.'
        : __('Please choose a city from the suggestion list in the city field.', 'lets-payplus');
}

/**
 * Is this a real city per the registry? TRUE / FALSE / NULL (registry down —
 * the caller must fail open).
 *
 * @param  string  $city
 * @return bool|null
 */
function lets_payplus_address_city_is_known($city)
{
    $cities = lets_payplus_address_cities();

    if (null === $cities) {
        return null;
    }

    return isset($cities[lets_payplus_address_normalise($city)]);
}

/* -------------------------------------------------------------------------
 * 3b. Tell LETS when a customer saves their address (a Timeline fact — the
 *     address itself stays WooCommerce's; LETS keeps no warehouse copy).
 * ---------------------------------------------------------------------- */

add_action('woocommerce_customer_save_address', 'lets_payplus_address_report_update', 10, 2);

function lets_payplus_address_report_update($user_id, $load_address)
{
    if (null === lets_payplus_connection() || ! function_exists('lets_payplus_signed_post')) {
        return;
    }

    $user = get_userdata((int) $user_id);
    if (! $user) {
        return;
    }

    // Fire and forget: a Timeline line is nice to have, never worth an error
    // in the shopper's face. The signed helper already swallows transport noise.
    lets_payplus_signed_post('/api/woocommerce/account/address-updated', array(
        'customer_ref' => (string) $user_id,
        'email'        => (string) $user->user_email,
        'name'         => (string) $user->display_name,
        'type'         => sanitize_key((string) $load_address),
    ));
}

/* -------------------------------------------------------------------------
 * 4. The lists — downloaded once, cached here, filtered in the browser
 * ---------------------------------------------------------------------- */

/**
 * Every locality in the country, keyed by its NORMALISED name so a lookup does
 * not depend on how the registry spaced its hyphens:
 *
 *     'תל אביב יפו' => array('name' => 'תל אביב - יפו', 'code' => 5000, 'latin' => 'TEL AVIV - YAFO')
 *
 * NULL when the registry did not answer — the callers fail open on NULL.
 *
 * @return array<string, array>|null
 */
function lets_payplus_address_cities()
{
    $cached = get_transient(LETS_ADDRESS_CITIES_KEY);
    if (is_array($cached)) {
        return $cached;
    }
    if ('fail' === $cached) {
        return null;
    }

    $records = lets_payplus_address_download(LETS_ADDRESS_CITIES_RESOURCE, 0);

    if (null === $records) {
        set_transient(LETS_ADDRESS_CITIES_KEY, 'fail', LETS_ADDRESS_FAIL_TTL);

        return null;
    }

    $cities = array();
    foreach ($records as $record) {
        $name = trim((string) ($record[LETS_ADDRESS_CITY_FIELD] ?? ''));
        $code = (int) ($record[LETS_ADDRESS_CITY_CODE_FIELD] ?? 0);

        if ('' === $name || 0 === $code) {
            continue;
        }

        $cities[lets_payplus_address_normalise($name)] = array(
            'name'  => $name,
            'code'  => $code,
            'latin' => lets_payplus_address_latin((string) ($record[LETS_ADDRESS_CITY_LATIN_FIELD] ?? '')),
        );
    }

    if (array() === $cities) {
        // A 200 that carried nothing usable is an outage in a friendly wrapper.
        set_transient(LETS_ADDRESS_CITIES_KEY, 'fail', LETS_ADDRESS_FAIL_TTL);

        return null;
    }

    uasort($cities, 'lets_payplus_address_compare');
    set_transient(LETS_ADDRESS_CITIES_KEY, $cities, LETS_ADDRESS_LIST_TTL);
    update_option(LETS_ADDRESS_SYNCED_OPT, time(), false);

    return $cities;
}

/**
 * Every street of one city, by the city's own name as the registry spells it.
 *
 * An empty array means "we have no list for this city" — never "this city has
 * no streets" — and the browser reads it exactly that way: no list, no rule.
 *
 * @param  string  $city
 * @return array<int, string>
 */
function lets_payplus_address_streets_in($city)
{
    $cities = lets_payplus_address_cities();
    $key = lets_payplus_address_normalise($city);

    if (null === $cities || ! isset($cities[$key])) {
        return array();
    }

    $code = (int) $cities[$key]['code'];
    $transient = LETS_ADDRESS_STREETS_KEY . $code;

    $cached = get_transient($transient);
    if (is_array($cached)) {
        return $cached;
    }
    if ('fail' === $cached) {
        return array();
    }

    $records = lets_payplus_address_download(LETS_ADDRESS_STREETS_RESOURCE, $code);

    if (null === $records) {
        set_transient($transient, 'fail', LETS_ADDRESS_FAIL_TTL);

        return array();
    }

    $streets = array();
    foreach ($records as $record) {
        $name = trim((string) ($record[LETS_ADDRESS_STREET_FIELD] ?? ''));
        if ('' !== $name) {
            $streets[lets_payplus_address_normalise($name)] = $name;
        }
    }

    $streets = array_values($streets);
    usort($streets, 'strcmp');

    set_transient($transient, $streets, LETS_ADDRESS_LIST_TTL);
    lets_payplus_address_remember_city($code);

    return $streets;
}

/**
 * Page through one CKAN resource and hand back its raw records.
 *
 * The city filter is the registry's own NUMERIC code, not a name: an exact
 * match on a number cannot be defeated by the trailing spaces and the hyphen
 * spellings that make the name columns unreliable.
 *
 * @param  string  $resource  CKAN resource id
 * @param  int     $cityCode  0 for the whole resource
 * @return array<int, array>|null  NULL when the registry did not answer
 */
function lets_payplus_address_download($resource, $cityCode)
{
    $records = array();

    for ($page = 0; $page < LETS_ADDRESS_MAX_PAGES; $page++) {
        $args = array(
            'resource_id' => $resource,
            'limit'       => LETS_ADDRESS_PAGE_SIZE,
            'offset'      => $page * LETS_ADDRESS_PAGE_SIZE,
        );

        if ($cityCode > 0) {
            // wp_json_encode escapes the Hebrew key to \uXXXX, so the query
            // string stays pure ASCII whatever the server's locale.
            $args['filters'] = wp_json_encode(array(LETS_ADDRESS_CITY_CODE_FIELD => $cityCode));
        }

        // http_build_query, not add_query_arg: the latter writes new values into
        // the URL unencoded, and the filter is JSON — braces and quotes and all.
        $response = wp_remote_get(
            LETS_ADDRESS_GOV_API . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986),
            array('timeout' => LETS_ADDRESS_TIMEOUT)
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return array() === $records ? null : $records; // a partial list beats none
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $page_records = isset($body['result']['records']) && is_array($body['result']['records'])
            ? $body['result']['records']
            : array();

        if (array() === $page_records) {
            break;
        }

        $records = array_merge($records, $page_records);

        if (count($page_records) < LETS_ADDRESS_PAGE_SIZE) {
            break;
        }
    }

    return $records;
}

/**
 * The spelling-tolerant form of a place name, for KEYS and for matching only —
 * never for display and never for what gets saved. Hyphens, quotes and repeated
 * spaces are what differ between the two registry resources and between what a
 * shopper types and what the list holds.
 *
 * @param  string  $value
 * @return string
 */
function lets_payplus_address_normalise($value)
{
    $value = str_replace(
        array('"', "'", '`', '׳', '״', '־', '-', '(', ')', '.', ','),
        ' ',
        (string) $value
    );
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim(mb_strtolower((string) $value));
}

/** The registry's Latin name, or '' — a searchable alias, never a stored value. */
function lets_payplus_address_latin($value)
{
    $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

    return '' === $value ? '' : $value;
}

/** Alphabetical by the registry's own spelling. */
function lets_payplus_address_compare($a, $b)
{
    return strcmp((string) $a['name'], (string) $b['name']);
}

/* -------------------------------------------------------------------------
 * 5. The proxy REST routes (browser → plugin, nonce; plugin → GOV, server)
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/address/cities', array(
        'methods'             => 'GET',
        'callback'            => 'lets_payplus_address_cities_route',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));

    register_rest_route(LETS_PAYPLUS_REST_NS, '/address/streets', array(
        'methods'             => 'GET',
        'callback'            => 'lets_payplus_address_streets_route',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

/**
 * The whole locality list, as pairs of [name, latin alias]. One request per
 * browsing session (the browser keeps it in sessionStorage), never one per
 * keystroke.
 */
function lets_payplus_address_cities_route(WP_REST_Request $request)
{
    $cities = lets_payplus_address_cities();

    if (null === $cities) {
        return rest_ensure_response(array('cities' => array(), 'known' => false));
    }

    $out = array();
    foreach ($cities as $city) {
        $out[] = array($city['name'], $city['latin']);
    }

    return rest_ensure_response(array('cities' => $out, 'known' => true));
}

/** Every street of one city — the whole list, filtered in the browser. */
function lets_payplus_address_streets_route(WP_REST_Request $request)
{
    $city = trim(wp_strip_all_tags(wp_unslash((string) $request->get_param('city'))));

    if ('' === $city) {
        return rest_ensure_response(array('streets' => array()));
    }

    return rest_ensure_response(array(
        'streets' => lets_payplus_address_streets_in(mb_substr($city, 0, 120)),
    ));
}

/* -------------------------------------------------------------------------
 * 6. The enqueue (classic checkout + the My Account address form)
 * ---------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'lets_payplus_address_assets', 20);

function lets_payplus_address_assets()
{
    if (! lets_payplus_address_enabled()) {
        return;
    }

    $on_checkout = function_exists('is_checkout') && is_checkout();
    $on_account = function_exists('is_account_page') && is_account_page();

    if (! $on_checkout && ! $on_account) {
        return;
    }

    wp_enqueue_style(
        'lets-payplus-address',
        LETS_PAYPLUS_URL . 'assets/css/lets-address.css',
        array(),
        LETS_PAYPLUS_VERSION
    );

    wp_register_script(
        'lets-payplus-address',
        LETS_PAYPLUS_URL . 'assets/js/lets-address.js',
        array(),
        LETS_PAYPLUS_VERSION,
        true
    );

    $he = lets_payplus_address_is_he();

    wp_localize_script('lets-payplus-address', 'LetsAddressCfg', array(
        'citiesUrl'  => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/address/cities')),
        'streetsUrl' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/address/streets')),
        'nonce'      => wp_create_nonce('wp_rest'),
        'i18n'       => array(
            'cityHint'   => $he ? 'יש לבחור עיר מתוך הרשימה' : __('Please choose a city from the list', 'lets-payplus'),
            'streetHint' => $he ? 'יש לבחור רחוב מתוך הרשימה' : __('Please choose a street from the list', 'lets-payplus'),
            'needCity'   => $he ? 'בחרו קודם עיר' : __('Choose a city first', 'lets-payplus'),
            'loading'    => $he ? 'טוען…' : __('Loading…', 'lets-payplus'),
            'noMatches'  => $he ? 'לא נמצאה התאמה' : __('No matches', 'lets-payplus'),
            'more'       => $he ? 'המשיכו להקליד כדי לצמצם' : __('Keep typing to narrow the list', 'lets-payplus'),
        ),
    ));

    wp_enqueue_script('lets-payplus-address');
}

/* -------------------------------------------------------------------------
 * 7. "Update the lists now" — the merchant's own refresh button
 * ---------------------------------------------------------------------- */

/** Note that this city's streets are cached, so a flush can find them again. */
function lets_payplus_address_remember_city($code)
{
    $code = (int) $code;
    $codes = array_map('intval', (array) get_option(LETS_ADDRESS_STREETS_INDEX_OPT, array()));

    if (in_array($code, $codes, true)) {
        return;
    }

    $codes[] = $code;
    update_option(LETS_ADDRESS_STREETS_INDEX_OPT, $codes, false);
}

/**
 * Drop every downloaded list.
 *
 * @return array<int, int>  the city codes whose streets were cached, so the
 *                          caller can put back what the store actually uses
 */
function lets_payplus_address_flush()
{
    delete_transient(LETS_ADDRESS_CITIES_KEY);

    $codes = array_map('intval', (array) get_option(LETS_ADDRESS_STREETS_INDEX_OPT, array()));
    foreach ($codes as $code) {
        delete_transient(LETS_ADDRESS_STREETS_KEY . $code);
    }

    delete_option(LETS_ADDRESS_STREETS_INDEX_OPT);

    return $codes;
}

/**
 * Re-download the street lists the store had before the flush, newest registry
 * data, so the cities its shoppers actually use are not cold afterwards.
 *
 * Bounded by a clock, not by a count: a store with two hundred cached cities
 * must not turn one button into a two-minute request. Whatever the budget does
 * not reach simply downloads again on the first shopper who needs it.
 *
 * @param  array<int, int>  $codes
 * @return int  how many lists were put back
 */
function lets_payplus_address_rewarm($codes)
{
    $cities = lets_payplus_address_cities();
    if (null === $cities || array() === $codes) {
        return 0;
    }

    $names = array();
    foreach ($cities as $city) {
        $names[(int) $city['code']] = $city['name'];
    }

    $started = microtime(true);
    $done = 0;

    foreach ($codes as $code) {
        if (microtime(true) - $started > LETS_ADDRESS_REWARM_SECONDS) {
            break;
        }
        if (! isset($names[$code])) {
            continue; // a locality the registry has since dropped
        }

        lets_payplus_address_streets_in($names[$code]);
        $done++;
    }

    return $done;
}

/** What the settings screen reports: how much is cached, and since when. */
function lets_payplus_address_status()
{
    $cities = get_transient(LETS_ADDRESS_CITIES_KEY);

    return array(
        'cities'  => is_array($cities) ? count($cities) : 0,
        'streets' => count((array) get_option(LETS_ADDRESS_STREETS_INDEX_OPT, array())),
        'synced'  => (int) get_option(LETS_ADDRESS_SYNCED_OPT, 0),
    );
}

/** The nonced URL behind the refresh button. */
function lets_payplus_address_refresh_url()
{
    return wp_nonce_url(
        admin_url('admin-post.php?action=lets_payplus_refresh_addresses'),
        'lets_payplus_refresh_addresses'
    );
}

add_action('admin_post_lets_payplus_refresh_addresses', 'lets_payplus_address_refresh');

function lets_payplus_address_refresh()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to do this.', 'lets-payplus'), 403);
    }

    check_admin_referer('lets_payplus_refresh_addresses');

    // An explicit click may take longer than a page view is normally given.
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }

    $he = lets_payplus_address_is_he();
    $cached = lets_payplus_address_flush();
    $cities = lets_payplus_address_cities();

    if (null === $cities) {
        set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array(
            'type'    => 'error',
            'message' => $he
                ? 'לא הצלחנו להוריד את רשימת היישובים מ‑data.gov.il. השדות ממשיכים לעבוד כשדות טקסט רגילים; אפשר לנסות שוב בעוד כמה דקות.'
                : __('Could not download the locality list from data.gov.il. The fields keep working as plain text — try again in a few minutes.', 'lets-payplus'),
        ), 60);

        lets_payplus_diag_back();
    }

    $rewarmed = lets_payplus_address_rewarm($cached);
    $pending = max(0, count($cached) - $rewarmed);

    $message = $he
        ? sprintf('רשימת הכתובות עודכנה: %s יישובים, ו‑%s רשימות רחובות נטענו מחדש.', number_format_i18n(count($cities)), number_format_i18n($rewarmed))
        : sprintf(
            /* translators: 1: locality count, 2: street-list count */
            __('Address lists updated: %1$s localities, and %2$s street lists reloaded.', 'lets-payplus'),
            number_format_i18n(count($cities)),
            number_format_i18n($rewarmed)
        );

    if ($pending > 0) {
        $message .= ' ' . ($he
            ? sprintf('%s רשימות נוספות ייטענו מחדש בפעם הבאה שלקוח יבחר את העיר.', number_format_i18n($pending))
            : sprintf(
                /* translators: %s: street-list count */
                __('%s more will reload the next time a shopper picks that city.', 'lets-payplus'),
                number_format_i18n($pending)
            ));
    }

    set_transient(LETS_PAYPLUS_DIAG_TRANSIENT, array('type' => 'ok', 'message' => $message), 60);

    lets_payplus_diag_back();
}
