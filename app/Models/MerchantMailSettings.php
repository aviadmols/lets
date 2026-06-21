<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-shop email engine settings. Exactly ONE row per shop, lazily created with
 * spec defaults on first read (current()). Tenant-scoped (shop_id +
 * BelongsToShop); shop_id is guarded so a raw create/update can never re-key the
 * row to another tenant.
 *
 * Each of the six notification templates carries a nullable {name}_subject +
 * {name}_body override. NULL = use the platform default (DefaultEmailTemplates);
 * non-null = merchant-edited HTML substituted ONLY via strtr() (NEVER Blade) —
 * the RCE-prevention law (CLAUDE.md). The optional SMTP override lets a merchant
 * send from their own mailbox; the password is encrypted at rest.
 *
 * Ported + multi-tenant-refactored from the reference engine's single-tenant
 * Settings/MailSettings.
 */
class MerchantMailSettings extends Model
{
    use BelongsToShop;

    // === CONSTANTS — the six notification templates ===
    protected $table = 'mail_settings';

    public const TEMPLATE_FIRST_PAYMENT_WELCOME = 'first_payment_welcome';
    public const TEMPLATE_RECURRING_PAYMENT_REMINDER = 'recurring_payment_reminder';
    public const TEMPLATE_MANUAL_RECURRING_PAYMENT = 'manual_recurring_payment';
    public const TEMPLATE_CHARGE_SUCCEEDED = 'charge_succeeded';
    public const TEMPLATE_CHARGE_FAILED = 'charge_failed';
    public const TEMPLATE_PLAN_CANCELLED = 'plan_cancelled';

    /** Canonical template keys (drive the migration columns + the settings UI). */
    public const TEMPLATES = [
        self::TEMPLATE_FIRST_PAYMENT_WELCOME,
        self::TEMPLATE_RECURRING_PAYMENT_REMINDER,
        self::TEMPLATE_MANUAL_RECURRING_PAYMENT,
        self::TEMPLATE_CHARGE_SUCCEEDED,
        self::TEMPLATE_CHARGE_FAILED,
        self::TEMPLATE_PLAN_CANCELLED,
    ];

    /** Spec defaults applied when a shop's row is first materialised. */
    public const DEFAULT_REMINDER_OFFSET_HOURS = 72;

    /**
     * shop_id is guarded (auto-stamped by BelongsToShop) so it can never be
     * mass-assigned to another tenant. Everything else is merchant-editable.
     */
    protected $guarded = ['shop_id'];

    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'reminder_enabled' => 'boolean',
            'reminder_offset_hours' => 'integer',
            'override_env_smtp' => 'boolean',
            'smtp_port' => 'integer',
            // SMTP password is a credential — encrypt at rest (APP_KEY cast; it is
            // a per-row secret, not a cross-shop one, so APP_KEY is fine here).
            'smtp_password' => 'encrypted',
        ];
    }

    /**
     * The settings row for the CURRENT tenant, created with spec defaults on
     * first read (so the in-memory model carries the values before a DB
     * round-trip). Tenant-safe: keyed strictly by Tenant::id(); the BelongsToShop
     * global scope plus the explicit shop_id key make a cross-shop read
     * impossible (shop A can never see or create shop B's row).
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'reminder_enabled' => true,
                'reminder_offset_hours' => self::DEFAULT_REMINDER_OFFSET_HOURS,
                'override_env_smtp' => false,
            ],
        );
    }

    /** The merchant's custom subject for a template, or null to use the default. */
    public function customSubject(string $template): ?string
    {
        $value = $this->{$template.'_subject'} ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** The merchant's custom HTML body for a template, or null to use the default. */
    public function customBody(string $template): ?string
    {
        $value = $this->{$template.'_body'} ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** True when the merchant has fully overridden a template (both subject + body). */
    public function hasCustomTemplate(string $template): bool
    {
        return $this->customBody($template) !== null;
    }
}
