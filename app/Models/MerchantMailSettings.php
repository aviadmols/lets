<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\DefaultEmailTemplates;
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

    /**
     * The personal area's sign-in code. Unlike the six above it is not driven by
     * a plan — it is sent to whoever asked to sign in, so its var bag is its own.
     */
    public const TEMPLATE_LOGIN_CODE = 'login_code';

    /**
     * Sent when a post-purchase add-on window closes on an order the shopper
     * ACTUALLY added to. Its {items_table} arrives pre-rendered as one scalar —
     * see OrderUpdatedNotifier for why a collection cannot be passed.
     */
    public const TEMPLATE_ORDER_UPDATED = 'order_updated';

    /**
     * "Your card needs updating" — carries the durable card-update link.
     *
     * Transactional, not marketing: the customer asked for a subscription and
     * this is the arrangement failing without a live card. No unsubscribe line,
     * no "פרסומת" tag.
     */
    public const TEMPLATE_CARD_UPDATE = 'card_update';

    /**
     * "Start your subscription" — carries the activation link of a paid subscription that
     * waits for its customer (PlanActivation). Transactional.
     */
    public const TEMPLATE_PLAN_ACTIVATION = 'plan_activation';

    /**
     * Placeholders the WhatsApp card-update line accepts.
     *
     * Substituted with strtr() and NOTHING else — this is merchant-typed text, and
     * a template engine let loose on merchant input is remote code execution. Same
     * rule as every email body in this model.
     *
     * @var list<string>
     */
    public const WHATSAPP_PLACEHOLDERS = ['{shop}', '{url}', '{customer}'];

    /** A WhatsApp message is one or two lines, not a document. */
    public const MAX_WHATSAPP_LENGTH = 700;

    /** Canonical template keys (drive the migration columns + the settings UI). */
    public const TEMPLATES = [
        self::TEMPLATE_FIRST_PAYMENT_WELCOME,
        self::TEMPLATE_RECURRING_PAYMENT_REMINDER,
        self::TEMPLATE_MANUAL_RECURRING_PAYMENT,
        self::TEMPLATE_CHARGE_SUCCEEDED,
        self::TEMPLATE_CHARGE_FAILED,
        self::TEMPLATE_PLAN_CANCELLED,
        self::TEMPLATE_LOGIN_CODE,
        self::TEMPLATE_ORDER_UPDATED,
        self::TEMPLATE_CARD_UPDATE,
        self::TEMPLATE_PLAN_ACTIVATION,
    ];

    /**
     * The templates a merchant may switch off ONE BY ONE on the per-email list.
     *
     * TEMPLATES minus the two that already have an owner elsewhere, because one
     * email with two switches is how two screens come to disagree about it:
     *
     *   - the SIGN-IN CODE is governed by Settings → Customer area
     *     (`login_code_enabled` on MerchantPortalAppearance);
     *   - the RENEWAL REMINDER is governed by its own `reminder_enabled` column,
     *     which has a section of its own on this screen (with the offset) and is
     *     read by the scheduler's cheap pre-filter.
     *
     * The MASTER tap still covers both — "send no email at all" has to mean what
     * it says — and sendsTemplate() routes each of them to its real owner.
     *
     * @var list<string>
     */
    public const SWITCHABLE_TEMPLATES = [
        self::TEMPLATE_FIRST_PAYMENT_WELCOME,
        self::TEMPLATE_MANUAL_RECURRING_PAYMENT,
        self::TEMPLATE_CHARGE_SUCCEEDED,
        self::TEMPLATE_CHARGE_FAILED,
        self::TEMPLATE_PLAN_CANCELLED,
        self::TEMPLATE_ORDER_UPDATED,
        self::TEMPLATE_CARD_UPDATE,
        self::TEMPLATE_PLAN_ACTIVATION,
    ];

    /**
     * The gate key for a MARKETING campaign, which is not a template at all.
     *
     * Campaigns have no row on the per-email list — a merchant who does not want
     * one simply does not press Send. But the master tap must stop them, or "no
     * emails at all" would be a promise this app breaks the moment somebody
     * schedules a newsletter.
     */
    public const CHANNEL_CAMPAIGN = 'campaign';

    /** Spec defaults applied when a shop's row is first materialised. */
    public const DEFAULT_REMINDER_OFFSET_HOURS = 72;

    /**
     * The master tap. TRUE by default — a shop that never opens the screen sends
     * exactly what it sends today.
     */
    public const DEFAULT_EMAILS_ENABLED = true;

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
            'emails_enabled' => 'boolean',
            'emails_paused_at' => 'datetime',
            'disabled_templates' => 'array',
            'override_env_smtp' => 'boolean',
            'smtp_port' => 'integer',
            // SMTP password is a credential — encrypt at rest (APP_KEY cast; it is
            // a per-row secret, not a cross-shop one, so APP_KEY is fine here).
            'smtp_password' => 'encrypted',
        ];
    }

    /**
     * The WhatsApp line for a card-update link, as the merchant wrote it — or the
     * shipped default when they never touched it.
     *
     * Null means "use ours" rather than "send nothing", so the wording keeps
     * improving for every shop that has not written their own.
     */
    public function cardUpdateWhatsappTemplate(): string
    {
        $custom = trim((string) ($this->card_update_whatsapp ?? ''));

        return $custom !== '' ? $custom : (string) __('card_update.share.default_message');
    }

    /**
     * The message with the placeholders filled in.
     *
     * strtr() and NOTHING else. This is merchant-typed text, and every other
     * merchant-edited body in this model obeys the same rule for the same reason:
     * handing merchant input to a template engine is remote code execution.
     * Unknown tokens are left standing rather than blanked, so a typo shows itself
     * instead of silently deleting half a sentence.
     *
     * @param  array<string, string>  $values  placeholder (with braces) => value
     */
    public function renderCardUpdateWhatsapp(array $values): string
    {
        $clean = [];

        foreach (self::WHATSAPP_PLACEHOLDERS as $token) {
            $clean[$token] = (string) ($values[$token] ?? '');
        }

        return mb_substr(trim(strtr($this->cardUpdateWhatsappTemplate(), $clean)), 0, self::MAX_WHATSAPP_LENGTH);
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
                'emails_enabled' => self::DEFAULT_EMAILS_ENABLED,
                'disabled_templates' => [],
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

    // === Which emails go out (the master tap + the per-email list) ===

    /**
     * The master tap. A shop with no row, or a row from before this column
     * existed, sends — the behaviour every shop had before the switch.
     */
    public function emailsEnabled(): bool
    {
        return (bool) ($this->emails_enabled ?? self::DEFAULT_EMAILS_ENABLED);
    }

    /**
     * The templates this shop has switched OFF individually.
     *
     * Sanitised against the canonical catalogue, so a stale key left behind by a
     * renamed template can never mute an email that now answers to a different
     * name.
     *
     * @return list<string>
     */
    public function disabledTemplates(): array
    {
        $stored = $this->disabled_templates;

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? $v : '', $stored),
            static fn (string $v): bool => in_array($v, self::SWITCHABLE_TEMPLATES, true),
        ));
    }

    /**
     * MAY THIS SHOP SEND THIS EMAIL? The single authority, read through MailPolicy
     * by every send site in the app.
     *
     * Two levels, in order: the master tap, then the per-email list. The reminder
     * is answered by its OWN column rather than the list — `reminder_enabled`
     * predates this screen and is read by the scheduler's cheap pre-filter, so
     * routing it through the list as well would give one email two switches and
     * let two screens disagree about it.
     *
     * It knows nothing about the accounting document's email, and that is the
     * point: that one is sent by the invoicing provider on the merchant's separate
     * invoicing setting. A tax receipt is not a notification, and this tap must
     * not be able to stop one by accident.
     */
    public function sendsTemplate(string $template): bool
    {
        if (! $this->emailsEnabled()) {
            return false;
        }

        if ($template === self::TEMPLATE_RECURRING_PAYMENT_REMINDER) {
            return (bool) ($this->reminder_enabled ?? true);
        }

        // A template with no row on the list (the sign-in code, a campaign, a
        // template added next release) passes the second gate. Fail OPEN here on
        // purpose: the master tap above is the wall, and a new email must arrive
        // visible rather than silently muted for every shop that saved this
        // screen months ago.
        return ! in_array($template, $this->disabledTemplates(), true);
    }

    /**
     * The switchable templates that are currently ON — the shape the settings
     * screen's checkbox list binds to (it states what IS sent, which is the
     * question a merchant is actually asking; the column stores the inverse).
     *
     * @return list<string>
     */
    public function enabledTemplates(): array
    {
        $off = $this->disabledTemplates();

        return array_values(array_filter(
            self::SWITCHABLE_TEMPLATES,
            static fn (string $template): bool => ! in_array($template, $off, true),
        ));
    }

    /**
     * The language the shop's CUSTOMERS read their email in — not the merchant's
     * admin language. Guarded against an unknown value because it selects a
     * translation file: an unrecognised locale would ship raw dotted keys.
     */
    public function emailLocale(): string
    {
        $value = is_string($this->email_locale) ? trim($this->email_locale) : '';

        return in_array($value, DefaultEmailTemplates::LOCALES, true)
            ? $value
            : DefaultEmailTemplates::LOCALE_HE;
    }
}
