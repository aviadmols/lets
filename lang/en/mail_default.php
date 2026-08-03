<?php

/*
 * The PLATFORM'S default email copy — what a shop that has never edited a
 * template sends. The English mirror of lang/he/mail_default.php; every key
 * must exist in both, or a customer receives a raw dotted string.
 *
 * See the Hebrew file for why this copy lives in a lang file rather than inside
 * DefaultEmailTemplates.
 */

return [

    'subject' => [
        'first_payment_welcome' => 'Welcome — your first payment went through ({business_name})',
        'recurring_payment_reminder' => 'Reminder: your next charge is on {next_charge_date} ({business_name})',
        'manual_recurring_payment' => 'Payment request — {business_name}',
        'charge_succeeded' => 'Payment of {amount} {currency} received ({business_name})',
        'charge_failed' => 'Your payment did not go through ({business_name})',
        'plan_cancelled' => 'Your plan has been cancelled — {business_name}',
        'login_code' => 'Your sign-in code: {code}',
        'order_updated' => 'Your order was updated — {business_name}',
    ],

    'greeting' => 'Hi {customer_name},',
    'footer' => 'Plan #{plan_id} · {business_name}',
    'amount' => 'Amount: {amount} {currency}',

    /* The two ready-made sentences. They are VARS and not template conditionals
       because an open-ended subscription must show neither — see
       TemplateRenderer::progress(). */
    'progress' => ' (payment %s of %s)',
    'total_note' => '%s payments in total. ',

    'first_payment_welcome' => [
        'lead' => 'Thank you — your first payment for <strong>{product_title}</strong> went through.',
        'next' => '{installment_total_note}Your next charge is due on {next_charge_date}.',
        'cta' => 'View my plan',
    ],

    'recurring_payment_reminder' => [
        'lead' => 'A quick reminder that your next charge for <strong>{product_title}</strong> is due on <strong>{next_charge_date}</strong>.',
        'cta' => 'Manage my subscription',
    ],

    'manual_recurring_payment' => [
        'lead' => 'Your payment for <strong>{product_title}</strong> is due. Please complete it by {due_date}.',
        'amount' => 'Amount due: {amount} {currency}',
        'cta' => 'Pay now',
    ],

    'charge_succeeded' => [
        'lead' => 'Your payment for <strong>{product_title}</strong> went through{installment_progress}.',
        'cta' => 'View invoice',
        'secondary' => 'Manage my plan',
    ],

    'charge_failed' => [
        'lead' => 'We could not charge your payment method for <strong>{product_title}</strong>.',
        'reason' => 'Reason: {failure_reason}. We will try again on {next_retry_date}. You can update your card first:',
        'cta' => 'Update payment method',
    ],

    'plan_cancelled' => [
        'lead' => 'Your plan for <strong>{product_title}</strong> has been cancelled. {cancellation_reason}',
        'cta' => 'View history',
    ],

    'login_code' => [
        'heading' => 'Your sign-in code',
        'lead' => 'Enter this code in your account at {business_name}:',
        'valid' => 'The code is valid for {expires_minutes} minutes.',
        'footer' => 'Did not ask for a code? You can ignore this — it cannot sign anyone in on its own. · {business_name}',
    ],

    'order_updated' => [
        'heading' => 'Your order was updated',
        'lead' => 'You added items to order <strong>#{order_number}</strong> after checkout. Here is what changed:',
        'total' => 'Added: {added_total} {currency}',
        'closing' => 'Your order is on its way. Thank you!',
        'footer' => 'Order #{order_number} · {business_name}',
    ],

];
