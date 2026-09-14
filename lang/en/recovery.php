<?php

/*
| FAILED CHARGES (app/Filament/Pages/PaymentRecovery.php). Mirror in lang/he.
|
| The copy carries the one distinction the whole screen exists to make: a
| subscription whose attempts ran out is HELD, not cancelled. It keeps the debt on
| its original date and comes back the moment the card is fixed — and a merchant
| who reads "cancelled" stops chasing a customer they could still keep.
*/
return [
    'nav' => 'Failed charges',
    'title' => 'Failed charges',

    'tab' => [
        'retrying' => 'Trying again',
        'stopped' => 'Stopped — card',
        'cancelled' => 'Cancelled',
    ],

    'intro' => [
        'retrying' => 'The card was declined and we are still asking. Each one shows when the next attempt runs.',
        'stopped' => 'The attempts ran out, so we stopped asking. These subscriptions are ON HOLD, not cancelled: each still owes its cycle on the original date, and the moment the customer updates their card it is charged and carries on.',
        'cancelled' => 'Subscriptions that ended while they still owed money after the card failed. These are the ones the card actually cost you.',
    ],

    'kpi' => [
        'in_group' => 'Subscriptions',
        'money' => 'Money not collected',
    ],

    'col' => [
        'amount' => 'Owed',
        'card' => 'Card',
        'reason' => 'Why it failed',
        'attempts' => 'Attempts',
        'next_attempt' => 'Next attempt',
    ],

    'card' => [
        'none' => 'No usable card',
        'unknown' => 'Card on file',
    ],

    'reason' => [
        'unknown' => 'The gateway gave no reason',
        /*
        | No charge slot at all — this subscription was never charged by us. Said
        | plainly, because "the gateway gave no reason" about a charge that never
        | happened sends a merchant hunting for a decline that does not exist.
        */
        'imported_unpaid' => 'Imported already in arrears — never charged here',
        'never_attempted' => 'No charge has ever been attempted',
    ],

    'next' => [
        'on_card_update' => 'When the card is updated',
        'never' => '—',
    ],

    'since' => 'failing since :when',

    'held' => [
        'title' => ':count subscriptions are on hold, waiting for a card',
        'body' => 'Nothing was cancelled and nothing was written off. Each one still owes its cycle on the day it was due, and updating the card charges it from that date. Select them and send a card-update link — that is the one thing that brings them back.',
    ],

    'action' => [
        'send_links' => 'Email a card-update link',
        'send_links_heading' => 'Ask these customers to update their card?',
        'send_links_body' => 'Each one gets an email with a link of ours that lasts days; the payment page behind it is created at the moment they click. Customers with no email address, or on a store with no PayPlus connection, are skipped and counted.',
        'send_links_done' => ':sent card-update links sent.',
        'send_links_skipped' => ':count were skipped — no email address, or the store cannot mint a payment page right now.',
    ],

    'empty' => [
        'retrying' => 'No charge is waiting on a retry. Nothing to chase.',
        'stopped' => 'No subscription is on hold for a card.',
        'cancelled' => 'No subscription has been lost to a failed card.',
    ],
];
