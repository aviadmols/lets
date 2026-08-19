<?php

namespace App\Domain\Account;

use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHO is looking at the personal area, and how we know.
 *
 * This closes a real seam. Until now the same human had three different names in
 * three places: the loyalty club knew them by a bare WooCommerce user id, the
 * portal by a typed `ext:`/`email:` ref, and the Shopify rail by a customer GID.
 * A personal area that shows subscriptions, points and orders on one screen has to
 * resolve all three, or it shows a shopper somebody else's half of their own life.
 *
 * The reference is ALWAYS server-established — the WordPress plugin's own server
 * asserts the logged-in user over HMAC, or Shopify puts `logged_in_customer_id`
 * inside its signed query. The browser never says who it is, so matching on the
 * platform-asserted email as well as the id is safe: both come from the account
 * the platform already authenticated.
 *
 * A visitor with no reference is not an error. It is a logged-out shopper, and the
 * page owes them a sign-in prompt, not a fail-closed blank.
 */
final class AccountVisitor
{
    // === CONSTANTS ===
    /** The one reference column that is a bigint, not a string (see narrow()). */
    public const NUMERIC_REF_COLUMN = 'customer_id';

    public const SOURCE_WOOCOMMERCE = 'woocommerce';  // plugin server → HMAC
    public const SOURCE_PROXY = 'proxy';              // Shopify App Proxy (signed query)
    public const SOURCE_PREVIEW = 'preview';          // the admin's appearance preview

    private function __construct(
        public readonly Shop $shop,
        public readonly ?string $customerRef,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly ?string $phone,
        public readonly string $source,
    ) {}

    public static function make(
        Shop $shop,
        ?string $customerRef,
        string $source,
        ?string $email = null,
        ?string $name = null,
        ?string $phone = null,
    ): self {
        return new self(
            shop: $shop,
            customerRef: self::clean($customerRef),
            email: self::cleanEmail($email),
            name: self::clean($name),
            phone: self::clean($phone),
            source: $source,
        );
    }

    /** Did the platform tell us who this is? */
    public function isIdentified(): bool
    {
        return $this->customerRef !== null;
    }

    /**
     * Every PayPlus plan belonging to this shopper on the bound shop.
     *
     * Tenant-scoped by BelongsToShop, then narrowed to the identities the platform
     * vouched for. An unidentified visitor matches NOTHING — the `1 = 0` is the
     * fail-closed floor the portal uses for an unknown ref, and it matters here
     * too: a bug that lost the ref must produce an empty page, never everyone's.
     */
    public function plans(): Builder
    {
        return $this->narrow(InstallmentPlan::query(), [
            'external_customer_id',
            'shopify_customer_id',
            'customer_id',
        ], 'customer_email');
    }

    /** Every Shopify-Payments contract belonging to this shopper. */
    public function contracts(): Builder
    {
        return $this->narrow(SubscriptionContract::query(), [
            'shopify_customer_gid',
        ], 'customer_email');
    }

    /**
     * This visitor's loyalty membership, or null (not identified, or never joined).
     *
     * The same human can arrive under a different reference than the one they
     * joined with — a WooCommerce user id one day, a billing email the next — so
     * the email is a second chance, not a second identity.
     */
    public function loyaltyAccount(): ?LoyaltyAccount
    {
        if (! $this->isIdentified()) {
            return null;
        }

        $account = LoyaltyAccount::query()->where('customer_ref', $this->customerRef)->first();
        if ($account !== null) {
            return $account;
        }

        return $this->email !== null
            ? LoyaltyAccount::query()->where('customer_email', $this->email)->first()
            : null;
    }

    /** The name to greet them by — theirs, else the local part of their email. */
    public function displayName(): ?string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return $this->email !== null ? strtok($this->email, '@') ?: null : null;
    }

    /**
     * Narrow a query to this visitor across the columns a platform might have
     * written the reference into, plus the platform-asserted email.
     *
     * The Shopify customer GID and its bare numeric id are both accepted: the
     * proxy hands us the number, while contracts store the GID.
     *
     * @param  list<string>  $refColumns
     */
    private function narrow(Builder $query, array $refColumns, string $emailColumn): Builder
    {
        if (! $this->isIdentified()) {
            return $query->whereRaw('1 = 0');
        }

        $ref = (string) $this->customerRef;
        $email = $this->email;

        return $query->where(function (Builder $q) use ($refColumns, $emailColumn, $ref, $email): void {
            foreach ($refColumns as $column) {
                // `customer_id` is an unsignedBigInteger. Postgres refuses to read
                // a non-numeric reference as one and aborts the whole query rather
                // than not matching — and an imported member's reference is the
                // UUID their old system gave them. It cannot match a bigint, so it
                // is not asked.
                if ($column === self::NUMERIC_REF_COLUMN && ! ctype_digit($ref)) {
                    continue;
                }

                $q->orWhere($column, $ref);
                if (str_ends_with($column, '_gid')) {
                    $q->orWhere($column, 'gid://shopify/Customer/'.$ref);
                }
            }

            if ($email !== null) {
                $q->orWhereRaw('LOWER('.$emailColumn.') = ?', [$email]);
            }
        });
    }

    private static function clean(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    /** Lowercased so it matches the way every query compares it. */
    private static function cleanEmail(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($value) : null;
    }
}
