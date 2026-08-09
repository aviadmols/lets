<?php

/*
 * The shopper's personal area (My Account).
 *
 * Every string here is resolved SERVER-SIDE in the shopper's language and shipped
 * inside the bootstrap payload, because the WordPress plugin carries no
 * translation catalogs of its own — one source of truth for the copy, and no
 * second place to forget when a string changes. The Hebrew mirror lives in
 * lang/he/account.php and must stay key-for-key identical.
 *
 * `ui.*`      — chrome the renderer always needs
 * `benefit.*` — one line per UpcomingBenefits::KIND_*
 * `result.*`  — what to say after a subscription action
 * `admin.*`   — the merchant's settings screen (NOT sent to shoppers)
 */

return [

    'ui' => [
        'welcome_heading' => 'My account',
        'welcome_subtext' => 'Your subscriptions, benefits and orders in one place.',
        'subscriptions_heading' => 'Subscriptions',
        'upcoming_heading' => "What's next",
        'benefits_heading' => 'Benefits',
        'loyalty_heading' => 'Rewards',
        'orders_heading' => 'Order history',
        'documents_heading' => 'Invoices & receipts',
        'profile_heading' => 'My details',
        'addresses_heading' => 'Addresses',
        'support_heading' => 'Need a hand?',

        'empty_subscriptions' => 'You have no active subscriptions yet.',
        'empty_upcoming' => 'Nothing scheduled right now.',

        'next_charge' => 'Next charge',
        'every' => 'Every',
        'status' => 'Status',
        'payment_method' => 'Payment method',
        'no_card' => 'No saved card',
        'paid_of' => 'paid of',
        'remaining' => 'Remaining',
        'payments_heading' => 'Payments',

        'action_pause' => 'Pause',
        'action_resume' => 'Resume',
        'action_cancel' => 'Cancel subscription',
        'action_skip' => 'Skip next delivery',
        'action_reschedule' => 'Change date',
        'action_items' => 'Edit products',
        'action_update_card' => 'Update card',
        'confirm_cancel' => 'Cancel this subscription? This cannot be undone.',

        'points_balance' => 'Points balance',
        'points_worth' => 'Worth',
        'tier' => 'Tier',

        'sign_in_prompt' => 'Sign in to see your subscriptions and rewards.',
        'sign_in_cta' => 'Sign in',

        'saved' => 'Saved',
        'failed' => 'That did not work. Please try again.',
        'loading' => 'Loading…',
    ],

    /*
     * Plan AND payment statuses share one bag: the renderer looks up
     * `status_{value}` without knowing which enum produced it, and a value with
     * no entry falls back to the raw string rather than rendering blank.
     */
    'status' => [
        'draft' => 'Draft',
        'awaiting_first_payment' => 'Awaiting first payment',
        'active' => 'Active',
        'paused' => 'Paused',
        'failed' => 'Payment failed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'pending' => 'Pending',
        'succeeded' => 'Paid',
        'retry_scheduled' => 'Retrying',
        'refunded' => 'Refunded',
    ],

    /* Benefits the shopper HAS right now — see AccountPresenter::activeBenefits. */
    'active' => [
        'intro_price' => 'You pay :now instead of :was',
        'intro_left' => ':count more orders at this price',
    ],

    'benefit' => [
        'next_delivery' => 'Next delivery',
        'next_order_extra' => 'Added to your next order',
        'intro_ending' => 'Your intro price ends — the price becomes',
        'plan_completes' => 'Final payment — then it is fully yours',
        'birthday_points' => 'Birthday points',
        'tier_progress' => 'Away from the next tier',
        'redeem_ready' => 'Ready to redeem',
    ],

    'result' => [
        'pause' => 'Subscription paused.',
        'resume' => 'Subscription resumed.',
        'cancel' => 'Subscription cancelled.',
        'skip' => 'Next delivery skipped.',
        'reschedule' => 'Date updated.',
        'items' => 'Next order updated.',
    ],

    'login' => [
        'heading' => 'Sign in with a code',
        'intro' => 'We will text or email you a one-time code.',
        'email_label' => 'Email address',
        'phone_label' => 'Mobile number',
        'code_label' => 'Verification code',
        'send' => 'Send code',
        'verify' => 'Sign in',
        'resend' => 'Send another code',
        'sent' => 'If that matches an account, a code is on its way.',
        'rejected' => 'That code is not right.',
        'expired' => 'That code has expired. Ask for a new one.',
        'exhausted' => 'Too many attempts. Ask for a new code.',
    ],

    'sms' => [
        'login_code' => 'Your code is :code. Valid for :minutes minutes.',
    ],

    'sample' => [
        'name' => 'Dana',
        'product' => 'Monthly coffee box',
    ],

    'admin' => [
        'title' => 'Customer area',
        'nav' => 'Customer area',
        'subheading' => 'What your customers see in their account, and how it looks.',

        'tab' => [
            'sections' => 'Sections',
            'appearance' => 'Appearance',
            'banners' => 'Side banners',
            'login' => 'Sign-in',
            'copy' => 'Wording',
        ],

        'sections' => [
            'help' => 'Drag to reorder. Turn a section off to hide it from every customer.',
            'locked' => 'Always shown — a customer must be able to reach their own subscription.',
            'label' => [
                'welcome' => 'Welcome header',
                'subscriptions' => 'Subscriptions',
                'upcoming' => "What's next (benefit timeline)",
                'benefits' => 'Benefits',
                'loyalty' => 'Rewards / loyalty club',
                'orders' => 'Order history',
                'documents' => 'Invoices & receipts',
                'profile' => 'My details',
                'addresses' => 'Addresses',
                'support' => 'Support',
            ],
        ],

        'appearance' => [
            'locale' => 'Language',
            'locale_help' => 'The language your customers read the area in — including the sign-in codes you send them.',
            'locale_option' => [
                'auto' => 'Follow the store',
                'he' => 'Hebrew',
                'en' => 'English',
            ],
            'accent' => 'Accent colour',
            'accent_text' => 'Text on accent',
            'theme' => 'Theme',
            'radius' => 'Corners',
            'density' => 'Spacing',
            'card' => 'Card style',
            'font_note' => 'Typography is inherited from your storefront theme, so the area always matches your shop.',
            'theme_option' => ['light' => 'Light', 'dark' => 'Dark', 'auto' => "Follow the shopper's device"],
            'radius_option' => ['sharp' => 'Square', 'soft' => 'Rounded', 'pill' => 'Pill'],
            'density_option' => ['compact' => 'Compact', 'comfortable' => 'Comfortable'],
            'card_option' => ['flat' => 'Flat', 'outlined' => 'Outlined', 'raised' => 'Raised'],
        ],

        'banners' => [
            'help' => 'Up to three promos in the side column. A banner needs a heading or an image to show at all.',
            'heading' => 'Heading',
            'subtext' => 'Subtext',
            'image_url' => 'Image URL',
            'link_url' => 'Links to',
            'enabled' => 'Show',
            'https_only' => 'Must start with https://',
        ],

        'login' => [
            'enabled' => 'Let customers sign in with a one-time code',
            'enabled_help' => 'Adds a passwordless option to your account page. Customers can still use their password.',
            'channel' => 'Send the code by',
            'channel_option' => ['email' => 'Email', 'sms' => 'SMS', 'both' => 'Email and SMS'],
            'sms_heading' => 'SMS account (019)',
            'sms_help' => 'Codes are sent from your own 019 account, so they carry your sender name. Without it, only email works.',
            'sms_enabled' => 'Send by SMS',
            'sms_username' => '019 username',
            'sms_token' => '019 API token',
            'sms_sender' => 'Sender name',
            'sms_sender_help' => 'Up to 11 letters or digits, no spaces — this is what appears as the sender.',
        ],

        'copy' => [
            'welcome_heading' => 'Welcome heading',
            'welcome_subtext' => 'Welcome subtext',
            'support_email' => 'Support email',
            'support_url' => 'Support page',
            'blank_help' => 'Leave blank to use the default wording.',
        ],

        'preview' => [
            'heading' => 'Preview',
            'help' => 'Exactly what your customers see — same stylesheet, same renderer.',
        ],

        'saved' => 'Customer area saved.',
    ],

];
