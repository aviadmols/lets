<?php

/**
 * Customer-portal strings (Phase 6.5). Rendered on the customer-facing magic-link
 * page (NOT the admin panel) — kept plain + short. lang/he/portal.php mirrors every
 * key (RTL-aware). Plan/payment status keys mirror the PlanStatus / PaymentStatus
 * enum values so portal.status_{value} / portal.payment_status_{value} resolve.
 */
return [
    'page_title' => 'Manage your subscription',
    'heading' => 'Your subscriptions',
    'subtitle' => 'Manage your plans with :business.',
    'empty' => 'You have no active plans right now.',

    'plan_aria' => 'Subscription plan',
    'kind_installments' => 'Installment plan',
    'kind_recurring' => 'Subscription',

    'total' => 'Total',
    'remaining' => 'Remaining balance',
    'per_cycle' => 'Per cycle',
    'next_charge' => 'Next charge',
    'next_charge_none' => 'None scheduled',

    // History.
    'history_title' => 'Payment history',
    'history_seq' => '#',
    'history_amount' => 'Amount',
    'history_status' => 'Status',
    'history_date' => 'Date',

    // Actions.
    'action_pause' => 'Pause',
    'action_resume' => 'Resume',
    'action_cancel' => 'Cancel plan',
    'confirm_cancel' => 'Are you sure you want to cancel this plan? This cannot be undone.',

    'footnote' => 'This page is private to you. Need help? Contact :business.',

    // Plan status labels (mirror PlanStatus values).
    'status_draft' => 'Draft',
    'status_awaiting_first_payment' => 'Awaiting first payment',
    'status_active' => 'Active',
    'status_paused' => 'Paused',
    'status_failed' => 'Payment failed',
    'status_completed' => 'Completed',
    'status_cancelled' => 'Cancelled',

    // Payment-slot status labels (mirror PaymentStatus values).
    'payment_status_pending' => 'Pending',
    'payment_status_succeeded' => 'Paid',
    'payment_status_failed' => 'Failed',
    'payment_status_retry_scheduled' => 'Retry scheduled',
    'payment_status_refunded' => 'Refunded',
];
