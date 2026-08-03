<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The shop's SMS provider account — one row per shop, brought by the merchant.
 *
 * We never send on a shared account: an SMS carries the merchant's sender name and
 * their reputation, and their spend. A shop with no complete configuration simply
 * has no SMS channel — SmsSenderFactory returns null and every caller no-ops,
 * the same default-off contract InvoiceProviderFactory::for() keeps.
 *
 * The token is encrypted at rest and hidden from serialisation. It is never logged.
 */
class MerchantSmsSettings extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'merchant_sms_settings';

    /** 019 (019sms.co.il) — the Israeli gateway the merchants asked for. */
    public const PROVIDER_019 = '019';
    public const PROVIDERS = [self::PROVIDER_019];

    /** 019 caps the `source` (sender) at 11 characters, letters and digits only. */
    public const MAX_SENDER = 11;
    private const SENDER_PATTERN = '/^[A-Za-z0-9]{1,11}$/';

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_token' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            ['enabled' => false, 'provider' => self::PROVIDER_019],
        );
    }

    /*
     * The guarded reads are named AROUND their columns — providerKey() not
     * provider(), accountName() not username(), senderName() not sender().
     * Eloquent resolves $model->foo by first asking whether a foo() method
     * exists and, if it does, treating it as a relation; an accessor sharing a
     * column's name therefore makes that column unreadable.
     */

    public function providerKey(): string
    {
        $value = is_string($this->provider) ? $this->provider : '';

        return in_array($value, self::PROVIDERS, true) ? $value : self::PROVIDER_019;
    }

    public function accountName(): ?string
    {
        return $this->trimmedOrNull($this->getAttribute('username'), 100);
    }

    public function apiToken(): ?string
    {
        return $this->trimmedOrNull($this->api_token, 500);
    }

    /**
     * The sender shown on the shopper's phone. 019 rejects anything longer than
     * 11 characters or carrying punctuation, so we reject it here rather than
     * discovering it as a failed send.
     */
    public function senderName(): ?string
    {
        $value = $this->getAttribute('sender');
        $value = is_string($value) ? trim($value) : '';

        return preg_match(self::SENDER_PATTERN, $value) === 1 ? $value : null;
    }

    /** Can this shop actually send? All three fields, or nothing. */
    public function usable(): bool
    {
        return (bool) $this->enabled
            && $this->accountName() !== null
            && $this->apiToken() !== null
            && $this->senderName() !== null;
    }

    private function trimmedOrNull(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
