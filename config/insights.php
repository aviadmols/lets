<?php

// === CONSTANTS ===
// The merchant analytics screens (app/Domain/Insights).

return [
    /*
     * The day a shop's numbers START, per shop id. Retention ("passed month N") and the
     * subscriber-book chart count only from here. Default (no entry): the shop's first
     * charge through the system.
     *
     * A shop gets an entry when its first weeks were the import, not real joining and
     * leaving: sellameir's member import corrected hundreds of statuses on 2026-09-14,
     * so its merchant chose the day after. Kept here, not in a column, so setting it
     * needs no migration.
     */
    'start_dates' => array_replace(
        [
            2 => '2026-09-15',
        ],
        // Extra entries from the environment ({"<shop id>":"YYYY-MM-DD"}) — how a LOCAL
        // preview, whose demo shop has another id, shows the same start as production.
        (array) json_decode((string) env('INSIGHTS_START_DATES', '{}'), true),
    ),

    /* Days after a cycle's date before a missing payment counts as "did not pay" — retries take days. */
    'ladder_grace_days' => 5,

    /* How many months the retention ladder reaches. */
    'ladder_months' => 12,

    /* Days the payment-problems chart looks back. */
    'failure_days' => 120,
];
