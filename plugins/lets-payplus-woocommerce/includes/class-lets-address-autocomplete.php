<?php

/**
 * LETS — Israeli addresses at checkout: GOV autocomplete + the Israeli field
 * order.
 *
 * With the toggle ON, the address block becomes the shape an Israeli address
 * actually has: CITY first (required, and it must be a real city — picked from
 * the registry's list, enforced again server-side), then STREET, then four
 * separate fields — building number, apartment, floor, entrance. Building and
 * apartment ride into WooCommerce's own address_1/address_2 on order creation,
 * so shipping labels, invoices and LETS keep reading a complete address, while
 * the granular values are also saved as their own order meta.
 *
 * Suggestions come from the government registry on data.gov.il (ישובים for
 * cities, רחובות for streets narrowed by the chosen city). The registry holds
 * cities and streets ONLY — the numbers stay the shopper's to type.
 *
 * THE HOUSE RULE HOLDS: the browser talks to the PLUGIN's REST (WP nonce), and
 * the plugin's SERVER talks to data.gov.il (`wp_remote_get`) — the same proxy
 * shape as the product widget. Answers are transient-cached per prefix.
 *
 * FAILS OPEN, ALWAYS. A dead registry degrades to plain fields and NEVER blocks
 * a checkout: the city rule is only enforced when the registry answered — an
 * outage must not stand between a shopper and paying.
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
        $fields['address_1']['placeholder'] = $he ? 'שם הרחוב' : __('Street name', 'lets-payplus');
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
                lets_payplus_address_is_he()
                    ? 'יש לבחור עיר מתוך רשימת ההשלמה בשדה העיר.'
                    : __('Please choose a city from the suggestion list in the city field.', 'lets-payplus')
            );
        }
    }
}

/**
 * Is this a real city per the registry? TRUE / FALSE / NULL (registry down —
 * the caller must fail open). Compared trimmed, because the registry pads its
 * names with trailing spaces.
 *
 * @param  string  $city
 * @return bool|null
 */
function lets_payplus_address_city_is_known($city)
{
    $names = lets_payplus_address_lookup(
        'city:' . $city,
        LETS_ADDRESS_CITIES_RESOURCE,
        LETS_ADDRESS_CITY_FIELD,
        array(LETS_ADDRESS_CITY_FIELD => $city)
    );

    if (null === $names) {
        return null;
    }

    return in_array($city, $names, true);
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
 * 4. The proxy REST routes (browser → plugin, nonce; plugin → GOV, server)
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

    return rest_ensure_response(array('suggestions' => null === $suggestions ? array() : $suggestions));
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

    return rest_ensure_response(array('suggestions' => null === $suggestions ? array() : $suggestions));
}

/**
 * One cached GOV lookup → a plain list of unique, trimmed names — or NULL when
 * the registry did not answer, so a caller enforcing a rule can tell "no such
 * name" apart from "no answer" and fail open.
 *
 * @param  string  $cacheKey  distinct per (kind, prefix) — the transient key seed
 * @param  string  $resource  CKAN resource id
 * @param  string  $field     the field whose values become the suggestions
 * @param  array   $query     CKAN `q` filter map (field => term)
 * @return array<int, string>|null
 */
function lets_payplus_address_lookup($cacheKey, $resource, $field, $query)
{
    $transient = 'lets_addr_' . md5($cacheKey);

    $cached = get_transient($transient);
    if (is_array($cached)) {
        return $cached;
    }
    if ('fail' === $cached) {
        return null;
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
        // A dead registry must not be re-asked on every keystroke — remember the
        // miss briefly, as a MISS: suggestions go quiet, rules fail open.
        set_transient($transient, 'fail', 5 * MINUTE_IN_SECONDS);

        return null;
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
 * 5. The enqueue (classic checkout + the My Account address form)
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

    wp_localize_script('lets-payplus-address', 'LetsAddressCfg', array(
        'citiesUrl'  => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/address/cities')),
        'streetsUrl' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/address/streets')),
        'nonce'      => wp_create_nonce('wp_rest'),
        'minChars'   => LETS_ADDRESS_MIN_CHARS,
        'cityHint'   => lets_payplus_address_is_he()
            ? 'יש לבחור עיר מתוך הרשימה'
            : __('Please choose a city from the list', 'lets-payplus'),
    ));

    wp_enqueue_script('lets-payplus-address');
}
