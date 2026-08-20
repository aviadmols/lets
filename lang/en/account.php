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
        'receipt_label' => 'Receipt',

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

        /*
         * Account offers. `offer_from` is deliberately date-less: the presenter
         * puts the resolved day in the payload's `first_charge_at` and the
         * renderer composes the sentence, so one string serves every offer.
         */
        // Why the pause/cancel buttons are not there yet, when the plan was sold
        // with a minimum term. Never shown to a subscriber who is free to leave.
        'commitment_note' => 'You can pause or cancel after :required payments — :paid so far.',
        'offer_accept' => 'Choose this plan',
        'offer_from' => 'from',
        'offer_replaces' => 'Replaces your current subscription',
        'offer_price_label' => 'Price',
        'offer_unavailable' => 'This offer is no longer available.',

        /*
         * A one-time target sells a plain product to somebody who already
         * subscribes. `offer_buy_now` is its button — "Choose this plan" would be
         * a lie about a mug — and `offer_add_to_next` labels the date a
         * ride-along add-on arrives, which the renderer follows with
         * `next_order_at` from the payload.
         */
        'offer_buy_now' => 'Buy now',
        'offer_one_time' => 'One-time purchase',
        'offer_add_to_next' => 'Added to your next order on',

        /*
         * The disclosure a shopper reads BEFORE their saved card is used — the
         * amount, when it is taken, and what happens to what they already have.
         * The `_replace` variants add that second sentence; an `add` offer ends
         * nothing and must not claim to.
         *
         * The two one-time sentences are the same promise for a plain product.
         * `next_order` says in as many words that nothing is charged today: a
         * shopper who believes they have just paid will go looking for the charge.
         */
        'offer_disclosure_now' => ':amount will be charged to your saved card now.',
        'offer_disclosure_now_replace' => ':amount will be charged to your saved card now. Your current subscription ends.',
        'offer_disclosure_later' => ':amount will be charged to your saved card on :date.',
        'offer_disclosure_later_replace' => ':amount will be charged to your saved card on :date. Your current subscription ends now, and nothing is charged today.',
        'offer_disclosure_buy_now' => ':amount will be charged to your saved card now, and this will be sent to you as a separate order.',
        'offer_disclosure_next_order' => 'This will be added to your next order on :date, and :amount will be charged with it. Nothing is charged today.',
    ],

    /*
     * Shown in the STORE, not the account page: the shop allows one subscription
     * per customer and this shopper already has one. The plugin turns the link
     * label into a link to their own area — it knows the URL, we do not.
     */
    'purchase' => [
        'blocked' => 'You already have an active subscription, so a second one cannot be added. You can change or cancel the one you have in your account area.',
        'blocked_link' => 'Go to my subscription',
    ],

    /*
     * How often a subscription bills, as a sentence. The unit is a CHOICE string
     * (singular|plural) because Hebrew must agree with the count — "כל חודש" but
     * "כל 3 חודשים" — which a flat frequency→word map cannot express.
     */
    'cycle' => [
        'every' => 'every :unit',
        'every_n' => 'every :count :unit',

        'unit' => [
            'daily' => 'day|days',
            'weekly' => 'week|weeks',
            'biweekly' => '2 weeks|2 weeks',
            'monthly' => 'month|months',
            'quarterly' => 'quarter|quarters',
            'yearly' => 'year|years',
        ],
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

        /*
         * accept_offer answers with more than ok/failed, because "your card was
         * declined" and "you have already taken this" are not the same news and
         * a shopper told the wrong one will call support.
         */
        'accept_offer' => 'Done — your new plan is set up.',
        'accept_offer_unavailable' => 'This offer is not available right now. Nothing was charged.',
        'accept_offer_charge_failed' => 'Your card was declined, so the change was not made. Your current subscription is unchanged.',
        'accept_offer_not_eligible' => 'This offer no longer applies to your subscription.',
        'accept_offer_changed' => 'Something changed while you were looking. Please refresh and try again — nothing was charged.',
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
                'downloads' => 'Downloads (WooCommerce tab)',
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
            'help' => 'Up to three promos. Choose where each one sits and who sees it. A banner needs a heading or an image to show at all.',
            'heading' => 'Heading',
            'subtext' => 'Subtext',
            'image_url' => 'Image URL',
            'link_url' => 'Links to',
            'enabled' => 'Show',
            'https_only' => 'Must start with https://',
            'placement' => 'Placement',
            'placement_option' => [
                'rail' => 'Side column',
                'top' => 'Top of the page',
            ],
            'audience' => 'Who sees it',
            'audience_option' => [
                'everyone' => 'Everyone',
                'subscribers' => 'Subscribers',
                'non_subscribers' => 'Non-subscribers',
            ],
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
            'sms_incomplete' => 'SMS sign-in is not ready yet',
            'sms_incomplete_help' => 'You chose to send codes by SMS, but the 019 account is not complete. Until the username, token and sender name are all filled in, a customer who asks for a code by SMS will never receive one.',
            'google_client_id' => 'Google sign-in — OAuth client ID',
            'google_client_id_help' => 'Paste the client ID from your own Google Cloud project to show a "Sign in with Google" button. Your store\'s address must be listed under the OAuth client\'s authorized JavaScript origins. Leave blank to hide the button.',
            'google_client_id_invalid' => 'This does not look like a Google OAuth client ID (it ends with .apps.googleusercontent.com).',
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
