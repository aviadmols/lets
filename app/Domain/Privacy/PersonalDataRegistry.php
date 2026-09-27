<?php

namespace App\Domain\Privacy;

/**
 * EVERY column in the schema that can hold a customer's personal data, and
 * who erases it.
 *
 * The redaction jobs used to know six tables; personal data had since spread
 * to twenty. This map is the contract that stops that happening again:
 * PersonalDataRegistryTest walks the live schema, and any column whose name
 * looks personal (email, phone, name, address, ip, recipient, raw payload…)
 * that is neither ERASED here nor EXEMPT with a reason fails the build. A new
 * table with a `customer_email` column cannot ship without deciding how it is
 * redacted.
 *
 * ERASED — the column is cleared (sentinel / null / scrubbed JSON) or its row
 * deleted by RedactCustomerData (for the one customer) AND RedactShopData (for
 * everyone), through the job itself or PersonalDataEraser.
 *
 * EXEMPT — the column is not a CUSTOMER's personal data (merchant contact
 * details, platform staff), or is kept on purpose, with the reason written down.
 */
final class PersonalDataRegistry
{
    // === CONSTANTS ===
    /**
     * Column-name fragments that mark a column as possibly personal. Matched as a
     * case-insensitive substring of the column name.
     *
     * @var list<string>
     */
    public const PERSONAL_FRAGMENTS = [
        'email', 'phone', 'customer_name', 'buyer_', 'address', 'birthday',
        'user_agent', '_ip', 'ip_hash', 'sent_to', 'recipient', 'customer_ref',
        'customer_gid', 'raw_payload', 'destination', 'card_last_four', 'card_exp',
    ];

    /** @var array<string, list<string>> table => columns erased */
    public const ERASED = [
        // The existing six (RedactCustomerData / RedactShopData bodies).
        'installment_plans' => ['customer_name', 'customer_email', 'customer_phone', 'meta'],
        'customer_consents' => ['customer_email', 'customer_ip', 'user_agent'],
        'installment_payment_methods' => ['card_brand', 'card_last_four', 'payplus_card_token_uid', 'payplus_customer_uid', 'payplus_token_reference', 'encrypted_payplus_token'],
        'issued_documents' => ['document_url', 'raw_response_masked', 'source_payload'],
        'activity_events' => ['details'],
        'loyalty_accounts' => ['customer_name', 'customer_email', 'birthday'],

        // Added with the registry (PersonalDataEraser).
        'payment_ledger' => ['customer_name', 'customer_email', 'raw_response_masked'],
        'installment_payments' => ['raw_response_masked'],
        'subscription_contracts' => ['customer_email', 'customer_name', 'shopify_customer_gid', 'card_brand', 'card_last_four', 'card_exp', 'lines'],
        'webhook_events' => ['raw_payload'],
        'email_campaign_recipients' => ['email', 'customer_name', 'customer_ref'],          // row deleted
        'customer_login_tokens' => ['email', 'customer_name', 'customer_ref', 'recipient_id', 'consumed_ip_hash', 'consumed_user_agent'], // row deleted
        'customer_login_codes' => ['destination_hash', 'ip_hash'],                          // row deleted
        'gift_recipients' => ['customer_name', 'customer_email'],
        'gift_export_rows' => ['recipient', 'fields'],                                      // row deleted
        'gift_campaigns' => ['source_emails'],
        'gift_export_runs' => ['emails'],
        'loyalty_point_events' => ['meta'],
        'loyalty_referrals' => ['buyer_ref', 'buyer_email'],
        'refund_requests' => ['reason', 'lines', 'money_result', 'store_result', 'doc_result'],
        'card_update_links' => ['sent_to'],
        'token_recovery_results' => ['candidates'],
        'upsell_offer_events' => ['customer_ref', 'context'],
        'data_request_exports' => ['shopify_customer_id', 'customer_email', 'export'],       // row deleted
    ];

    /** @var array<string, string> "table.column" => why it is not erased */
    public const EXEMPT = [
        'users.email' => 'Merchant staff and platform admins, not store customers.',
        'mail_settings.from_address' => "The merchant's own sender address.",
        'platform_mail_settings.from_address' => "The platform's sender address.",
        'merchant_billing_settings.support_email' => "The merchant's support contact.",
        'merchant_billing_settings.cancel_contact_email' => "The merchant's cancellation contact.",
        'merchant_billing_settings.cancel_contact_phone' => "The merchant's cancellation contact.",
        'merchant_portal_appearance.support_email' => "The merchant's support contact.",
        'campaign_unsubscribes.email' => 'KEPT on customers/redact: the suppression record is what guarantees an erased person is never mailed marketing again (GDPR Art. 17(3) / Art. 21). Deleted on shop/redact.',
        'campaign_unsubscribes.ip_hash' => 'A salted hash kept with the suppression record; deleted on shop/redact.',
        'loyalty_accounts.customer_ref' => "The store's own customer id. Kept on customers/redact like every CUSTOMER_ID_COLUMNS identifier (the person is gone from the row); replaced on shop/redact.",
        'gift_recipients.address_source' => 'A label naming where an address came from, not the address.',
        'mail_settings.email_locale' => 'A language setting.',
        'merchant_loyalty_settings.birthday_points' => 'A points amount setting.',
        'password_reset_tokens.email' => 'Merchant staff password resets, not store customers.',
        'sessions.ip_address' => 'Framework session rows; they expire with the session lifetime and carry no customer key.',
        'sessions.user_agent' => 'Framework session rows; they expire with the session lifetime and carry no customer key.',
    ];

    /**
     * Name shapes that are never a VALUE of personal data: foreign keys,
     * timestamps, counters, switches, and template subject/body settings.
     *
     * @var list<string>
     */
    private const NON_VALUE_SUFFIXES = ['_id', '_at', '_total', '_count', '_enabled', '_subject', '_body'];

    /** @var list<string> */
    private const NON_VALUE_PREFIXES = ['send_', 'emails_'];

    /** Does this column name look like it could hold personal data? */
    public static function looksPersonal(string $column): bool
    {
        $column = strtolower($column);

        foreach (self::NON_VALUE_SUFFIXES as $suffix) {
            if (str_ends_with($column, $suffix)) {
                return false;
            }
        }
        foreach (self::NON_VALUE_PREFIXES as $prefix) {
            if (str_starts_with($column, $prefix)) {
                return false;
            }
        }

        foreach (self::PERSONAL_FRAGMENTS as $fragment) {
            if (str_contains($column, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** Is this column accounted for — erased, or exempt with a reason? */
    public static function covers(string $table, string $column): bool
    {
        return in_array($column, self::ERASED[$table] ?? [], true)
            || array_key_exists($table.'.'.$column, self::EXEMPT);
    }
}
