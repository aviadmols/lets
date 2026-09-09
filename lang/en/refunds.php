<?php

/*
 * The Refund / Cancel drawer, and the record it leaves behind.
 *
 * The wording carries one promise: nothing here ever claims a leg ran that did
 * not. "Refunded" means money left, "recorded on the store" means the order was
 * updated, and when the two disagree the merchant is told plainly instead of
 * being shown a green tick.
 */

return [

    'action' => [
        'open' => 'Refund or cancel',
        'submit' => 'Refund',
        'submit_cancel' => 'Cancel the order',
        'retry_store' => 'Retry store sync',
    ],

    'heading' => 'Refund or cancel this order',
    'intro' => 'Money goes back through :rail. The order and the paperwork follow.',

    'field' => [
        'mode' => 'What should happen',
        'mode_option' => [
            'refund_full' => 'Refund everything still refundable',
            'refund_partial' => 'Refund part of it',
            'cancel_order' => 'Cancel the order',
        ],
        'mode_help' => [
            'refund_full' => 'Every charge on this order goes back. The order itself stays as it is.',
            'refund_partial' => 'You choose the amount. It comes off the most recent charge first.',
            'cancel_order' => 'Everything refundable goes back, the order is cancelled on the store, and any subscription it started is stopped.',
        ],
        'amount' => 'Amount to refund',
        'amount_help' => 'At most :max — what is still refundable on this order today.',
        'restock' => 'Return the items to stock',
        'restock_help' => 'The store puts the quantities back on the shelf.',
        'reason' => 'Reason',
        'reason_help' => 'Written on the store order, in the timeline, and on the credit note.',
        'notify' => 'Let the customer know',
        'notify_help' => 'The store sends its own refund notice.',
    ],

    'summary' => [
        'heading' => 'What will happen',
        'money' => 'Money',
        'money_value' => ':amount back to the customer through :rail.',
        'money_none' => 'No money moves — nothing on this order was paid.',
        'store' => 'Store',
        'store_refund' => 'The order records the refund:restock.',
        'store_cancel' => 'The order is cancelled:restock.',
        'store_restock_suffix' => ' and the items go back to stock',
        'store_none' => 'This shop has no live store connection, so the order is not updated.',
        'document' => 'Paperwork',
        'document_value' => 'A credit note per reversed charge, linked to the document it credits.',
        'document_off' => 'No document — this shop has no invoicing provider connected.',
        'plan' => 'Subscription',
        'plan_cancel' => 'The subscription started by this order is cancelled and stops billing.',
        'plan_keep' => 'The subscription is untouched and keeps billing.',
    ],

    'rail' => [
        'payplus' => 'PayPlus',
        'shopify_native' => 'Shopify Payments',
        'woo_gateway' => "the order's own payment gateway",
        'external' => 'a refund made outside LETS',
        'none' => 'no payment rail',
    ],

    'status' => [
        'pending' => 'Working…',
        'money_done' => 'Money returned',
        'store_done' => 'Store updated',
        'completed' => 'Done',
        'failed' => 'Nothing was refunded',
        'needs_attention' => 'Needs attention',
    ],

    'result' => [
        'heading' => 'Result',
        'money_ok' => ':amount returned to the customer.',
        'money_partial' => ':amount returned. :failed of the charges were refused — see below.',
        'money_failed' => 'No money was returned.',
        'store_ok' => 'The store order was updated.',
        'store_skipped' => 'Nothing to update on the store.',
        'store_failed' => 'The store could not be updated: :error',
        'documents' => ':count credit notes issued.',
        'documents_pending' => 'Credit notes are being issued.',
        'charge_line' => ':amount — :outcome',
    ],

    'needs_attention' => [
        'title' => 'The money went back; the store does not know yet',
        'body' => 'The customer has been refunded :amount. The order on your store still shows as paid, because :error. Retrying is safe — it will not refund anybody twice.',
        'badge' => 'Store sync failed',
        'filter' => 'Refunds needing attention',
    ],

    'store' => [
        // The note the merchant reads inside their own store admin.
        'woo_note' => 'LETS refund: :amount :currency. Reason: :reason',
    ],

    'failure' => [
        'nothing_to_refund' => 'There is nothing left to refund on this order.',
        'money_failed' => 'The payment gateway refused the refund.',
        'store_failed' => 'The store could not be updated.',
        'no_order' => 'This charge has no store order to refund against.',
        'not_refundable' => 'This charge cannot be refunded.',
        'no_transaction' => 'This charge has no gateway transaction to reverse.',
        'exceeds_remaining' => 'That is more than is left on this charge.',
        'already_refunded' => 'Already refunded.',
        'refund_failed' => 'The gateway refused this refund.',
        'store_exception' => 'The store did not answer.',
        // A scope the merchant has not been approved for — an action they can
        // take in their Partner Dashboard, not a fault they can only report.
        'protected_data_pending' => 'Shopify refused: this app is not yet approved for the customer data this order needs.',
    ],

    'notify_result' => [
        'started' => 'Refunding — this page updates as each step finishes.',
        'completed' => 'Refunded :amount.',
        'failed' => 'Nothing was refunded.',
        'needs_attention' => 'The money went back, but your store was not updated.',
        'retried' => 'The store was updated.',
    ],

    'empty' => [
        'nothing_refundable' => 'Nothing on this order can be refunded — it was either never paid, or it has already been fully refunded.',
    ],

];
