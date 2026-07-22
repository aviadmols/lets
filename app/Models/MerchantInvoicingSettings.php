<?php

namespace App\Models;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceDocumentType;
use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Per-shop invoicing policy. Exactly ONE row per shop, lazily created with spec
 * defaults on first read (current()). Tenant-scoped (shop_id + BelongsToShop);
 * shop_id is guarded so a raw create/update can never re-key the row to another
 * tenant — a direct sibling of MerchantBillingSettings / MerchantMailSettings.
 *
 * What it governs (merchant-editable policy, NOT engine internals):
 *   - enabled: the master switch. OFF (the default) means every invoicing hook in
 *     the money pipeline is a clean no-op — the charge path behaves exactly as it
 *     did before the module existed;
 *   - scope: `plans_only` issues documents for LETS money only (deposits,
 *     installments, recurring cycles, upsells, refunds); `all_orders` ALSO issues
 *     for plain store orders the storefront reports, paid by any method;
 *   - trigger_statuses: in all_orders scope, which WooCommerce order statuses fire
 *     a document. Merchant-picked, because a store that fulfils before charging
 *     and a store that charges before fulfilling want different moments;
 *   - document_type_map: context → Green Invoice numeric type. Every row is
 *     overridable: an Osek Patur may not issue 305 at all, and merchants differ on
 *     whether a deposit deserves a receipt (400) or a proforma (300);
 *   - delivery/format: whether the provider emails the document to the customer,
 *     the document language, VAT type, rounding, and whether the document URL is
 *     written back onto the store order.
 *
 * No secrets live here — the Green Invoice keys are in the ENCRYPTED
 * shops.invoicing_credentials bag (Shop::invoicingConfig()).
 */
class MerchantInvoicingSettings extends Model
{
    use BelongsToShop;

    // === CONSTANTS — table + spec defaults ===
    protected $table = 'merchant_invoicing_settings';

    /** Scope: which money events produce documents. */
    public const SCOPE_PLANS_ONLY = 'plans_only';
    public const SCOPE_ALL_ORDERS = 'all_orders';
    public const SCOPES = [self::SCOPE_PLANS_ONLY, self::SCOPE_ALL_ORDERS];
    public const DEFAULT_SCOPE = self::SCOPE_PLANS_ONLY;

    /**
     * WooCommerce order statuses selectable as `all_orders` triggers. Only statuses
     * that can legitimately mean "the merchant has the money" are offered — a
     * cancelled/refunded/failed order must never mint an income document.
     *
     * @var list<string>
     */
    public const SELECTABLE_TRIGGER_STATUSES = ['processing', 'completed', 'on-hold'];

    /** @var list<string> */
    public const DEFAULT_TRIGGER_STATUSES = ['processing', 'completed'];

    /**
     * Default context → Green Invoice type. The reasoning, per row:
     *   deposit / installment  → 400 קבלה: money was received, but the sale is NOT
     *                            complete (fulfillment is still locked), so a tax
     *                            invoice would over-declare;
     *   final_installment      → 320: the sale completes here; linked to the prior
     *                            receipts;
     *   recurring / upsell /
     *   platform_order         → 320: each is a complete, paid sale in its own right;
     *   refund / cancellation  → 330 זיכוי, linked to the original document.
     *
     * @var array<string, int>
     */
    public const DEFAULT_DOCUMENT_TYPE_MAP = [
        DocumentContext::DEPOSIT->value => 400,
        DocumentContext::INSTALLMENT->value => 400,
        DocumentContext::FINAL_INSTALLMENT->value => 320,
        DocumentContext::RECURRING->value => 320,
        DocumentContext::UPSELL->value => 320,
        DocumentContext::PLATFORM_ORDER->value => 320,
        DocumentContext::REFUND->value => 330,
        DocumentContext::CANCELLATION->value => 330,
    ];

    /** Delivery + formatting defaults. */
    public const DEFAULT_ENABLED = false;
    public const DEFAULT_SEND_EMAIL_TO_CUSTOMER = false;
    public const DEFAULT_LANGUAGE = 'he';
    public const SELECTABLE_LANGUAGES = ['he', 'en'];
    public const DEFAULT_VAT_TYPE = 0;      // Green Invoice: 0 = default/inclusive per business setup
    public const DEFAULT_ROUNDING = false;
    public const DEFAULT_ATTACH_TO_ORDER = true;

    /**
     * shop_id (and the surrogate id) are guarded — shop_id is auto-stamped by
     * BelongsToShop so it can never be mass-assigned to another tenant.
     */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'trigger_statuses' => 'array',
            'document_type_map' => 'array',
            'send_email_to_customer' => 'boolean',
            'default_vat_type' => 'integer',
            'rounding' => 'boolean',
            'attach_to_order' => 'boolean',
        ];
    }

    /**
     * The settings row for the CURRENT tenant, created with spec defaults on first
     * read. Tenant-safe: keyed strictly by Tenant::id() and the BelongsToShop global
     * scope pins every query to the bound shop, so shop A can never see or create
     * shop B's row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            self::defaults(),
        );
    }

    /**
     * The settings row for an EXPLICIT shop, without relying on a bound tenant.
     * Queued jobs carry shop_id explicitly (CLAUDE.md law) and run before/outside
     * a Filament request, so they must be able to read a shop's policy by id. The
     * global scope is bypassed through the AUDITED acrossAllTenants() seam and the
     * shop_id is then matched directly — never a broad, unscoped read.
     */
    public static function forShop(int $shopId): self
    {
        $existing = static::acrossAllTenants()->where('shop_id', $shopId)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $row = new self();
            $row->forceFill(array_merge(self::defaults(), ['shop_id' => $shopId]))->save();

            return $row;
        } catch (QueryException $e) {
            // Two workers raced to create the row and the shop_id unique index caught
            // the loser. Re-read the winner's row: a settings LOOKUP must never be the
            // thing that fails a charge's document, and it is called on every charge.
            $winner = static::acrossAllTenants()->where('shop_id', $shopId)->first();

            if ($winner === null) {
                throw $e; // a real database problem, not the race we expected
            }

            return $winner;
        }
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'enabled' => self::DEFAULT_ENABLED,
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'scope' => self::DEFAULT_SCOPE,
            'trigger_statuses' => self::DEFAULT_TRIGGER_STATUSES,
            'document_type_map' => self::DEFAULT_DOCUMENT_TYPE_MAP,
            'send_email_to_customer' => self::DEFAULT_SEND_EMAIL_TO_CUSTOMER,
            'document_language' => self::DEFAULT_LANGUAGE,
            'default_vat_type' => self::DEFAULT_VAT_TYPE,
            'rounding' => self::DEFAULT_ROUNDING,
            'attach_to_order' => self::DEFAULT_ATTACH_TO_ORDER,
        ];
    }

    // === Typed accessors (defaults applied when a column is null/garbage) ===

    public function isEnabled(): bool
    {
        return (bool) ($this->enabled ?? self::DEFAULT_ENABLED);
    }

    /** `plans_only` | `all_orders`, never an unknown value. */
    public function scope(): string
    {
        $scope = (string) ($this->scope ?? '');

        return in_array($scope, self::SCOPES, true) ? $scope : self::DEFAULT_SCOPE;
    }

    /** Does this merchant want documents for plain store orders too? */
    public function coversAllOrders(): bool
    {
        return $this->scope() === self::SCOPE_ALL_ORDERS;
    }

    /**
     * The WooCommerce statuses that trigger a document in `all_orders` scope.
     * Unknown/garbage entries are dropped (never trust a stored value to be a real
     * status); an empty result falls back to the default so a merchant who saved a
     * broken row still gets documents rather than silence.
     *
     * @return list<string>
     */
    public function triggerStatuses(): array
    {
        $raw = is_array($this->trigger_statuses) ? $this->trigger_statuses : [];

        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($s): string => self::normaliseStatus((string) $s), $raw),
            static fn (string $s): bool => in_array($s, self::SELECTABLE_TRIGGER_STATUSES, true),
        )));

        return $clean !== [] ? $clean : self::DEFAULT_TRIGGER_STATUSES;
    }

    /** Is this WooCommerce status one the merchant chose to issue on? */
    public function triggersOn(string $status): bool
    {
        return in_array(self::normaliseStatus($status), $this->triggerStatuses(), true);
    }

    /**
     * WooCommerce reports order statuses both bare ("processing") and prefixed
     * ("wc-processing") depending on the hook and the REST shape. Normalise to the
     * bare form before ANY comparison, so a merchant's saved selection matches
     * whatever spelling the plugin happens to send.
     */
    private static function normaliseStatus(string $status): string
    {
        $normalised = strtolower(trim($status));

        return str_starts_with($normalised, 'wc-') ? substr($normalised, 3) : $normalised;
    }

    /**
     * The Green Invoice document type for a context: the merchant's override when
     * they set a real one, else the spec default. Never returns null — a context
     * with a corrupt override falls back rather than silently issuing nothing.
     */
    public function documentTypeFor(DocumentContext $context): GreenInvoiceDocumentType
    {
        $map = is_array($this->document_type_map) ? $this->document_type_map : [];

        $override = GreenInvoiceDocumentType::tryFromMixed($map[$context->value] ?? null);
        if ($override !== null) {
            return $override;
        }

        return GreenInvoiceDocumentType::from(
            self::DEFAULT_DOCUMENT_TYPE_MAP[$context->value]
        );
    }

    /**
     * The full context → type map, defaults filled in for any missing/corrupt row.
     * The settings screen renders from this so every context always shows a value.
     *
     * @return array<string, int>
     */
    public function documentTypeMap(): array
    {
        $out = [];
        foreach (DocumentContext::cases() as $context) {
            $out[$context->value] = $this->documentTypeFor($context)->value;
        }

        return $out;
    }

    public function sendsEmailToCustomer(): bool
    {
        return (bool) ($this->send_email_to_customer ?? self::DEFAULT_SEND_EMAIL_TO_CUSTOMER);
    }

    public function documentLanguage(): string
    {
        $lang = strtolower(trim((string) ($this->document_language ?? '')));

        return in_array($lang, self::SELECTABLE_LANGUAGES, true) ? $lang : self::DEFAULT_LANGUAGE;
    }

    public function vatType(): int
    {
        return max(0, (int) ($this->default_vat_type ?? self::DEFAULT_VAT_TYPE));
    }

    public function rounding(): bool
    {
        return (bool) ($this->rounding ?? self::DEFAULT_ROUNDING);
    }

    public function attachesToOrder(): bool
    {
        return (bool) ($this->attach_to_order ?? self::DEFAULT_ATTACH_TO_ORDER);
    }
}
