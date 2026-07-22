<?php

namespace App\Domain\Invoicing;

use App\Models\InstallmentPlan;

/**
 * The party a document is issued TO, in provider-neutral terms. The provider maps
 * this onto its own shape (Green Invoice: the `client` object).
 *
 * `taxId` is the Israeli ח.פ/ת.ז when the merchant captured one — it is optional
 * for a consumer sale and must NEVER be invented: a wrong tax id on a real tax
 * document is a reporting error the merchant has to correct with the authority.
 */
final class DocumentCustomer
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $taxId = null,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
    ) {}

    /**
     * Build from a plan's stored customer fields. Falls back to the plan's own
     * customer label so a document is never issued to an empty name (which the
     * provider rejects) — mirrors InstallmentPlan::customerLabel().
     */
    public static function fromPlan(InstallmentPlan $plan): self
    {
        $name = trim((string) ($plan->customer_name ?? ''));

        return new self(
            name: $name !== '' ? $name : $plan->customerLabel(),
            email: self::blankToNull((string) ($plan->customer_email ?? '')),
            phone: self::blankToNull((string) ($plan->customer_phone ?? '')),
        );
    }

    /** Does this customer have somewhere the provider could email the document? */
    public function isEmailable(): bool
    {
        return $this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function blankToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
