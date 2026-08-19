<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\SafeHtml;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An upsell the merchant shows inside a customer's own account page.
 *
 * Every merchant-set value is read back through a GUARD, never raw — the same
 * discipline MerchantPortalAppearance follows, and for the same reason: these
 * values are interpolated into a page on the merchant's storefront in front of a
 * signed-in shopper. An unvalidated URL is a hostile-content vector, an
 * unvalidated mode is a charge nobody meant to make, and an unvalidated audience
 * entry is an offer shown to the wrong person.
 *
 * The offer never carries a PRICE. It points at a subscription template
 * (ProductSubscriptionPlan) and AccountOfferQuote reads the money from there at
 * display AND at accept time, so the number on the card is the number the plan
 * is born with.
 */
class AccountOffer extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'account_offers';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE];

    /**
     * ADD stands the new subscription BESIDE the one the shopper already has.
     * REPLACE ends theirs in favour of it — which is the switch offer, and the
     * reason `replace_timing` exists at all.
     */
    public const MODE_ADD = 'add';

    public const MODE_REPLACE = 'replace';

    public const MODES = [self::MODE_ADD, self::MODE_REPLACE];

    /**
     * WHEN a replacement takes effect. IMMEDIATE charges the saved card on the
     * click and ends the old plan once the money lands. PERIOD_END schedules the
     * new plan's first charge for the day the old one would have renewed and
     * ends the old plan now — no proration, no double charge, no gap.
     */
    public const TIMING_IMMEDIATE = 'immediate';

    public const TIMING_PERIOD_END = 'period_end';

    public const TIMINGS = [self::TIMING_IMMEDIATE, self::TIMING_PERIOD_END];

    /** Where the card is drawn. `plan` sits under the subscription it targets. */
    public const PLACEMENT_TOP = 'top';

    public const PLACEMENT_RAIL = 'rail';

    public const PLACEMENT_PLAN = 'plan';

    public const PLACEMENTS = [self::PLACEMENT_TOP, self::PLACEMENT_RAIL, self::PLACEMENT_PLAN];

    /**
     * The tokens a merchant may write into custom HTML. `{{button}}` is the only
     * REQUIRED one — without it the block has no way to be accepted, which is a
     * promotion the shopper cannot act on.
     */
    public const TOKEN_BUTTON = '{{button}}';

    public const TOKEN_PRICE = '{{price}}';

    public const TOKEN_PRODUCT = '{{product}}';

    public const TOKEN_CADENCE = '{{cadence}}';

    public const TOKEN_HEADING = '{{heading}}';

    public const TOKENS = [
        self::TOKEN_BUTTON,
        self::TOKEN_PRICE,
        self::TOKEN_PRODUCT,
        self::TOKEN_CADENCE,
        self::TOKEN_HEADING,
    ];

    /**
     * What `{{button}}` becomes on the way out. A sentinel, not a `<button>`:
     * SafeHtml's allow-list has no `button` tag, and it should not — the renderer
     * swaps this span for a real control it wired itself, so the click handler is
     * never something a merchant could have typed.
     */
    public const BUTTON_SLOT = '<span class="la-offer__slot"></span>';

    /** The error key the admin form shows when `{{button}}` is missing/duplicated. */
    public const ERROR_BUTTON_REQUIRED = 'account_offers.form.html_button_required';

    public const MAX_HEADING = 80;

    public const MAX_SUBTEXT = 300;

    public const MAX_URL = 500;

    public const MAX_BUTTON_TEXT = 60;

    public const MAX_NAME = 120;

    /** The statuses an offer targets when the merchant named none. */
    public const DEFAULT_AUDIENCE_STATUSES = [
        PlanStatus::ACTIVE->value,
        PlanStatus::PAUSED->value,
    ];

    /** The audience filter keys, in the order the admin form draws them. */
    public const AUDIENCE_KEYS = ['plan_kinds', 'frequencies', 'product_ids', 'statuses'];

    /** A shop cannot target more products than a person would ever pick. */
    public const MAX_AUDIENCE_PRODUCTS = 100;

    /** id + shop_id are never mass-assignable; shop_id is auto-stamped. */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'last_accepted_at' => 'datetime',
            'priority' => 'integer',
            'accepted_count' => 'integer',
        ];
    }

    /**
     * Merchant HTML is sanitised on the way IN as well as on the way out.
     *
     * Cleaning on read alone would be enough for safety, but it would leave the
     * raw string in the database — where a future export, a support tool or a
     * different reader could pick it up unscrubbed. Cleaning here means the
     * hostile version never exists at rest.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $offer): void {
            if ($offer->isDirty('custom_html')) {
                $offer->custom_html = SafeHtml::clean($offer->custom_html);
            }
        });
    }

    // === Relations ===

    /** The subscription template this offer sells. Price + cadence live there. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductSubscriptionPlan::class, 'product_subscription_plan_id');
    }

    // === Scopes ===

    /** Offers the merchant has switched on. The date window is a separate question. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // === Guarded reads ===

    public function isActive(): bool
    {
        return $this->oneOf($this->status, self::STATUSES, self::STATUS_DRAFT) === self::STATUS_ACTIVE;
    }

    public function mode(): string
    {
        return $this->oneOf($this->mode, self::MODES, self::MODE_REPLACE);
    }

    public function isReplace(): bool
    {
        return $this->mode() === self::MODE_REPLACE;
    }

    public function isAdd(): bool
    {
        return $this->mode() === self::MODE_ADD;
    }

    /**
     * The replacement timing, or null when there is nothing to replace.
     *
     * An ADD offer has no timing to report — it ends no period — so the payload
     * carries null for it rather than a value the shopper's card would imply.
     * Use isImmediate() for the question the money actually asks.
     */
    public function timing(): ?string
    {
        if (! $this->isReplace()) {
            return null;
        }

        return $this->oneOf($this->replace_timing, self::TIMINGS, self::TIMING_IMMEDIATE);
    }

    /**
     * Does accepting this offer charge the saved card NOW?
     *
     * Everything that is not explicitly "at period end" does. An ADD is always
     * immediate (there is no other period to wait for), and a replace whose
     * timing column is missing or unreadable falls back to immediate rather than
     * to a silent delay — the shopper is shown a disclosure derived from this
     * same answer, so the two can never disagree.
     */
    public function isImmediate(): bool
    {
        return $this->timing() !== self::TIMING_PERIOD_END;
    }

    public function placement(): string
    {
        return $this->oneOf($this->placement, self::PLACEMENTS, self::PLACEMENT_PLAN);
    }

    /**
     * The audience filters, sanitised. Every list is INCLUSIVE and an empty list
     * means "any" — so a filter that loses all its values stops narrowing rather
     * than hiding the offer from everyone.
     *
     * @return array{plan_kinds: list<string>, frequencies: list<string>, product_ids: list<string>, statuses: list<string>}
     */
    public function audience(): array
    {
        $raw = is_array($this->audience) ? $this->audience : [];

        return [
            'plan_kinds' => $this->enumValues($raw['plan_kinds'] ?? [], PlanKind::class),
            'frequencies' => $this->enumValues($raw['frequencies'] ?? [], BillingFrequency::class),
            'product_ids' => $this->productIds($raw['product_ids'] ?? []),
            'statuses' => $this->enumValues($raw['statuses'] ?? [], PlanStatus::class)
                ?: self::DEFAULT_AUDIENCE_STATUSES,
        ];
    }

    public function heading(): ?string
    {
        return $this->trimmedOrNull($this->heading, self::MAX_HEADING);
    }

    public function subtext(): ?string
    {
        return $this->trimmedOrNull($this->subtext, self::MAX_SUBTEXT);
    }

    public function buttonText(): ?string
    {
        return $this->trimmedOrNull($this->button_text, self::MAX_BUTTON_TEXT);
    }

    /** Only https survives — a customer page must not carry a hostile scheme. */
    public function imageUrl(): ?string
    {
        $value = is_string($this->image_url) ? trim($this->image_url) : '';
        if ($value === '' || ! str_starts_with(strtolower($value), 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            ? mb_substr($value, 0, self::MAX_URL)
            : null;
    }

    /**
     * The merchant's custom block, scrubbed AGAIN on read.
     *
     * The saving hook already cleaned it, so this is belt and braces — but the
     * belt is what a row written before this class existed (an import, a manual
     * SQL fix, a restored backup) has to pass through too.
     */
    public function customHtml(): ?string
    {
        return SafeHtml::clean($this->custom_html);
    }

    /** Is the merchant's date window open at this instant? No window = always. */
    public function isOpenAt(CarbonInterface $now): bool
    {
        if ($this->starts_at !== null && $now->lessThan($this->starts_at)) {
            return false;
        }

        return ! ($this->ends_at !== null && $now->greaterThan($this->ends_at));
    }

    // === Validation (called by the admin form) ===

    /**
     * Is this custom HTML usable? Returns a TRANSLATION KEY describing what is
     * wrong, or null when the block is fine (including when it is empty — a
     * merchant who wrote no custom HTML gets the designed card instead).
     *
     * The rule is about `{{button}}`: exactly one, in TEXT position. A block with
     * none cannot be accepted by the shopper; a block with two would grow a
     * second control wired to the same charge. Counting after strip_tags is what
     * makes "in text position" checkable — a token hidden inside an attribute
     * would never be rendered as a button, and must not pass for one.
     */
    public static function validateCustomHtml(?string $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $clean = SafeHtml::clean($html);

        if ($clean === null) {
            return self::ERROR_BUTTON_REQUIRED;
        }

        return substr_count(strip_tags($clean), self::TOKEN_BUTTON) === 1
            ? null
            : self::ERROR_BUTTON_REQUIRED;
    }

    // === Private guards ===

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function trimmedOrNull(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /**
     * Keep only the values that are real cases of $enum, deduped, in order.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private function enumValues(mixed $raw, string $enum): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = (string) $value;

            if ($enum::tryFrom($value) !== null && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Platform product ids, as STRINGS. Never cast to int: a WooCommerce id is
     * numeric but a Shopify one is not necessarily, and the plan column these are
     * compared against is a string too.
     *
     * Deduped by VALUE and not by array key — a key of "2666" becomes the integer
     * 2666 the moment PHP stores it, and the comparison downstream is strict, so
     * a whole product filter would silently match nothing.
     *
     * @return list<string>
     */
    private function productIds(mixed $raw): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) >= self::MAX_AUDIENCE_PRODUCTS) {
                break;
            }
        }

        return $out;
    }
}
