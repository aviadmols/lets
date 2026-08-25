<?php

/**
 * LETS — Israeli GOV address autocomplete for the checkout.
 *
 * WooCommerce's own #billing_city / #billing_address_1 fields (and their
 * shipping twins) gain suggestions from the government address registry on
 * data.gov.il: the ישובים dataset for cities, the רחובות dataset for streets,
 * narrowed by the chosen city. The registry holds cities and streets ONLY —
 * house numbers stay the shopper's to type, which is why the street field keeps
 * accepting free text after the pick.
 *
 * THE HOUSE RULE HOLDS: the browser talks to the PLUGIN's REST (WP nonce), and
 * the plugin's SERVER talks to data.gov.il (`wp_remote_get`) — the same proxy
 * shape as the product widget. Responses are cached in transients per distinct
 * prefix, so a store's shoppers typing "תל א" cost the GOV API one call a day,
 * not one per keystroke.
 *
 * FAILS INVISIBLY, ALWAYS. A slow or dead GOV API, a blocked request, an empty
 * answer — every failure path renders as "no suggestions", never as a broken
 * checkout. The fields are WooCommerce's; we only decorate them.
 *
 * Local toggle (Settings → LETS), ON by default — copy of the notify-email
 * checkbox pattern. Nothing here touches the SaaS.
 */
defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The local on/off option ("1"/"0"). Default ON. */
define('LETS_ADDRESS_OPT', 'lets_payplus_address_autocomplete');

/** data.gov.il CKAN datastore_search endpoint. */
define('LETS_ADDRESS_GOV_API', 'https://data.gov.il/api/3/action/datastore_search');

/** The ישובים (cities) resource + its name field. */
define('LETS_ADDRESS_CITIES_RESOURCE', '5c78e9fa-c2e2-4771-93ff-7f400a12f7ba');
define('LETS_ADDRESS_CITY_FIELD', 'שם_ישוב');

/** The רחובות (streets) resource + its fields. */
define('LETS_ADDRESS_STREETS_RESOURCE', '9ad3862c-8391-4b2f-84a4-2d4c68625f4b');
define('LETS_ADDRESS_STREET_FIELD', 'שם_רחוב');
define('LETS_ADDRESS_STREET_CITY_FIELD', 'שם_ישוב');

/** Suggestions per answer, cache lifetime, and the shortest prefix worth asking about. */
define('LETS_ADDRESS_LIMIT', 10);
define('LETS_ADDRESS_CACHE_TTL', 12 * HOUR_IN_SECONDS);
define('LETS_ADDRESS_MIN_CHARS', 2);

/* -------------------------------------------------------------------------
 * 1. The toggle
 * ---------------------------------------------------------------------- */

function lets_payplus_address_enabled()
{
    return '0' !== get_option(LETS_ADDRESS_OPT, '1');
}

/* -------------------------------------------------------------------------
 * 2. The proxy REST routes (browser → plugin, nonce; plugin → GOV, server)
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/address/cities', array(
        'methods'             => 'GET',
        'callback'            => 'lets_payplus_address_cities',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));

    register_rest_route(LETS_PAYPLUS_REST_NS, '/address/streets', array(
        'methods'             => 'GET',
        'callback'            => 'lets_payplus_address_streets',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

function lets_payplus_address_cities(WP_REST_Request $request)
{
    $q = lets_payplus_address_clean_term((string) $request->get_param('q'));
    if ('' === $q) {
        return rest_ensure_response(array('suggestions' => array()));
    }

    $suggestions = lets_payplus_address_lookup(
        'city:' . $q,
        LETS_ADDRESS_CITIES_RESOURCE,
        LETS_ADDRESS_CITY_FIELD,
        array(LETS_ADDRESS_CITY_FIELD => $q)
    );

    return rest_ensure_response(array('suggestions' => $suggestions));
}

function lets_payplus_address_streets(WP_REST_Request $request)
{
    $q = lets_payplus_address_clean_term((string) $request->get_param('q'));
    $city = lets_payplus_address_clean_term((string) $request->get_param('city'));

    if ('' === $q) {
        return rest_ensure_response(array('suggestions' => array()));
    }

    // The city narrows when known; without one the search is country-wide,
    // which is still useful for a shopper who typed the street first.
    $query = array(LETS_ADDRESS_STREET_FIELD => $q);
    if ('' !== $city) {
        $query[LETS_ADDRESS_STREET_CITY_FIELD] = $city;
    }

    $suggestions = lets_payplus_address_lookup(
        'street:' . $city . ':' . $q,
        LETS_ADDRESS_STREETS_RESOURCE,
        LETS_ADDRESS_STREET_FIELD,
        $query
    );

    return rest_ensure_response(array('suggestions' => $suggestions));
}

/**
 * One cached GOV lookup → a plain list of unique, trimmed names.
 *
 * @param  string  $cacheKey  distinct per (kind, prefix) — the transient key seed
 * @param  string  $resource  CKAN resource id
 * @param  string  $field     the field whose values become the suggestions
 * @param  array   $query     CKAN `q` filter map (field => term)
 * @return array<int, string>
 */
function lets_payplus_address_lookup($cacheKey, $resource, $field, $query)
{
    $transient = 'lets_addr_' . md5($cacheKey);

    $cached = get_transient($transient);
    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get(
        add_query_arg(array(
            'resource_id' => $resource,
            'q'           => wp_json_encode($query),
            'limit'       => LETS_ADDRESS_LIMIT * 3, // the registry holds dupes; trim after
        ), LETS_ADDRESS_GOV_API),
        array('timeout' => 5)
    );

    if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
        // A dead registry must not be re-asked on every keystroke — cache the
        // miss briefly, and the checkout simply shows no suggestions.
        set_transient($transient, array(), 5 * MINUTE_IN_SECONDS);

        return array();
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $records = isset($body['result']['records']) && is_array($body['result']['records'])
        ? $body['result']['records']
        : array();

    $names = array();
    foreach ($records as $record) {
        $name = isset($record[$field]) ? trim((string) $record[$field]) : '';
        if ('' !== $name && ! in_array($name, $names, true)) {
            $names[] = $name;
        }
        if (count($names) >= LETS_ADDRESS_LIMIT) {
            break;
        }
    }

    set_transient($transient, $names, LETS_ADDRESS_CACHE_TTL);

    return $names;
}

/** Trim, unslash, strip tags, and cap — a search term, not a payload. */
function lets_payplus_address_clean_term($value)
{
    $value = trim(wp_strip_all_tags(wp_unslash((string) $value)));

    if (mb_strlen($value) < LETS_ADDRESS_MIN_CHARS) {
        return '';
    }

    return mb_substr($value, 0, 64);
}

/* -------------------------------------------------------------------------
 * 3. The enqueue (classic checkout + the My Account address form)
 * ---------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'lets_payplus_address_assets', 20);

function lets_payplus_address_assets()
{
    if (! lets_payplus_address_enabled() || null === lets_payplus_connection()) {
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

    wp_localize_script('lets-payplus-address', 'LetsAddressCfg', array(
        'citiesUrl'  => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/address/cities')),
        'streetsUrl' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/address/streets')),
        'nonce'      => wp_create_nonce('wp_rest'),
        'minChars'   => LETS_ADDRESS_MIN_CHARS,
    ));

    wp_enqueue_script('lets-payplus-address');
}
