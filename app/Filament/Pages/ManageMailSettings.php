<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Forms\Components\HtmlCodeEditor;
use App\Mail\Support\MailSettingsConfigurator;
use App\Models\MerchantMailSettings;
use App\Support\DefaultEmailTemplates;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Actions\Action as HeaderAction;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Settings → Email notifications (W9 Part A). A custom Filament Page (HasForms),
 * mounted strictly from MerchantMailSettings::current() — i.e. the row for
 * Tenant::current() only. It NEVER reads or writes another shop's row: current()
 * is keyed by Tenant::id() and the BelongsToShop global scope pins every query to
 * the bound shop; shop_id is guarded so a save can never re-key the row.
 *
 * Layout (docs/ux/50-settings.md "Mail"):
 *   - one collapsible Section per template (subject TextInput + body HtmlCodeEditor
 *     + the template's placeholder helper line + a Preview action + a "Restore
 *     default" action). A blank subject/body = "use the platform default".
 *   - a Reminders section (reminder_enabled + reminder_offset_hours).
 *   - a collapsed Advanced section: per-shop SMTP override + portal page URL.
 *   - a header "Send test email" action.
 *
 * Secrets-on-save: the SMTP password overwrites only when a new value is typed (an
 * empty field keeps the existing encrypted value) — mirrors the PayPlus-connection
 * "paste to replace" pattern. The merchant body is plain text the whole way;
 * substitution is strtr-only (TemplateRenderer) — Blade never touches it.
 */
class ManageMailSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static string $view = 'filament.pages.mail-settings';
    protected static ?string $slug = 'settings/mail';
    protected static ?int $navigationSort = 40;

    /** The six notification templates, in spec/UI order (drives the Sections). */
    public const TEMPLATES = MerchantMailSettings::TEMPLATES;

    /**
     * Reminder-offset choices (hours-before-charge), as value => label-key. Keeps
     * the offset a constrained Select, not free text.
     */
    public const REMINDER_OFFSETS = [24, 48, 72, 96, 168];

    /** SMTP encryption choices. */
    public const SMTP_ENCRYPTIONS = ['tls', 'ssl'];

    /** Form fields whose value is a secret → overwrite only when typed. */
    public const SECRET_FIELDS = ['smtp_password'];

    /** @var array<string, mixed> the form state (statePath: data). */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('mail.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('mail.title');
    }

    /**
     * Hydrate the form from the CURRENT tenant's settings row. Secrets stay blank
     * (masked) — an empty smtp_password on save means "keep existing".
     */
    public function mount(): void
    {
        $settings = MerchantMailSettings::current();

        $state = [
            'reminder_enabled' => (bool) $settings->reminder_enabled,
            'reminder_offset_hours' => (int) ($settings->reminder_offset_hours ?: MerchantMailSettings::DEFAULT_REMINDER_OFFSET_HOURS),
            'override_env_smtp' => (bool) $settings->override_env_smtp,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_encryption' => $settings->smtp_encryption,
            'smtp_username' => $settings->smtp_username,
            'smtp_password' => null, // never re-shown
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'portal_store_page_url' => $settings->portal_store_page_url,
        ];

        // Per-template subject/body overrides (null => default, shown as blank).
        foreach (self::TEMPLATES as $template) {
            $state[$template.'_subject'] = $settings->customSubject($template);
            $state[$template.'_body'] = $settings->customBody($template);
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                ...array_map(fn (string $t): Section => $this->templateSection($t), self::TEMPLATES),
                $this->remindersSection(),
                $this->advancedSection(),
            ]);
    }

    /** One collapsible Section per template (subject + body + placeholders + actions). */
    private function templateSection(string $template): Section
    {
        return Section::make(__('mail.template.'.$template))
            ->collapsible()
            ->collapsed()
            ->schema([
                TextInput::make($template.'_subject')
                    ->label(__('mail.field.subject'))
                    ->helperText(__('mail.field.subject_hint'))
                    ->placeholder(DefaultEmailTemplates::subject($template))
                    ->maxLength(255),

                // Placeholder helper line: the exact tokens this template supports.
                Placeholder::make($template.'_placeholders')
                    ->label(__('mail.field.placeholders'))
                    ->content(new HtmlString($this->placeholderChips($template))),

                HtmlCodeEditor::make($template.'_body')
                    ->label(__('mail.field.body'))
                    ->helperText(__('mail.field.body_hint')),

                Actions::make([
                    Action::make($template.'_preview')
                        ->label(__('mail.actions.preview'))
                        ->icon('heroicon-m-eye')
                        ->color('gray')
                        ->modalHeading(__('mail.preview.heading'))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('mail.preview.close'))
                        ->modalContent(fn (): View => $this->previewModal($template))
                        // Modal width so the iframe preview has room.
                        ->modalWidth('3xl'),

                    Action::make($template.'_restore')
                        ->label(__('mail.actions.reset'))
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('mail.reset.heading'))
                        ->modalDescription(__('mail.reset.body'))
                        ->action(fn () => $this->restoreDefault($template)),
                ]),
            ]);
    }

    /** Reminders behaviour (DispatchRemindersCommand reads these). */
    private function remindersSection(): Section
    {
        return Section::make(__('mail.reminder.heading'))
            ->schema([
                Toggle::make('reminder_enabled')
                    ->label(__('mail.reminder.enabled')),
                Select::make('reminder_offset_hours')
                    ->label(__('mail.reminder.offset_hours'))
                    ->helperText(__('mail.reminder.offset_help'))
                    ->options($this->reminderOffsetOptions())
                    ->native(false)
                    ->visible(fn (Get $get): bool => (bool) $get('reminder_enabled')),
            ])
            ->columns(2);
    }

    /** Advanced — per-shop SMTP override + the portal landing URL (collapsed). */
    private function advancedSection(): Section
    {
        return Section::make(__('mail.smtp.heading'))
            ->description(__('mail.smtp.intro'))
            ->collapsed()
            ->schema([
                Toggle::make('override_env_smtp')
                    ->label(__('mail.smtp.override'))
                    ->live()
                    ->columnSpanFull(),

                Grid::make()
                    ->visible(fn (Get $get): bool => (bool) $get('override_env_smtp'))
                    ->schema([
                        TextInput::make('smtp_host')->label(__('mail.smtp.host')),
                        TextInput::make('smtp_port')->label(__('mail.smtp.port'))->numeric(),
                        Select::make('smtp_encryption')
                            ->label(__('mail.smtp.encryption'))
                            ->options($this->encryptionOptions())
                            ->native(false),
                        TextInput::make('smtp_username')->label(__('mail.smtp.username'))->autocomplete(false),
                        TextInput::make('smtp_password')
                            ->label(__('mail.smtp.password'))
                            ->helperText(__('mail.smtp.password_hint'))
                            ->password()
                            ->revealable()
                            ->placeholder($this->secretMaskHint())
                            ->autocomplete(false),
                        TextInput::make('from_address')->label(__('mail.smtp.from_address'))->email(),
                        TextInput::make('from_name')->label(__('mail.smtp.from_name')),
                    ])
                    ->columns(2),

                TextInput::make('portal_store_page_url')
                    ->label(__('mail.portal.store_page_url'))
                    ->helperText(__('mail.portal.store_page_help'))
                    ->url()
                    ->columnSpanFull(),
            ]);
    }

    /** Header actions: "Send test email" (sends a sample to the current admin). */
    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('sendTest')
                ->label(__('mail.actions.send_test'))
                ->icon('heroicon-o-paper-airplane')
                ->form([
                    Select::make('template')
                        ->label(__('mail.test.template'))
                        ->options($this->templateOptions())
                        ->default(self::TEMPLATES[0])
                        ->native(false)
                        ->required(),
                    TextInput::make('recipient')
                        ->label(__('mail.test.recipient'))
                        ->email()
                        ->default(fn (): ?string => auth()->user()?->email)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->sendTest($data['template'], $data['recipient'])),
        ];
    }

    // === Actions ===

    /**
     * Persist the form into the CURRENT tenant's settings row. Tenant-safe:
     * MerchantMailSettings::current() is keyed by Tenant::id() and shop_id is
     * guarded, so the write can only ever touch this shop's row. The smtp_password
     * is written ONLY when a new value was typed (blank keeps the encrypted value).
     */
    public function save(): void
    {
        if (! Tenant::check()) {
            return;
        }

        $input = $this->form->getState();
        $settings = MerchantMailSettings::current();

        // Per-template subject/body: an empty string normalises to null (= default).
        foreach (self::TEMPLATES as $template) {
            $settings->{$template.'_subject'} = $this->blankToNull($input[$template.'_subject'] ?? null);
            $settings->{$template.'_body'} = $this->blankToNull($input[$template.'_body'] ?? null);
        }

        $settings->reminder_enabled = (bool) ($input['reminder_enabled'] ?? false);
        $settings->reminder_offset_hours = (int) ($input['reminder_offset_hours'] ?? MerchantMailSettings::DEFAULT_REMINDER_OFFSET_HOURS);

        $settings->override_env_smtp = (bool) ($input['override_env_smtp'] ?? false);
        $settings->smtp_host = $this->blankToNull($input['smtp_host'] ?? null);
        $settings->smtp_port = ($input['smtp_port'] ?? null) !== null && $input['smtp_port'] !== ''
            ? (int) $input['smtp_port']
            : null;
        $settings->smtp_encryption = $this->blankToNull($input['smtp_encryption'] ?? null);
        $settings->smtp_username = $this->blankToNull($input['smtp_username'] ?? null);
        $settings->from_address = $this->blankToNull($input['from_address'] ?? null);
        $settings->from_name = $this->blankToNull($input['from_name'] ?? null);
        $settings->portal_store_page_url = $this->blankToNull($input['portal_store_page_url'] ?? null);

        // Secret: overwrite only when a new value was typed (mirrors PayPlus creds).
        if (! empty($input['smtp_password'])) {
            $settings->smtp_password = $input['smtp_password'];
        }

        $settings->save();

        $this->mount(); // re-mask the secret field
        Notification::make()->title(__('mail.saved'))->success()->send();
    }

    /** "Restore default" — clear a template's custom subject + body so the default is used. */
    public function restoreDefault(string $template): void
    {
        if (! in_array($template, self::TEMPLATES, true) || ! Tenant::check()) {
            return;
        }

        $settings = MerchantMailSettings::current();
        $settings->{$template.'_subject'} = null;
        $settings->{$template.'_body'} = null;
        $settings->save();

        // Reflect the cleared values in the live form state.
        $this->data[$template.'_subject'] = null;
        $this->data[$template.'_body'] = null;

        Notification::make()->title(__('mail.reset_done'))->success()->send();
    }

    /**
     * Send a SAMPLE of the chosen template to a recipient. Uses the SAME strtr
     * preview path as production (EmailPreviewRenderer → TemplateRenderer) fed
     * sample vars, so the test mirrors the merchant's custom copy (or the default)
     * exactly. The per-shop SMTP override is applied first (no-op when off) so the
     * test goes out via the merchant's own mailbox when configured — tenant-safe:
     * keyed by Tenant::current(), never another shop.
     */
    public function sendTest(string $template, string $recipient): void
    {
        $shop = Tenant::current();
        if ($shop === null || ! in_array($template, self::TEMPLATES, true)) {
            return;
        }

        // Preview the merchant's UNSAVED edits when present, else the saved row.
        $settings = MerchantMailSettings::current();
        $preview = EmailPreviewRenderer::preview($template, $this->liveSettingsFor($template, $settings));

        try {
            MailSettingsConfigurator::apply($shop); // per-shop SMTP (no-op when off)

            $from = ($settings->override_env_smtp && $settings->from_address)
                ? new Address($settings->from_address, $settings->from_name ?: $shop->name)
                : null;

            Mail::html($preview['html'], function ($message) use ($recipient, $preview, $from): void {
                $message->to($recipient)->subject($preview['subject']);
                if ($from !== null) {
                    $message->from($from->address, $from->name);
                }
            });

            Notification::make()->title(__('mail.test.sent', ['email' => $recipient]))->success()->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('mail.test.failed', ['reason' => class_basename($e)]))
                ->danger()
                ->send();
        }
    }

    // === Preview ===

    /**
     * The Preview modal body: the rendered subject + an isolated iframe whose
     * srcdoc carries the htmlspecialchars-escaped preview HTML (so the email markup
     * is shown, never executed inside the admin origin). The merchant's CURRENT
     * (live, unsaved) custom copy is previewed when present, else the default.
     */
    public function previewModal(string $template): View
    {
        $settings = MerchantMailSettings::current();
        $preview = EmailPreviewRenderer::preview($template, $this->liveSettingsFor($template, $settings));

        return view('filament.pages.partials.mail-preview', [
            'subject' => $preview['subject'],
            'html' => $preview['html'],
            'isCustom' => $preview['is_custom'],
        ]);
    }

    // === Options / display helpers (no aggregation) ===

    /** @return array<int, string> hour-offset => human label */
    private function reminderOffsetOptions(): array
    {
        $options = [];
        foreach (self::REMINDER_OFFSETS as $hours) {
            $options[$hours] = __('mail.reminder.offset_option', ['hours' => $hours]);
        }

        return $options;
    }

    /** @return array<string, string> */
    private function encryptionOptions(): array
    {
        $options = [];
        foreach (self::SMTP_ENCRYPTIONS as $enc) {
            $options[$enc] = __('mail.smtp.encryption_'.$enc);
        }

        return $options;
    }

    /** @return array<string, string> template-key => human label (for the test picker) */
    private function templateOptions(): array
    {
        $options = [];
        foreach (self::TEMPLATES as $template) {
            $options[$template] = __('mail.template.'.$template);
        }

        return $options;
    }

    /** Render the template's placeholders as inline-CSS-free token chips. */
    private function placeholderChips(string $template): string
    {
        $chips = array_map(
            static fn (string $token): string => '<code class="rc-token">{'.e($token).'}</code>',
            DefaultEmailTemplates::placeholders($template),
        );

        return '<div class="rc-token-row">'.implode('', $chips).'</div>';
    }

    /** Show a "paste to replace" hint when an SMTP password is already stored. */
    private function secretMaskHint(): ?string
    {
        return MerchantMailSettings::current()->smtp_password
            ? __('mail.smtp.password_saved')
            : null;
    }

    /** Empty string → null (a blank field means "use the default" / "unset"). */
    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * A settings model carrying the merchant's LIVE (unsaved) subject/body for the
     * template being previewed/tested, so Preview + Send-test reflect the in-form
     * edits — not only what is persisted. Falls back to the saved values. This is
     * an in-memory clone; it is never saved.
     */
    private function liveSettingsFor(string $template, MerchantMailSettings $saved): MerchantMailSettings
    {
        $clone = clone $saved;
        $clone->{$template.'_subject'} = $this->blankToNull($this->data[$template.'_subject'] ?? $saved->{$template.'_subject'});
        $clone->{$template.'_body'} = $this->blankToNull($this->data[$template.'_body'] ?? $saved->{$template.'_body'});

        return $clone;
    }
}
