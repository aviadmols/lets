<?php

// Payments → Invoices: the merchant's view of every accounting document the
// invoicing module tried to issue. Admin-facing copy only — the text printed ON
// the documents lives in invoicing.php. Mirror every key in lang/he/invoices.php.
return [

    'empty' => 'No invoices yet. They appear here automatically once invoicing is on.',

    'col' => [
        'type' => 'Document',
        'number' => 'Number',
        'reason' => 'Reason',
    ],

    'tab' => [
        'attention' => 'Needs attention',
        'issued' => 'Issued',
        'all' => 'All',
    ],

    'filter' => [
        'needs_attention' => 'Needs attention',
    ],

    'status' => [
        'issued' => 'Issued',
        'pending' => 'In progress',
        'failed' => 'Failed',
        // Deliberately not "Failed": we do not know that it failed. Saying so
        // would push a merchant to re-issue, which is the one action that can
        // create a duplicate tax document.
        'unresolved' => 'Needs checking',
    ],

    'action' => [
        'open' => 'Open',
        'failed' => 'Could not do that',

        'retry' => 'Try again',
        'retry_heading' => 'Try issuing this invoice again?',
        'retry_body' => 'Green Invoice rejected the earlier attempt, so no document was created. Trying again is safe.',
        'retry_queued' => 'Queued. The invoice will appear here shortly.',

        'issue_anyway' => 'Issue a new one',
        'issue_anyway_heading' => 'Check Green Invoice first',
        'issue_anyway_body' => 'We asked Green Invoice for this invoice but never learned the answer, so a document may already exist there. Open Green Invoice and search for it. Only continue if there is NO document for this payment — otherwise you will report the same income twice.',
        'issue_anyway_confirm' => 'There is no document — issue it',

        'record_existing' => 'I found it in Green Invoice',
        'record_existing_heading' => 'Link the existing document',
        'record_existing_body' => 'The earlier attempt did go through and we simply never heard back. Paste the document details from Green Invoice and we will link them here instead of issuing another.',
        'recorded' => 'Linked. This payment is now marked as invoiced.',

        'field' => [
            'document_id' => 'Document ID in Green Invoice',
            'document_url' => 'Link to the document',
        ],
    ],

    // Machine reasons from DocumentReconciliationService, in plain language.
    'reason' => [
        'ok' => 'Done.',
        'not_retryable' => 'This one cannot be retried automatically — check Green Invoice first.',
        'not_unresolved' => 'This invoice is not waiting to be checked.',
        'already_issued' => 'This invoice has already been issued.',
        'missing_document_id' => 'Enter the document ID from Green Invoice.',
        'not_rebuildable' => 'The original order details are no longer stored, so this invoice cannot be re-issued accurately. Create it directly in Green Invoice, then use “I found it in Green Invoice”.',
    ],
];
