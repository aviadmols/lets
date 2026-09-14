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
        /*
        | Ask PayPlus what card it holds, in bulk. The per-subscription button has
        | always existed but only appears when the plan is provably in that
        | situation, which means a migrated book's broken tokens are found one
        | failed cycle at a time. This finds them all at once — and charges nothing.
        */
        /*
        | Find the card AND take the money, in one click. The charge is QUEUED on
        | the scheduler's own job, so the copy says the results arrive here rather
        | than promising them on the spot.
        */
        'recover_and_charge' => 'Find card & charge now',
        'recover_and_charge_heading' => 'Find each member\'s card and charge them?',
        'recover_and_charge_body' => 'For a member whose card is in doubt we first ask PayPlus what it holds and save it. Then a charge is queued for every member with a usable card — the same job the scheduler runs. A charge that succeeds makes the subscription active again and advances its date; one that is declined stays here. Results land on this screen within a few minutes.',
        'recover_and_charge_submit' => 'Find & charge',
        'recover_and_charge_done' => ':queued charges queued — watch this screen.',
        'recover_and_charge_report' => 'Cards found and saved first: :recovered. Not charged: :no_card had no card to charge, :skipped were cancelled or completed.',
        'recover_and_charge_double_body' => ':count members were recovered but NOT charged, because PayPlus is still billing them itself — charging here would take their money twice. Cancel the recurring charge at PayPlus first for: :names',

        'recover_tokens' => 'Find saved cards at PayPlus',
        'recover_tokens_heading' => 'Ask PayPlus what card it holds for these members?',
        'recover_tokens_body' => 'For each one we ask PayPlus which card it has on file and save it here when the answer is unambiguous. NOTHING IS CHARGED. A member whose last 4 digits we do not know is skipped rather than matched on a guess, and more than one possible card is refused outright.',
        'recover_tokens_submit' => 'Ask PayPlus',
        'recover_tokens_done' => ':count cards found and saved.',
        'recover_tokens_report' => 'Of the rest: :valid already had a working card · :ambiguous had more than one possible card · :no_last_four had no last-4 digits to match on · :not_found had no card at PayPlus · :skipped had no card record at all.',

        'recover_tokens_double_title' => 'Warning — PayPlus is still billing some of these members itself',
        'recover_tokens_double_body' => 'Now that we hold a working card, :count of them would be charged TWICE — once by us and once by PayPlus\'s own recurring schedule. Cancel the recurring charge at PayPlus for: :names',

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
