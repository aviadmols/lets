<?php

// Subscriptions list + detail (docs/ux/30-subscriptions.md). Mirror in lang/he/subscriptions.php.
return [
    'list' => [
        'title' => 'Subscriptions',
        'search_placeholder' => 'Search customer',
        'col' => [
            'customer' => 'Customer',
            'product' => 'Product',
            'kind' => 'Kind',
            'status' => 'Status',
            'next_charge' => 'Next charge',
            'amount_balance' => 'Amount / Balance',
        ],
        'empty' => [
            'first_run' => 'No subscriptions yet. Create a plan on a product or take a deposit checkout to start.',
        ],
    ],

    'tab' => [
        'all' => 'All',
        'upcoming' => 'Upcoming',
        'failed' => 'Failed',
        'failing' => 'Could not charge',
        'paused' => 'Paused',
        'cancelled' => 'Cancelled',
    ],

    'filter' => [
        'kind' => [
            'all' => 'All',
            'installments' => 'Installments',
            'recurring' => 'Recurring',
        ],

        'charge_date' => 'Next charge date',
        'charge_from' => 'Charging from',
        'charge_until' => 'Charging until',
        'charge_on' => 'Charging on :date',
        'charge_between' => 'Charging :from → :until',

        'frequency' => 'Billing frequency',
        'product' => 'Product',

        'no_charge' => 'Payment',
        'no_charge_all' => 'All subscriptions',
        'no_charge_only' => 'Free — never billed',
        'no_charge_excluded' => 'Billed subscriptions only',

        'balance' => 'Remaining balance',
        'balance_min' => 'From amount',
        'balance_max' => 'Up to amount',
        'balance_between' => 'Balance :min–:max',

        'created' => 'Created date',
        'created_from' => 'Created from',
        'created_until' => 'Created until',
        'created_between' => 'Created :from → :until',

        'last_payment' => 'Last payment',
    ],

    // How often a recurring plan bills, as a phrase. The single-unit labels
    // ("Monthly") live in billing.settings.frequency; these are the plural nouns
    // for "every N …".
    'cadence' => [
        'every_n' => 'Every :n :unit',
        'plural' => [
            'daily' => 'days',
            'weekly' => 'weeks',
            'biweekly' => 'fortnights',
            'monthly' => 'months',
            'quarterly' => 'quarters',
            'yearly' => 'years',
        ],
    ],

    'detail' => [
        'missing' => 'That subscription no longer exists, or it belongs to another store.',
        'customer' => 'Customer',
        // Beside the status badge, and in the list filter. Deliberately not
        // "free": what it promises is that nobody is ever billed for it.
        'no_charge' => 'No payment',
        'remaining_of_total' => 'Remaining :balance of :total',
        'every_frequency' => 'Every :frequency',
        'plan_items' => 'Plan items',
        'billing_schedule' => 'Billing schedule',
        'deposit' => 'Deposit',
        'installment_n' => 'Installment :n',
        'n_of_m' => ':n of :m',
        'fulfillment_locked' => 'Fulfillment locked until fully paid',
        'order_released' => 'Order released for fulfillment',
        'payment_schedule' => 'Payment schedule',
        'schedule_empty' => 'No scheduled payments yet.',
        'payment_ledger' => 'Payment ledger',
        'ledger_empty' => 'No charges yet.',
        'timeline' => 'Timeline',
        'preview_email' => 'Preview email',
        'preview_unavailable' => 'This email preview is no longer available.',
        'next_cycle' => 'Next cycle',
        'started' => 'Started',
        'expires' => 'Expires',
        'no_expiry' => 'No end date — renews until cancelled',
        'checkout_order' => 'Checkout order',
        'coupon_applied' => 'Coupon applied',
        'intro_window' => 'Intro discount',
        'intro_window_status' => ':used of :total discounted charges used',
        'intro_window_ended' => 'Ended — billing the regular price',
        'next_order' => 'Next order',
        'next_order_customised' => 'Customised for the next charge',
        'cycle_orders' => 'Past cycle orders',
        'recurring_line' => 'Subscription',
        'total' => 'Total',
        'col' => [
            'date' => 'Date',
            'context' => 'Context',
            'amount' => 'Amount',
            'status' => 'Status',
            'tx' => 'Transaction',
            'sequence' => 'Item',
            'scheduled_for' => 'Scheduled for',
            'attempts' => 'Attempts',
            'charged_at' => 'Charged at',
            'note' => 'Note',
            'product' => 'Product',
            'qty' => 'Qty',
            'document' => 'Invoice',
        ],
        'contact' => [
            'title' => 'Contact details',
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'national_id' => 'National ID',
            'address' => 'Address',
            'street' => 'Street',
            'building' => 'Building no.',
            'apartment' => 'Apartment',
            'apartment_short' => 'Apt. :number',
            'city' => 'City',
            'zip' => 'Zip code',
            'country' => 'Country',
        ],
        'note' => [
            'paid' => 'Paid',
            'refunded' => 'Refunded',
            'attempt_error' => 'Attempt :attempt — :error',
            'attempt_failed' => 'Attempt :attempt failed',
            'retry_on' => 'Retry scheduled for :date',
            'retry_pending' => 'Retry scheduled',
            'awaiting_customer' => 'Awaiting customer',
            'scheduled' => 'Scheduled',
        ],
    ],

    // Lifecycle actions (Pause / Resume / Cancel) on the subscription detail page.
    'action' => [
        'failed' => 'Action failed. No change was made.',

        /* Adding a subscriber by hand. The copy's whole job is to be honest
           about what this does NOT do: it takes no card and charges nobody,
           now or later, and a merchant who expects otherwise will find out on
           the day a renewal does not happen. */
        'create' => [
            'label' => 'New subscription',
            'heading' => 'Add a subscription — without payment',
            'body' => 'For a member who pays nothing here: a comped or staff subscription, a gift, or someone whose money you collect elsewhere. No card is attached and no charge is ever scheduled — not now, and not on a later cycle. To take money from this person they have to subscribe and give their card the normal way.',
            'save' => 'Create subscription',
            'success' => 'Subscription created.',
            'success_body' => 'Nothing was charged and nothing is scheduled.',

            'section' => [
                'customer' => 'Who it is for',
                'plan' => 'What they get',
            ],

            'field' => [
                'name' => 'Customer name',
                'email' => 'Email',
                'email_help' => 'How this subscription reaches them — their customer page, their account area and any mail you send. If you already have subscriptions under this address, the new one joins that customer.',
                'phone' => 'Phone',
                'product' => 'Product',
                'product_help' => 'Optional. Pick one if this subscription maps onto something in your catalog; leave it empty for a membership that does not.',
                'title' => 'Subscription name',
                'title_help' => 'What the customer sees this called.',
                'frequency' => 'Billing frequency',
                'interval' => 'Every',
                'interval_help' => '“Every 2” with “Monthly” means once every two months.',
                'amount' => 'Amount per cycle (:currency)',
                'amount_help' => 'What the subscription is worth on paper. Leave it at 0 for a free member. Whatever you put here is never charged — this subscription has no card and no charge date.',
                'status' => 'Status',
                'note' => 'Note',
                'note_help' => 'Why this subscription is free. Kept with the subscription for whoever opens it next.',
            ],

            'status_help' => [
                'active' => 'A live subscriber who owes nothing. Counts as active everywhere.',
                'draft' => 'Recorded, but not started yet.',
            ],
        ],

        'pause' => [
            'label' => 'Pause',
            'heading' => 'Pause this subscription?',
            'body' => 'No further charges run until you resume it.',
            'success' => 'Subscription paused.',
        ],
        'resume' => [
            'label' => 'Resume',
            'heading' => 'Resume this subscription?',
            'body' => 'The next charge is scheduled again.',
            'success' => 'Subscription resumed.',
        ],
        'cancel' => [
            'label' => 'Cancel',
            'heading' => 'Cancel this subscription?',
            'body' => 'The customer will not be charged again. This cannot be undone.',
            'reason' => 'Reason (optional)',
            'success' => 'Subscription cancelled.',
        ],
        /*
        | A member migrated from another system charges against the token that
        | system exported. When PayPlus does not recognise it, the card is
        | usually still there under a uid we were never given — so we ask PayPlus
        | rather than sending the customer back to a payment page.
        */
        /*
        | Pick the card, when the rules would not. The automatic replacement
        | refuses whenever the choice is not forced; this is how the person who
        | can see which card is current gets to say so.
        */
        'choose_card' => [
            'label' => 'Choose a saved card',
            'heading' => 'Which of these cards should we bill?',
            'body' => 'PayPlus holds more than one live card for this member, and we would not pick between them on our own. These are their own saved cards, newest first where PayPlus told us when each was added. The one you choose is billed from the next charge onwards.',
            'field' => 'Saved cards at PayPlus',
            'submit' => 'Use this card',
            'expires' => 'expires :date',
            'added' => 'added :date',
            'attached' => 'Card attached. Charge the subscription to test it.',
            'refused' => 'That card is not one of this member\x27s saved cards. Nothing was changed.',
        ],

        'activate_now' => [
            'label' => 'Activate now',
            'heading' => 'Start this subscription now?',
            'body' => 'It becomes active today and its next charge is set one cycle from today — exactly as if the customer had pressed their activation link. Nothing is charged now.',
            'done' => 'Subscription activated. Next charge: :date',
        ],

        'activation_link' => [
            'label' => 'Activation link',
            'heading' => 'The customer\'s activation link',
            'body' => 'The subscription starts when this link is confirmed, with the next charge one cycle from that day. It does not expire.',
            'url' => 'Link',
            'send' => 'Email it to :email',
            'close' => 'Close',
            'sent' => 'Activation link emailed to :email',
            'not_sent' => 'The email was not sent — check that the customer has an address and that this email is switched on.',
        ],

        'revoke_activation_link' => [
            'label' => 'Revoke activation link',
            'heading' => 'Revoke every activation link sent so far?',
            'body' => 'Links already sent stop working. A new link is created the next time you open or send it.',
            'done' => 'Activation links revoked',
        ],

        'recover_token' => [
            'label' => 'Find saved card',
            'heading' => 'Look up this member\'s card at PayPlus?',
            'body' => 'This subscription was migrated and its saved card is not being recognised. PayPlus will be asked what card it holds for this member, and if it finds one it will be saved here so you can charge it. Nothing is charged by this button.',
            'submit' => 'Ask PayPlus',
            'recovered' => 'Card found and saved. You can charge this subscription now.',
            'already_valid' => 'The saved card is valid at PayPlus, so the charge is failing for another reason — most likely the card was saved on a different PayPlus terminal.',
            'not_found' => 'PayPlus holds no card we can safely attach to this member.',
            'ambiguous' => 'PayPlus knows this customer but holds more than one card and we cannot tell which is theirs. Ask them to update their card instead.',
            'no_last_four' => 'We hold no last-4 digits for this member, so a card at PayPlus cannot be matched to them safely.',
            'not_connected' => 'This store has no PayPlus connection configured.',
            'recurring_live' => 'Warning: PayPlus is still billing this member on its own schedule. Cancel it there before charging, or they will be billed twice.',
        ],

        'charge_now' => [
            'label' => 'Charge now',
            'heading' => 'Charge this subscription now?',
            'body' => 'The saved card will be charged :amount immediately.',
            'success' => 'Charge succeeded.',
            'failed' => 'Charge failed.',
            'failed_retry' => 'Charge failed — a retry is scheduled.',
            'skipped' => 'Nothing to charge right now (already paid or awaiting consent).',
            // Already charged in the last 24 hours — another charge only on explicit approval.
            'repeat_heading' => 'This subscription was already charged in the last 24 hours',
            'repeat_body' => 'It was charged :last on :when. Charging now takes :amount from the customer again — usually for the next cycle, in advance. Approve only if that is really what you mean.',
            'repeat_body_in_flight' => 'A charge of :last was sent for this subscription on :when and its outcome is not known yet. Charging again now may charge the customer twice. Check PayPlus before you approve.',
            'repeat_confirm' => 'I approve charging this subscription again today',
            'repeat_blocked' => 'Nothing was charged: this subscription was already charged in the last 24 hours. To charge again, open the subscription and approve a second charge explicitly.',
        ],

        /*
        | A charge we asked for and never got an answer to. We will not ask again
        | on our own — the card may already have been charged — so this is where
        | a person who has looked at PayPlus tells us which way it went.
        */
        'reconcile' => [
            'label' => 'Unfinished charge',
            'heading' => 'We lost track of one charge',
            'body' => 'We asked PayPlus for :amount on :when and never got an answer — the worker stopped mid-charge. The card may or may not have been charged, so nothing further has been attempted on this cycle. Open this customer in PayPlus, then tell us what you find.',
            'question' => 'What does PayPlus show?',
            'did_not' => 'No such charge — the money never moved',
            'took' => 'The charge is there — the money moved',
            'uid' => 'Transaction ID (optional)',
            'uid_help' => 'From the PayPlus transaction, if you have it. It is stored with the payment for later reference.',
            'done' => 'Recorded. The subscription can move again.',
            'failed' => 'That charge is no longer waiting to be resolved.',
        ],
        'frequency' => [
            'label' => 'Change frequency',
            'heading' => 'How often does this bill?',
            'body' => 'Applies from the cycle AFTER the next charge. The next charge date does not move — use "Edit next charge" for that.',
            'every' => 'Every',
            'unit' => 'Unit',
            'unit_months' => 'months',
            'unit_years' => 'years',
            'save' => 'Save frequency',
            'success' => 'Billing frequency updated.',
        ],
        'export' => [
            'label' => 'Export these',
        ],

        'note' => [
            'label' => 'Add note',
            'heading' => 'Add a note to the timeline',
            'field' => 'Note',
            'plan' => 'Subscription',
            'save' => 'Add note',
            'success' => 'Note added to the timeline.',
        ],

        'contact' => [
            'label' => 'Edit contact details',
            'heading' => 'Edit contact details',
            'body' => 'Updates what this subscription knows about the customer. The store\'s own customer record is not changed.',
            'save' => 'Save details',
            'success' => 'Contact details updated.',
        ],

        'edit_next' => [
            'label' => 'Edit next charge',
            'heading' => 'Edit the next charge',
            'body' => 'Change the date and the products on the NEXT charge only. Later cycles keep the plan’s normal contents.',
            'date' => 'Next charge date',
            'items' => 'Products on the next order',
            'product' => 'Product',
            'qty' => 'Qty',
            'price' => 'Unit price',
            'add_product' => 'Add a product',
            'save' => 'Save changes',
            'success' => 'The next charge was updated.',
        ],
    ],

    /*
    | BULK EDIT — change many subscriptions at once (app/Domain/Bulk).
    |
    | The copy carries the screen's whole safety argument, so it is written to be
    | read by somebody about to change four thousand people's billing: every count
    | is named, every refusal says which rule refused and what to do instead, and
    | the two changes that can charge a card immediately say so in words before
    | they can be confirmed.
    */
    'bulk' => [
        'nav' => 'Bulk edit',
        'title' => 'Bulk edit subscriptions',
        'intro' => 'Change many subscriptions at once — pick who, pick what changes, then check the count before it runs.',
        'back_to_list' => 'Back to subscriptions',

        'step' => [
            'target' => '1. Which subscriptions',
            'target_help' => 'Every filter you set narrows the group. Leave a filter empty to ignore it.',
            'change' => '2. What changes',
            'change_label' => 'Change',
            'confirm' => '3. Check and run',
        ],

        'criteria' => [
            'interval' => 'Every (interval)',
            'interval_help' => 'Use with a frequency — “every 3” plus “monthly” finds the quarterly ones.',
            'status_help' => 'Tick none to include every status.',
            'no_next_charge' => 'Only subscriptions with no next charge date',
            'no_next_charge_help' => 'Their clock is stopped. Choosing this ignores the charge-date range above.',
        ],

        'unit' => [
            'days' => 'days',
            'weeks' => 'weeks',
            'months' => 'months',
            'years' => 'years',
        ],

        'op' => [
            'set_date' => [
                'label' => 'Set the next charge date',
                'help' => 'Every matched subscription moves to the same date. Later cycles follow from there, on each subscription’s own frequency.',
                'summary' => 'Set the next charge date to :date',
            ],
            'shift' => [
                'label' => 'Move the next charge date',
                'help' => 'Each subscription keeps its own date and moves by the same amount — so a group stays spread out instead of landing on one day.',
                'amount' => 'Move by',
                'amount_help' => 'A negative number moves charges earlier.',
                'summary_forward' => 'Move the next charge :amount :unit later',
                'summary_back' => 'Move the next charge :amount :unit earlier',
            ],
            'frequency' => [
                'label' => 'Change the billing frequency',
                'help' => 'Recurring subscriptions only. Instalment plans bill a fixed schedule until they are paid off.',
                'summary' => 'Bill every :amount :unit',
                'keeps_date' => 'The next charge date does not move. The new frequency applies from the cycle after it — use “Set the next charge date” to move the date itself.',
            ],
            'pause' => [
                'label' => 'Pause',
                'help' => 'Stops charging until you resume. Nothing is cancelled and nothing is refunded.',
                'summary' => 'Pause these subscriptions',
            ],
            'resume' => [
                'label' => 'Resume',
                'help' => 'Puts paused subscriptions back on schedule. One charge each, never a backlog of the cycles they missed.',
                'summary' => 'Resume these subscriptions',
            ],
            'lifecycle_note' => 'Each subscription moves through the normal state machine, one at a time, and gets its own timeline entry. Subscriptions that are no longer in the right state are skipped.',
        ],

        'due_now' => [
            'title' => 'This will make these subscriptions due immediately',
            'body' => 'The date you have chosen is today or earlier, so the scheduler will charge every matched card on its next run — within the hour.',
            'ack' => 'I understand these cards will be charged as soon as the scheduler runs',
        ],

        'action' => [
            'preview' => 'Count and preview',
            'counting' => 'Counting…',
            'apply' => 'Change :count subscriptions',
            'queueing' => 'Starting…',
            'stop' => 'Stop this run',
            'stop_help' => 'Changes already made stay. Nothing further is touched.',
        ],

        'preview' => [
            'empty' => 'Set your filters and the change, then press “Count and preview”. Nothing is changed until you confirm.',
            'matched' => 'Match your filters',
            'eligible' => 'Will be changed',
            'ineligible' => 'Cannot be changed',
            'ineligible_help' => ':count of them cannot take this change — cancelled or completed subscriptions, or the wrong plan kind for it. They are left alone.',
            'sample' => 'First :count, before and after',
            'before' => 'Now',
            'after' => 'After',
            'unfiltered' => [
                'title' => 'No filters are set',
                'body' => 'This will change every subscription in the store that can take the change.',
            ],
        ],

        'confirm' => [
            'label' => 'Type :count to confirm',
            'help' => 'This is a large change, so the number has to be typed.',
        ],

        'queued' => 'Started. :count subscriptions are being changed in the background.',
        'stopped' => 'Run stopped. Changes already made stay.',

        'run' => [
            'title' => 'Run #:id',
            'queued' => 'Waiting for a worker to pick it up…',
            'changed' => 'Changed',
            'skipped' => 'Skipped',
            'failed' => 'Failed',
            'skipped_help' => 'Skipped means nothing needed doing — already on that value, or no longer eligible when the run reached it.',
            'errored' => 'The run stopped on an error',
        ],

        'status' => [
            'queued' => 'Queued',
            'running' => 'Running',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Stopped',
        ],

        'history' => [
            'title' => 'Recent bulk edits',
            'when' => 'When',
            'what' => 'What was asked for',
        ],

        'error' => [
            'no_shop' => 'No store is selected, so there is nothing to edit.',
            'preview_first' => 'Count and preview the change first — the confirmation has to match what you were shown.',
            'confirm_count' => 'The number does not match the count shown. Check the preview and type it again.',
            'nothing_matched' => 'No subscription matches those filters that can take this change. Nothing was run.',
            'invalid_params' => 'That change cannot be applied as set.',
            'date_required' => 'Choose the date the next charge should move to.',
            'date_unreadable' => 'That date could not be read.',
            'date_due_now' => 'That date is today or earlier, which would charge every matched card straight away. Tick the acknowledgement if that is what you mean.',
            'unit_unknown' => 'Choose days, weeks or months.',
            'amount_required' => 'Enter how far to move the charge date.',
            'amount_zero' => 'Moving by zero would change nothing.',
            'amount_too_large' => 'That is further than a bulk move allows. Use a smaller amount, or set the date directly.',
            'shift_backwards' => 'Moving charges earlier can make them due immediately. Tick the acknowledgement if that is what you mean.',
            'frequency_unknown' => 'Choose months or years.',
            'interval_required' => 'Enter how many months or years between charges.',
            'interval_range' => 'The interval has to be between 1 and 12.',
            'operation_unknown' => 'That change is not one this app knows how to make.',
        ],
    ],
];
