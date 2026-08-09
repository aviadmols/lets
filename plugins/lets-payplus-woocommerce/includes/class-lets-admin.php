<?php

/**
 * LETS — the personal area's settings, INSIDE WordPress.
 *
 * The merchant asked to stop leaving their own admin to configure their own
 * shop. So the banners, the appearance, the section order and the copy are
 * edited here, on a normal WordPress screen, with WordPress's own form styles.
 *
 * What this file is NOT is a second source of truth. Every screen reads and
 * writes ONE row on the SaaS — `merchant_portal_appearances` — over the signed
 * channel the plugin already uses for everything else. The Filament preview,
 * the storefront and this screen therefore cannot disagree: there is nothing
 * stored locally to disagree with. A store that is not connected shows the
 * connect notice and no forms, because there is nothing to edit yet.
 *
 * Saving goes through admin-post.php (nonce + capability), never through a
 * REST call from the browser: the api_secret lives on the server and the
 * browser never gets to sign anything.
 *
 * @package LETS_PayPlus
 */

if (! defined('ABSPATH')) {
    exit;
}

// === CONSTANTS ===

define('LETS_ADMIN_SLUG', 'lets-payplus-portal');
define('LETS_ADMIN_CAPABILITY', 'manage_options');
define('LETS_ADMIN_SAVE_ACTION', 'lets_payplus_save_portal');
define('LETS_ADMIN_NONCE', 'lets_payplus_portal_settings');
define('LETS_ADMIN_ENDPOINT', '/api/woocommerce/portal-settings');

/** Tab slug → the Hebrew/English label pair the screen prints. */
define('LETS_ADMIN_TABS', 'appearance|sections|banners|copy');

// ---------------------------------------------------------------------------
// Menu
// ---------------------------------------------------------------------------

add_action('admin_menu', 'lets_payplus_admin_menu', 9);

function lets_payplus_admin_menu()
{
    add_menu_page(
        lets_payplus_admin_text('LETS — האזור האישי', 'LETS — Personal area'),
        'LETS',
        LETS_ADMIN_CAPABILITY,
        LETS_ADMIN_SLUG,
        'lets_payplus_admin_render',
        'dashicons-id-alt',
        56
    );

    // The connect screen keeps its home under Settings, but a merchant who is
    // standing in the LETS menu should not have to go hunting for it.
    add_submenu_page(
        LETS_ADMIN_SLUG,
        lets_payplus_admin_text('האזור האישי', 'Personal area'),
        lets_payplus_admin_text('האזור האישי', 'Personal area'),
        LETS_ADMIN_CAPABILITY,
        LETS_ADMIN_SLUG,
        'lets_payplus_admin_render'
    );

    add_submenu_page(
        LETS_ADMIN_SLUG,
        lets_payplus_admin_text('חיבור והגדרות', 'Connection & settings'),
        lets_payplus_admin_text('חיבור והגדרות', 'Connection & settings'),
        LETS_ADMIN_CAPABILITY,
        'options-general.php?page=lets-payplus'
    );
}

/** Hebrew when the site is Hebrew, English otherwise. */
function lets_payplus_admin_text($he, $en)
{
    return function_exists('lets_payplus_is_he') && lets_payplus_is_he() ? $he : $en;
}

// ---------------------------------------------------------------------------
// Screen
// ---------------------------------------------------------------------------

function lets_payplus_admin_render()
{
    if (! current_user_can(LETS_ADMIN_CAPABILITY)) {
        return;
    }

    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();

    echo '<div class="wrap lets-admin">';
    echo '<h1>' . esc_html(lets_payplus_admin_text('האזור האישי של הלקוח', 'The customer personal area')) . '</h1>';

    if (null === lets_payplus_connection()) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html(lets_payplus_admin_text(
                'החנות עדיין לא מחוברת ל-LETS. חברו אותה כדי לערוך את האזור האישי.',
                'This store is not connected to LETS yet. Connect it to edit the personal area.'
            ))
            . ' <a href="' . esc_url(admin_url('options-general.php?page=lets-payplus')) . '">'
            . esc_html(lets_payplus_admin_text('למסך החיבור', 'Go to the connect screen'))
            . '</a></p></div></div>';

        return;
    }

    lets_payplus_admin_notice();

    $settings = lets_payplus_signed_get(LETS_ADMIN_ENDPOINT, array());

    if (is_wp_error($settings) || empty($settings['settings'])) {
        $message = is_wp_error($settings) ? $settings->get_error_message() : 'unexpected response';

        echo '<div class="notice notice-error"><p>'
            . esc_html(lets_payplus_admin_text('לא הצלחנו לקרוא את ההגדרות מ-LETS: ', 'Could not read the settings from LETS: '))
            . esc_html($message)
            . '</p></div></div>';

        return;
    }

    $s = $settings['settings'];
    $tab = lets_payplus_admin_current_tab();

    lets_payplus_admin_tabs($tab, $he);

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field(LETS_ADMIN_NONCE);
    echo '<input type="hidden" name="action" value="' . esc_attr(LETS_ADMIN_SAVE_ACTION) . '">';
    echo '<input type="hidden" name="lets_tab" value="' . esc_attr($tab) . '">';

    if ('appearance' === $tab) {
        lets_payplus_admin_appearance($s, $he);
    } elseif ('sections' === $tab) {
        lets_payplus_admin_sections($s, $he);
    } elseif ('banners' === $tab) {
        lets_payplus_admin_banners($s, $he);
    } else {
        lets_payplus_admin_copy($s, $he);
    }

    submit_button(lets_payplus_admin_text('שמירה', 'Save changes'));
    echo '</form>';
    echo '</div>';
}

function lets_payplus_admin_current_tab()
{
    $tabs = explode('|', LETS_ADMIN_TABS);
    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.

    return in_array($tab, $tabs, true) ? $tab : $tabs[0];
}

function lets_payplus_admin_tabs($current, $he)
{
    $labels = array(
        'appearance' => $he ? 'מראה' : 'Appearance',
        'sections' => $he ? 'אזורים' : 'Sections',
        'banners' => $he ? 'באנרים' : 'Banners',
        'copy' => $he ? 'טקסטים' : 'Copy',
    );

    echo '<h2 class="nav-tab-wrapper">';
    foreach ($labels as $slug => $label) {
        $url = admin_url('admin.php?page=' . LETS_ADMIN_SLUG . '&tab=' . $slug);
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url($url),
            $slug === $current ? ' nav-tab-active' : '',
            esc_html($label)
        );
    }
    echo '</h2>';
}

/** The outcome of the last save, carried back on the redirect. */
function lets_payplus_admin_notice()
{
    $status = isset($_GET['lets_status']) ? sanitize_key(wp_unslash($_GET['lets_status'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
    if ('' === $status) {
        return;
    }

    if ('saved' === $status) {
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html(lets_payplus_admin_text('נשמר. השינוי כבר חי אצל הלקוחות.', 'Saved. The change is already live for shoppers.'))
            . '</p></div>';

        return;
    }

    $detail = isset($_GET['lets_message']) ? sanitize_text_field(wp_unslash($_GET['lets_message'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

    echo '<div class="notice notice-error is-dismissible"><p>'
        . esc_html(lets_payplus_admin_text('השמירה נכשלה: ', 'Save failed: '))
        . esc_html('' !== $detail ? $detail : 'unknown error')
        . '</p></div>';
}

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------

function lets_payplus_admin_appearance($s, $he)
{
    $enums = array(
        'theme_mode' => array(
            'label' => $he ? 'ערכת צבעים' : 'Theme',
            'options' => $s['available_themes'],
            'labels' => array('light' => $he ? 'בהיר' : 'Light', 'dark' => $he ? 'כהה' : 'Dark', 'auto' => $he ? 'לפי המכשיר' : 'Follow the device'),
        ),
        'corner_radius' => array(
            'label' => $he ? 'פינות' : 'Corners',
            'options' => $s['available_radii'],
            'labels' => array('sharp' => $he ? 'חדות' : 'Sharp', 'soft' => $he ? 'רכות' : 'Soft', 'pill' => $he ? 'עגולות' : 'Pill'),
        ),
        'density' => array(
            'label' => $he ? 'צפיפות' : 'Density',
            'options' => $s['available_densities'],
            'labels' => array('comfortable' => $he ? 'מרווחת' : 'Comfortable', 'compact' => $he ? 'צפופה' : 'Compact'),
        ),
        'card_style' => array(
            'label' => $he ? 'סגנון כרטיס' : 'Card style',
            'options' => $s['available_card_styles'],
            'labels' => array('flat' => $he ? 'שטוח' : 'Flat', 'outlined' => $he ? 'ממוסגר' : 'Outlined', 'raised' => $he ? 'מוגבה' : 'Raised'),
        ),
        'font_family' => array(
            'label' => $he ? 'גופן' : 'Typeface',
            'options' => $s['available_fonts'],
            'labels' => array(
                'theme' => $he ? 'הגופן של האתר (מומלץ)' : 'The site’s own font (recommended)',
                'heebo' => 'Heebo',
                'system' => $he ? 'גופן המערכת' : 'System font',
            ),
            'hint' => $he
                ? 'ברירת המחדל לא מגדירה גופן בכלל — האזור יורש את הגופן של החנות, וזה מה שגורם לו להיראות חלק ממנה. בחרו אחרת רק אם הגופן של התבנית לא נושא עמוד של מספרים ותוויות.'
                : 'The default declares no font at all — the area inherits the shop’s, which is what makes it read as part of it. Choose otherwise only if the theme font cannot carry a page of numbers and labels.',
        ),
        'page_locale' => array(
            'label' => $he ? 'שפת העמוד' : 'Page language',
            'options' => $s['available_locales'],
            'labels' => array('auto' => $he ? 'לפי האתר' : 'Follow the site', 'he' => 'עברית', 'en' => 'English'),
        ),
    );

    echo '<table class="form-table" role="presentation"><tbody>';

    lets_payplus_admin_row(
        $he ? 'צבע המותג' : 'Brand colour',
        '<input type="color" name="accent_color" value="' . esc_attr($s['accent_color']) . '">',
        $he ? 'הצבע של הכפתורים הראשיים, כרטיס המועדון והקו העליון של מנוי פעיל.' : 'Primary buttons, the rewards card and the hairline on a live plan.'
    );

    lets_payplus_admin_row(
        $he ? 'צבע הטקסט על המותג' : 'Text on the brand colour',
        '<input type="color" name="accent_text_color" value="' . esc_attr($s['accent_text_color']) . '">',
        $he ? 'חייב להיות קריא על הצבע שלמעלה.' : 'Must stay readable on the colour above.'
    );

    foreach ($enums as $field => $meta) {
        $control = '<select name="' . esc_attr($field) . '">';
        foreach ($meta['options'] as $value) {
            $label = isset($meta['labels'][$value]) ? $meta['labels'][$value] : $value;
            $control .= '<option value="' . esc_attr($value) . '"' . selected($s[$field], $value, false) . '>'
                . esc_html($label) . '</option>';
        }
        $control .= '</select>';

        lets_payplus_admin_row($meta['label'], $control, isset($meta['hint']) ? $meta['hint'] : '');
    }

    echo '</tbody></table>';
}

function lets_payplus_admin_sections($s, $he)
{
    $labels = array(
        'welcome' => $he ? 'כותרת פתיחה' : 'Welcome heading',
        'subscriptions' => $he ? 'מנויים' : 'Subscriptions',
        'upcoming' => $he ? 'מה מחכה לך' : "What's next",
        'benefits' => $he ? 'ההטבות שלי' : 'Benefits',
        'loyalty' => $he ? 'מועדון הלקוחות' : 'Rewards',
        'orders' => $he ? 'הזמנות' : 'Orders',
        'documents' => $he ? 'חשבוניות וקבלות' : 'Invoices & receipts',
        'profile' => $he ? 'פרטי החשבון' : 'Account details',
        'addresses' => $he ? 'כתובות' : 'Addresses',
        'support' => $he ? 'תמיכה' : 'Support',
    );

    echo '<p class="description">' . esc_html($he
        ? 'כיבוי אזור מסתיר אותו מכל הלקוחות. הסדר כאן הוא הסדר בעמוד.'
        : 'Turning a section off hides it from every shopper. The order here is the order on the page.') . '</p>';

    echo '<table class="widefat striped" style="max-width:640px"><tbody>';

    foreach ($s['sections'] as $index => $section) {
        $key = $section['key'];
        $locked = in_array($key, $s['locked_sections'], true);
        $label = isset($labels[$key]) ? $labels[$key] : $key;

        echo '<tr>';
        echo '<td><input type="hidden" name="sections[' . (int) $index . '][key]" value="' . esc_attr($key) . '">'
            . esc_html($label) . '</td>';
        echo '<td style="width:160px">';

        if ($locked) {
            echo '<input type="hidden" name="sections[' . (int) $index . '][enabled]" value="1">'
                . '<span class="description">' . esc_html($he ? 'מוצג תמיד' : 'Always shown') . '</span>';
        } else {
            echo '<label><input type="checkbox" name="sections[' . (int) $index . '][enabled]" value="1"'
                . checked($section['enabled'], true, false) . '> '
                . esc_html($he ? 'מוצג' : 'Shown') . '</label>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table>';
}

function lets_payplus_admin_banners($s, $he)
{
    $slots = (int) $s['banner_slots'];
    $live = count($s['banners_live']);

    echo '<p class="description">' . esc_html($he
        ? 'עד ' . $slots . ' באנרים בטור הצדדי של האזור האישי. באנר חייב כותרת או תמונה כדי להופיע, וכתובות חייבות להיות https — כתובת http תימחק בשמירה.'
        : 'Up to ' . $slots . ' banners in the personal area side rail. A banner needs a heading or an image to appear, and URLs must be https — an http address is cleared on save.') . '</p>';

    echo '<p>' . esc_html(sprintf(
        $he ? 'כרגע מוצגים ללקוח: %d מתוך %d.' : 'Showing to shoppers right now: %d of %d.',
        $live,
        $slots
    )) . '</p>';

    for ($i = 0; $i < $slots; $i++) {
        $banner = isset($s['banners'][$i]) ? $s['banners'][$i] : array(
            'enabled' => false,
            'heading' => '',
            'subtext' => '',
            'image_url' => '',
            'link_url' => '',
        );

        echo '<h2>' . esc_html(sprintf($he ? 'באנר %d' : 'Banner %d', $i + 1)) . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        lets_payplus_admin_row(
            $he ? 'מוצג' : 'Shown',
            '<label><input type="checkbox" name="banners[' . $i . '][enabled]" value="1"'
                . checked(! empty($banner['enabled']), true, false) . '> '
                . esc_html($he ? 'הצג את הבאנר הזה' : 'Show this banner') . '</label>',
            ''
        );

        lets_payplus_admin_row(
            $he ? 'כותרת' : 'Heading',
            '<input type="text" class="regular-text" maxlength="80" name="banners[' . $i . '][heading]" value="'
                . esc_attr((string) $banner['heading']) . '">',
            ''
        );

        lets_payplus_admin_row(
            $he ? 'טקסט משנה' : 'Subtext',
            '<textarea class="large-text" rows="2" maxlength="300" name="banners[' . $i . '][subtext]">'
                . esc_textarea((string) $banner['subtext']) . '</textarea>',
            ''
        );

        lets_payplus_admin_row(
            $he ? 'כתובת תמונה' : 'Image URL',
            '<input type="url" class="large-text" dir="ltr" placeholder="https://" name="banners[' . $i . '][image_url]" value="'
                . esc_attr((string) $banner['image_url']) . '">',
            $he ? 'רוחב מלא, גובה חופשי. https בלבד.' : 'Full width, natural height. https only.'
        );

        lets_payplus_admin_row(
            $he ? 'קישור' : 'Link',
            '<input type="url" class="large-text" dir="ltr" placeholder="https://" name="banners[' . $i . '][link_url]" value="'
                . esc_attr((string) $banner['link_url']) . '">',
            $he ? 'באנר בלי קישור מוצג כטקסט ולא ככפתור.' : 'A banner with no link renders as text, not a button.'
        );

        echo '</tbody></table>';
    }
}

function lets_payplus_admin_copy($s, $he)
{
    echo '<p class="description">' . esc_html($he
        ? 'השאירו ריק כדי להשתמש בנוסח ברירת המחדל של LETS בשפת הלקוח.'
        : "Leave blank to use LETS's default wording in the shopper's language.") . '</p>';

    echo '<table class="form-table" role="presentation"><tbody>';

    lets_payplus_admin_row(
        $he ? 'כותרת הפתיחה' : 'Welcome heading',
        '<input type="text" class="regular-text" maxlength="120" name="welcome_heading" value="'
            . esc_attr((string) $s['welcome_heading']) . '">',
        $he ? 'שם הלקוח מצורף אחריה אוטומטית.' : "The shopper's name is appended automatically."
    );

    lets_payplus_admin_row(
        $he ? 'טקסט הפתיחה' : 'Welcome subtext',
        '<textarea class="large-text" rows="2" maxlength="500" name="welcome_subtext">'
            . esc_textarea((string) $s['welcome_subtext']) . '</textarea>',
        ''
    );

    lets_payplus_admin_row(
        $he ? 'מייל תמיכה' : 'Support email',
        '<input type="email" class="regular-text" dir="ltr" name="support_email" value="'
            . esc_attr((string) $s['support_email']) . '">',
        ''
    );

    lets_payplus_admin_row(
        $he ? 'קישור לתמיכה' : 'Support link',
        '<input type="url" class="regular-text" dir="ltr" placeholder="https://" name="support_url" value="'
            . esc_attr((string) $s['support_url']) . '">',
        $he ? 'https בלבד.' : 'https only.'
    );

    echo '</tbody></table>';
}

/**
 * One WordPress settings row. The control is already-escaped markup built by the
 * caller; the label and the hint are escaped here.
 */
function lets_payplus_admin_row($label, $control, $hint)
{
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td>'
        . $control // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by the caller.
        . ('' !== $hint ? '<p class="description">' . esc_html($hint) . '</p>' : '')
        . '</td></tr>';
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

add_action('admin_post_' . LETS_ADMIN_SAVE_ACTION, 'lets_payplus_admin_save');

function lets_payplus_admin_save()
{
    if (! current_user_can(LETS_ADMIN_CAPABILITY)) {
        wp_die(esc_html__('You are not allowed to do this.', 'lets-payplus'));
    }

    check_admin_referer(LETS_ADMIN_NONCE);

    $tab = isset($_POST['lets_tab']) ? sanitize_key(wp_unslash($_POST['lets_tab'])) : 'appearance';
    $body = lets_payplus_admin_body($tab);

    $result = lets_payplus_signed_post(LETS_ADMIN_ENDPOINT, $body);

    $args = array('page' => LETS_ADMIN_SLUG, 'tab' => $tab);

    if (is_wp_error($result)) {
        $args['lets_status'] = 'error';
        $args['lets_message'] = $result->get_error_message();
    } else {
        $args['lets_status'] = 'saved';
    }

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/**
 * Only the tab that was submitted is sent. The SaaS updates a field only when
 * the key is present, so a partial body cannot blank the tabs the merchant was
 * not looking at.
 *
 * @param string $tab
 * @return array
 */
function lets_payplus_admin_body($tab)
{
    if ('appearance' === $tab) {
        $fields = array('accent_color', 'accent_text_color', 'theme_mode', 'corner_radius', 'density', 'card_style', 'font_family', 'page_locale');
        $body = array();

        foreach ($fields as $field) {
            $body[$field] = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }

        return $body;
    }

    if ('sections' === $tab) {
        $rows = isset($_POST['sections']) && is_array($_POST['sections']) ? wp_unslash($_POST['sections']) : array();
        $sections = array();

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }

            $sections[] = array(
                'key' => sanitize_key($row['key']),
                'enabled' => ! empty($row['enabled']),
            );
        }

        return array('sections' => $sections);
    }

    if ('banners' === $tab) {
        $rows = isset($_POST['banners']) && is_array($_POST['banners']) ? wp_unslash($_POST['banners']) : array();
        $banners = array();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $banners[] = array(
                'enabled' => ! empty($row['enabled']),
                'heading' => isset($row['heading']) ? sanitize_text_field($row['heading']) : '',
                'subtext' => isset($row['subtext']) ? sanitize_textarea_field($row['subtext']) : '',
                'image_url' => isset($row['image_url']) ? esc_url_raw($row['image_url']) : '',
                'link_url' => isset($row['link_url']) ? esc_url_raw($row['link_url']) : '',
            );
        }

        return array('banners' => $banners);
    }

    return array(
        'welcome_heading' => isset($_POST['welcome_heading']) ? sanitize_text_field(wp_unslash($_POST['welcome_heading'])) : '',
        'welcome_subtext' => isset($_POST['welcome_subtext']) ? sanitize_textarea_field(wp_unslash($_POST['welcome_subtext'])) : '',
        'support_email' => isset($_POST['support_email']) ? sanitize_email(wp_unslash($_POST['support_email'])) : '',
        'support_url' => isset($_POST['support_url']) ? esc_url_raw(wp_unslash($_POST['support_url'])) : '',
    );
}
