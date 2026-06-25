<?php

namespace App\Domain\Privacy;

/**
 * The SINGLE source of truth for the GDPR redaction policy: which columns are
 * personal data (anonymise on redact) and which are the financial/legal record
 * (KEEP — Israeli + EU bookkeeping law requires the transaction trail; it does
 * not require the customer's name).
 *
 * Both RedactCustomerData and RedactShopData read this map so the policy lives in
 * exactly one place. The audit ActivityEvent records COUNTS keyed by table, never
 * the values, so the audit trail itself carries no PII.
 *
 *   PII columns (anonymise → sentinel/null):
 *     installment_plans              customer_name, customer_email, customer_phone
 *     customer_consents              customer_email, customer_ip, user_agent
 *     installment_payment_methods    card_brand, card_last_four  (token is encrypted; card metadata is quasi-PII)
 *     activity_events                details JSON   (recursively scrubbed for known PII keys)
 *
 *   KEPT (financial / legal trail — never destroyed):
 *     payment_ledger                 amount, currency, status, dates, idempotency_key,
 *                                    payplus_transaction_uid, payplus_document_uid
 *     installment_plans              total_amount, total_charged, status, dates, order ids
 *     installment_payments           amount, status, dates
 *
 * The financial rows that carry a CUSTOMER IDENTIFIER (shopify_customer_id /
 * customer_id / external_customer_id) keep the amount but have the identifier
 * neutralised on shop-level redact, so the row can no longer be tied to a person.
 */
final class RedactionPolicy
{
    // === CONSTANTS ===
    /** The visible sentinel written into string PII columns. */
    public const SENTINEL = '[redacted]';

    /**
     * PII columns to anonymise, per table. Value = sentinel for strings, the
     * column is set to NULL when listed in NULLABLE_PII.
     *
     * @var array<string, list<string>>
     */
    public const PII_COLUMNS = [
        'installment_plans' => ['customer_name', 'customer_email', 'customer_phone'],
        'customer_consents' => ['customer_email', 'customer_ip', 'user_agent'],
        'installment_payment_methods' => ['card_brand', 'card_last_four'],
    ];

    /**
     * Columns that should be set to NULL rather than the sentinel string (numeric
     * or credential-adjacent fields where a sentinel string would be invalid).
     *
     * @var array<string, list<string>>
     */
    public const NULLABLE_PII = [
        'installment_payment_methods' => ['card_brand', 'card_last_four'],
        'customer_consents' => ['customer_ip', 'user_agent'],
    ];

    /**
     * Customer-identifier columns. On CUSTOMER redact these stay (they identify
     * WHICH customer was redacted within this shop, and the PII is already gone).
     * On SHOP redact they are neutralised so a kept financial row can no longer be
     * linked to a person.
     *
     * @var array<string, list<string>>
     */
    public const CUSTOMER_ID_COLUMNS = [
        'installment_plans' => ['shopify_customer_id', 'external_customer_id'],
        'customer_consents' => ['shopify_customer_id'],
        'installment_payment_methods' => ['shopify_customer_id'],
        'payment_ledger' => ['shopify_customer_id'],
    ];

    /**
     * Keys treated as PII inside any JSON `details` / `meta` blob — recursively
     * replaced with the sentinel (case-insensitive substring match against the key).
     *
     * @var list<string>
     */
    public const PII_JSON_KEYS = [
        'customer_name', 'name', 'first_name', 'last_name',
        'customer_email', 'email',
        'customer_phone', 'phone',
        'customer_ip', 'ip', 'user_agent',
        'address', 'address1', 'address2', 'city', 'zip',
    ];

    /**
     * Recursively replace any PII-keyed value in a JSON blob with the sentinel.
     * Non-PII keys (amounts, ids, statuses, timestamps) are preserved untouched.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function scrubJson(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::scrubJson($value);

                continue;
            }

            if (is_string($key) && self::isPiiKey($key) && $value !== null && $value !== '') {
                $data[$key] = self::SENTINEL;
            }
        }

        return $data;
    }

    private static function isPiiKey(string $key): bool
    {
        $needle = mb_strtolower($key);

        foreach (self::PII_JSON_KEYS as $piiKey) {
            if (str_contains($needle, $piiKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A non-reversible, non-PII reference for the audit trail. We never write the
     * raw email/id into an ActivityEvent, but we still want to correlate the audit
     * row with a specific customer — a salted hash does both.
     */
    public static function customerRef(?string $shopifyCustomerId, ?string $email): string
    {
        $seed = ($shopifyCustomerId ?? '').'|'.mb_strtolower((string) $email);

        return substr(hash('sha256', $seed), 0, 16);
    }
}
