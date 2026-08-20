<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE thing an account offer sells. An offer carries several, in order.
 *
 * Every merchant-set value is read back through a GUARD, never raw — the same
 * discipline AccountOffer and MerchantPortalAppearance follow. Here the stakes
 * are the sharpest in the feature: an unreadable `mode` is a subscription
 * cancelled that should have been kept, and an unreadable `fulfilment` is a card
 * charged today that the shopper expected to pay for next month.
 *
 * TWO KINDS.
 *
 *   subscription — points at a ProductSubscriptionPlan template. The template
 *                  owns the price and the cadence; this row owns only whether
 *                  accepting ADDs the new subscription beside the shopper's
 *                  current one or REPLACEs it, and when a replacement bites.
 *
 *   one_time     — points at a plain catalog product. Price is the catalog's
 *                  times `quantity`, there is no cadence, and `fulfilment`
 *                  decides between charging the saved card now and appending the
 *                  line to the source subscription's next order.
 *
 * The invariants a caller may rely on without re-checking:
 *   - a one_time target is ALWAYS `add` and ALWAYS reports a null timing;
 *   - a subscription target ALWAYS reports a null fulfilment;
 *   - chargesNow() is the ONE money question, answered for both kinds.
 */
class AccountOfferTarget extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'account_offer_targets';

    /** What the shopper ends up with. */
    public const KIND_SUBSCRIPTION = 'subscription';

    public const KIND_ONE_TIME = 'one_time';

    public const KINDS = [self::KIND_SUBSCRIPTION, self::KIND_ONE_TIME];

    /**
     * ADD stands the new subscription BESIDE the one the shopper already has.
     * REPLACE ends theirs in favour of it — which is the switch offer, and the
     * reason `replace_timing` exists at all. A one_time product is never either:
     * buying a mug does not end a subscription, so it is always an ADD.
     */
    public const MODE_ADD = 'add';

    public const MODE_REPLACE = 'replace';

    public const MODES = [self::MODE_ADD, self::MODE_REPLACE];

    /**
     * WHEN a replacement takes effect. IMMEDIATE charges the saved card on the
     * click and ends the old plan once the money lands. PERIOD_END schedules the
     * new plan's first charge for the day the old one would have renewed and ends
     * the old plan now — no proration, no double charge, no gap.
     */
    public const TIMING_IMMEDIATE = 'immediate';

    public const TIMING_PERIOD_END = 'period_end';

    public const TIMINGS = [self::TIMING_IMMEDIATE, self::TIMING_PERIOD_END];

    /**
     * HOW a one-time product reaches the shopper.
     *
     * IMMEDIATE charges the saved card on the click and creates a paid store
     * order for it. NEXT_ORDER takes no money at all: the line is appended to the
     * source subscription's next-order override and is charged, shipped and
     * invoiced with that cycle — which is why it stays available on a shop whose
     * live charging is switched off.
     */
    public const FULFILMENT_IMMEDIATE = 'immediate';

    public const FULFILMENT_NEXT_ORDER = 'next_order';

    public const FULFILMENTS = [self::FULFILMENT_IMMEDIATE, self::FULFILMENT_NEXT_ORDER];

    /** The merchant's slug for a target: lower-case, digits, underscores. */
    public const TOKEN_KEY_PATTERN = '/^[a-z0-9_]{1,32}$/';

    public const MAX_TOKEN_KEY = 32;

    public const MAX_BUTTON_TEXT = 60;

    /** Nobody adds twenty of anything from an offer card by accident. */
    public const MAX_QUANTITY = 20;

    /** id + shop_id are never mass-assignable; shop_id is auto-stamped. */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
        ];
    }

    // === Relations ===

    public function offer(): BelongsTo
    {
        return $this->belongsTo(AccountOffer::class, 'offer_id');
    }

    /** The subscription template this target sells. Null for a one_time target. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductSubscriptionPlan::class, 'product_subscription_plan_id');
    }

    // === Guarded reads ===

    public function kind(): string
    {
        return $this->oneOf($this->raw('kind'), self::KINDS, self::KIND_SUBSCRIPTION);
    }

    public function isSubscription(): bool
    {
        return $this->kind() === self::KIND_SUBSCRIPTION;
    }

    public function isOneTime(): bool
    {
        return $this->kind() === self::KIND_ONE_TIME;
    }

    /** A one_time product never replaces a subscription — it is always an ADD. */
    public function mode(): string
    {
        return $this->isOneTime()
            ? self::MODE_ADD
            : $this->oneOf($this->raw('mode'), self::MODES, self::MODE_ADD);
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
     * An ADD ends no period — so the payload carries null rather than a value the
     * shopper's card would imply. Use chargesNow() for the question the money
     * actually asks.
     */
    public function timing(): ?string
    {
        if ($this->isOneTime() || ! $this->isReplace()) {
            return null;
        }

        return $this->oneOf($this->raw('replace_timing'), self::TIMINGS, self::TIMING_IMMEDIATE);
    }

    /** How a one-time product is delivered, or null for a subscription target. */
    public function fulfilment(): ?string
    {
        if (! $this->isOneTime()) {
            return null;
        }

        return $this->oneOf($this->raw('fulfilment'), self::FULFILMENTS, self::FULFILMENT_IMMEDIATE);
    }

    /**
     * Does accepting this target charge the saved card RIGHT NOW?
     *
     * The one money question, asked identically of both kinds — and the input to
     * every wall that hides a target on a shop whose charging is paused, and to
     * the disclosure the shopper reads before they click. Everything unreadable
     * falls back to "now" rather than to a silent delay: a target that charges
     * when it said it would not is the failure that costs a chargeback, while one
     * that is hidden on a paused shop costs a sale the shop was not taking anyway.
     */
    public function chargesNow(): bool
    {
        return $this->isOneTime()
            ? $this->fulfilment() === self::FULFILMENT_IMMEDIATE
            : $this->timing() !== self::TIMING_PERIOD_END;
    }

    /** One subscription is one subscription; only a one_time target has a count. */
    public function quantity(): int
    {
        if (! $this->isOneTime()) {
            return 1;
        }

        return max(1, min(self::MAX_QUANTITY, (int) $this->raw('quantity')));
    }

    public function position(): int
    {
        return max(1, (int) $this->raw('position'));
    }

    /** The merchant's slug, or null when they did not set a readable one. */
    public function tokenKey(): ?string
    {
        $value = is_string($this->raw('token_key')) ? trim(mb_strtolower((string) $this->raw('token_key'))) : '';

        return $value !== '' && preg_match(self::TOKEN_KEY_PATTERN, $value) === 1 ? $value : null;
    }

    public function buttonText(): ?string
    {
        $value = is_string($this->raw('button_text')) ? trim((string) $this->raw('button_text')) : '';

        return $value !== '' ? mb_substr($value, 0, self::MAX_BUTTON_TEXT) : null;
    }

    public function externalProductId(): ?string
    {
        $value = trim((string) ($this->raw('external_product_id') ?? ''));

        return $value !== '' ? $value : null;
    }

    public function externalVariantId(): ?string
    {
        $value = trim((string) ($this->raw('external_variant_id') ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * The identifier the payload carries and the browser posts back.
     *
     * STABLE, and in the merchant's own vocabulary where they gave one: their
     * slug, else the product id they addressed it by, else its position. It is
     * never the database id — the row is rewritten every time the merchant edits
     * the offer's target list, and a key that changes under a page the shopper
     * already loaded is a click that lands on the wrong thing.
     */
    public function stableKey(): string
    {
        return $this->tokenKey()
            ?? $this->externalProductId()
            ?? (string) $this->position();
    }

    /**
     * The three ways a button token may address this target, plus the key it
     * answers with. Shared by the presenter (substitution) and the admin form
     * (validation), so a token that validates is a token that renders.
     *
     * @return array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}
     */
    public function descriptor(): array
    {
        return self::descriptorFor($this->tokenKey(), $this->externalProductId(), $this->position());
    }

    /**
     * A descriptor for a target that is not saved yet — the admin form validates
     * custom HTML against rows the merchant is still typing.
     *
     * @return array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}
     */
    public static function descriptorFor(?string $tokenKey, ?string $externalProductId, int $position): array
    {
        $tokenKey = is_string($tokenKey) && preg_match(self::TOKEN_KEY_PATTERN, trim(mb_strtolower($tokenKey))) === 1
            ? trim(mb_strtolower($tokenKey))
            : null;
        $externalProductId = trim((string) $externalProductId);
        $externalProductId = $externalProductId !== '' ? $externalProductId : null;
        $position = max(1, $position);

        return [
            'token_key' => $tokenKey,
            'external_product_id' => $externalProductId,
            'position' => $position,
            'stable_key' => $tokenKey ?? $externalProductId ?? (string) $position,
        ];
    }

    // === Private guards ===

    /**
     * The RAW column value, or null when the row simply does not carry it.
     *
     * Read straight off the attribute bag rather than through `$this->column`,
     * because a guard has to work on a half-built model too: the admin form
     * validates a target the merchant is still typing, and asking a model for an
     * attribute it has never been given is an error in Laravel, not a null. A
     * guard that throws is not a guard.
     */
    private function raw(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
