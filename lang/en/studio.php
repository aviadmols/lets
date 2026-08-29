<?php

// The newsletter studio — block editor + chat. Mirror of lang/he/studio.php —
// every key must exist in both files.
return [

    'title' => 'Newsletter studio',

    // Block names, in the palette and in the chat's diff lines.
    'block' => [
        'hero' => 'Hero (image + heading + button)',
        'heading' => 'Heading',
        'text' => 'Text',
        'image' => 'Image',
        'button' => 'Button',
        'coupon' => 'Coupon',
        'divider' => 'Divider',
        'spacer' => 'Spacer',
        'social_links' => 'Social links',
        'footer' => 'Legal footer',
    ],

    'network' => [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'whatsapp' => 'WhatsApp',
        'x' => 'X',
        'linkedin' => 'LinkedIn',
        'website' => 'Our website',
    ],

    // Variable names in the insert menu.
    'variable' => [
        'customer_name' => "Customer's name",
        'customer_email' => "Customer's email",
        'business_name' => 'Business name',
        'account_login_url' => 'Sign-in link to the personal area',
        'unsubscribe_url' => 'Unsubscribe link',
    ],

    // The document a fresh studio campaign opens on.
    'starter' => [
        'heading' => 'We have something for you',
        'text' => 'Write your story here — or ask the AI to draft it.',
        'button' => 'To my account',
    ],

    'footer' => [
        'unsubscribe' => 'Unsubscribe from this list',
    ],

    // --- The studio screen ---
    'missing' => 'Campaign not found.',
    'stale' => 'The document changed since this screen loaded — refreshed. Check and try again.',
    'not_editable' => 'This campaign has been sent or is sending — it can no longer be edited.',
    'restored' => 'Version restored.',
    'version_line' => 'Version :version',

    'refused' => [
        'too_large' => 'The email is too large — shorten texts or remove blocks.',
        'not_found' => 'Version not found.',
    ],

    'panel' => [
        'blocks' => 'Blocks',
        'empty' => 'No blocks yet — add one from the list below.',
        'settings' => 'Email settings (subject, colors, direction)',
        'versions' => 'Version history',
        'warnings' => 'Worth fixing',
    ],

    'verb' => [
        'up' => 'Up',
        'down' => 'Down',
        'duplicate' => 'Duplicate',
        'remove' => 'Remove',
        'remove_confirm' => 'Remove this block?',
    ],

    'action' => [
        'save_block' => 'Save block',
        'save_settings' => 'Save settings',
        'close' => 'Close',
        'restore' => 'Restore',
        'open_studio' => 'Open in studio',
    ],

    'settings' => [
        'subject' => 'Subject',
        'preheader' => 'Preview line',
        'preheader_hint' => 'The line inboxes show after the subject, before opening.',
        'direction' => 'Direction',
        'rtl' => 'Hebrew (right to left)',
        'ltr' => 'English (left to right)',
        'background_color' => 'Page background',
        'content_background' => 'Content background',
        'text_color' => 'Text color',
        'link_color' => 'Link color',
        'button_color' => 'Button color',
        'button_text_color' => 'Button text',
    ],

    'field' => [
        'text' => 'Text',
        'text_hint' => 'Basic formatting allowed: <b>, <i>, <a>, lists. Variables like {customer_name} work.',
        'level' => 'Size',
        'level_1' => 'Main heading',
        'level_2' => 'Subheading',
        'image_url' => 'Image URL (https)',
        'alt' => 'Alt text',
        'link_url' => 'Link on click (optional)',
        'label' => 'Button text',
        'url' => 'Where the button goes',
        'url_hint' => 'An https address, or a variable: {account_login_url} for the personal area.',
        'heading' => 'Heading',
        'code' => 'Coupon code',
        'description' => 'Description',
        'height' => 'Height (px)',
        'color' => 'Color',
        'business_line' => 'Business line',
        'address_line' => 'Business address',
        'note' => 'Note',
        'footer_hint' => 'The unsubscribe link always shows — it cannot (and must not) be removed.',
        'social_hint' => 'Leave a network blank to skip it.',
        'align' => 'Alignment',
        'align_default' => 'Default',
        'align_start' => 'Line start',
        'align_center' => 'Center',
        'align_end' => 'Line end',
    ],

    'cause' => [
        'manual' => 'Edit',
        'ai_patch' => 'AI change',
        'restore' => 'Restore',
        'init' => 'Created',
    ],

    'warning' => [
        'unknown_token' => 'Unknown variable: {:detail} — it will not be substituted on send.',
        'image_without_alt' => 'An image without alt text.',
        'button_without_url' => 'Button ":detail" has no link.',
        'no_footer' => 'No footer — a marketing email must carry an unsubscribe link.',
    ],
];
