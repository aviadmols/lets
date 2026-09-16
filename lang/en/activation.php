<?php

/*
| The page behind a subscription's activation link (PlanActivationController).
| Shown in the SHOP's customer language.
*/
return [
    'page' => [
        'title' => 'Start your subscription',
        'heading' => 'Your subscription is ready',
        'lead' => 'Your subscription to :product from :shop is paid and waiting for you. It starts the moment you press the button — and your next charge is counted from today.',
        'button' => 'Start my subscription',
        'note' => 'Nothing more is charged today.',
        'active_title' => 'Your subscription is active',
        'active_heading' => 'Your subscription is active',
        'active_lead' => 'Your subscription to :product from :shop has started.',
        'next_charge' => 'Next charge: :date',
        'lead_generic' => 'Your subscription from :shop is paid and waiting for you. It starts the moment you press the button — and your next charge is counted from today.',
        'active_lead_generic' => 'Your subscription from :shop has started.',
        'failed' => 'We could not start it just now. Nothing was charged — please try again in a few minutes.',
    ],
];
