<?php

namespace App\Filament\Pages;

use App\Domain\Mail\PlatformSenderDomain;
use App\Domain\Mail\SenderDomains;
use App\Mail\Support\CampaignMailer;
use App\Mail\Support\MailTransport;
use App\Models\PlatformMailSettings;
use App\Models\Shop;
use App\Support\Ui\PanelAccess;
use Filament\Actions\Action as HeaderAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Platform → Email delivery. THE OWNER'S screen, never a merchant's.
 *
 * The sending account is the house's: one SendGrid account, one reputation, one
 * bill, and every shop that has not authenticated a domain of its own sends
 * through it. That arrangement used to live entirely in deploy variables, which
 * made rotating the key a deploy and made "is it actually working" a question
 * no screen could answer.
 *
 * THE ENV VAR STILL WORKS and is still the way to bring a fresh environment up;
 * a key saved here simply wins, because a form whose value is ignored is worse
 * than no form. The key is encrypted at rest and NEVER rendered back — an empty
 * field means "keep what is stored", the same discipline as the per-shop SMTP
 * password.
 *
 * The platform's own domain is on this screen for a reason that is easy to miss:
 * the fallback From must sit on a domain this account authenticated, or the
 * provider refuses the message — for EVERY shop without one of its own. So the
 * screen states that relationship rather than leaving the owner to discover it
 * from a bounce.
 */
class ManagePlatformMail extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string $view = 'filament.pages.platform-mail';

    protected static ?string $slug = 'platform/mail';

    protected static ?int $navigationSort = 20;

    /** The DNS panel for the platform's own domain. */
    public const DOMAIN_VIEW = 'filament.pages.partials.platform-domain';

    /** A saved key is never re-shown; blank on save means "keep what is stored". */
    public const SECRET_FIELDS = [
        'sendgrid_api_key',
        'ses_access_key_id',
        'ses_secret_access_key',
        'ses_smtp_username',
        'ses_smtp_password',
    ];

    /** @var array<string, mixed> the form state (statePath: data). */
    public array $data = [];

    /**
     * Per-record DNS verdicts from the last check on THIS page view. Not
     * persisted: a resolver answer is only true for the moment it was fetched.
     *
     * @var list<array<string, mixed>>
     */
    public array $domainRecords = [];

    /**
     * PLATFORM ADMINS ONLY, and never while entered into a shop — the account
     * being configured is the house's, and a screen that appeared inside a
     * merchant's context would read as theirs.
     */
    public static function canAccess(): bool
    {
        return PanelAccess::isPlatformAdmin() && ! PanelAccess::tenantBound();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('platform_mail.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('platform_mail.title');
    }

    public function mount(): void
    {
        $settings = PlatformMailSettings::current();

        $this->form->fill([
            'provider' => $settings->provider(),
            'ses_region' => $settings->sesRegion(),
            'sendgrid_api_key' => null, // never re-shown
            'ses_access_key_id' => null,
            'ses_secret_access_key' => null,
            'ses_smtp_username' => null,
            'ses_smtp_password' => null,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'subdomain' => $settings->subdomainOverride(),
            'domain' => $settings->domain,
        ]);
    }

    /**
     * The one action worth a header slot: a real send, through the real ladder.
     * Everything else on this screen is a claim; this is the proof.
     */
    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('sendTest')
                ->label(__('platform_mail.test.action'))
                ->icon('heroicon-o-paper-airplane')
                ->form([
                    TextInput::make('recipient')
                        ->label(__('platform_mail.test.field'))
                        ->email()
                        ->required()
                        ->default(fn (): ?string => auth()->user()?->email)
                        ->extraInputAttributes(['dir' => 'ltr']),
                ])
                ->action(fn (array $data) => $this->sendTest((string) $data['recipient'])),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                $this->accountSection(),
                $this->domainSection(),
            ]);
    }

    private function accountSection(): Section
    {
        return Section::make(__('platform_mail.account.heading'))
            ->description(__('platform_mail.account.intro'))
            ->schema([
                Placeholder::make('connection_state')
                    ->label(__('platform_mail.account.state'))
                    ->content(fn (): string => $this->connectionLine()),

                Radio::make('provider')
                    ->label(__('platform_mail.account.provider'))
                    ->options([
                        PlatformMailSettings::PROVIDER_SENDGRID => __('platform_mail.account.provider_sendgrid'),
                        PlatformMailSettings::PROVIDER_SES => __('platform_mail.account.provider_ses'),
                    ])
                    ->descriptions([
                        PlatformMailSettings::PROVIDER_SENDGRID => __('platform_mail.account.provider_sendgrid_help'),
                        PlatformMailSettings::PROVIDER_SES => __('platform_mail.account.provider_ses_help'),
                    ])
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('sendgrid_api_key')
                    ->label(__('platform_mail.account.key'))
                    ->helperText(fn (): string => $this->keyHint())
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('provider') !== PlatformMailSettings::PROVIDER_SES),

                // === Amazon SES ===
                // Two credentials, because AWS issues two. The API pair signs
                // the domain calls; the SMTP pair sends the mail. Sending with
                // the API pair fails with a message that names neither, so the
                // screen keeps them visibly apart.
                TextInput::make('ses_region')
                    ->label(__('platform_mail.account.ses_region'))
                    ->helperText(__('platform_mail.account.ses_region_help'))
                    ->placeholder('eu-central-1')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(32)
                    ->visible(fn (Get $get): bool => $get('provider') === PlatformMailSettings::PROVIDER_SES),

                TextInput::make('ses_access_key_id')
                    ->label(__('platform_mail.account.ses_key_id'))
                    ->helperText(__('platform_mail.account.ses_api_help'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('provider') === PlatformMailSettings::PROVIDER_SES),

                TextInput::make('ses_secret_access_key')
                    ->label(__('platform_mail.account.ses_secret'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('provider') === PlatformMailSettings::PROVIDER_SES),

                TextInput::make('ses_smtp_username')
                    ->label(__('platform_mail.account.ses_smtp_username'))
                    ->helperText(__('platform_mail.account.ses_smtp_help'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('provider') === PlatformMailSettings::PROVIDER_SES),

                TextInput::make('ses_smtp_password')
                    ->label(__('platform_mail.account.ses_smtp_password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('provider') === PlatformMailSettings::PROVIDER_SES),

                TextInput::make('from_address')
                    ->label(__('platform_mail.account.from_address'))
                    ->helperText(__('platform_mail.account.from_address_help'))
                    ->email()
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(190),

                TextInput::make('from_name')
                    ->label(__('platform_mail.account.from_name'))
                    ->maxLength(120),

                TextInput::make('subdomain')
                    ->label(__('platform_mail.account.subdomain'))
                    ->helperText(__('platform_mail.account.subdomain_help'))
                    ->placeholder(PlatformMailSettings::DEFAULT_SUBDOMAIN)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(63),
            ])
            ->columns(2);
    }

    private function domainSection(): Section
    {
        return Section::make(__('platform_mail.domain.heading'))
            ->description(__('platform_mail.domain.intro'))
            ->schema([
                TextInput::make('domain')
                    ->label(__('platform_mail.domain.domain'))
                    ->helperText(__('platform_mail.domain.domain_help'))
                    ->placeholder('lets.co.il')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->maxLength(253),

                ViewField::make('domain_state')
                    ->hiddenLabel()
                    ->view(self::DOMAIN_VIEW)
                    ->columnSpanFull(),
            ]);
    }

    // === Actions ===

    public function save(): void
    {
        $input = $this->form->getState();
        $settings = PlatformMailSettings::current();

        // A blank key keeps what is stored: the field is never re-shown, so an
        // owner saving an unrelated change must not blank the credential the
        // whole platform sends with.
        foreach (self::SECRET_FIELDS as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $settings->{$field} = $value;
            }
        }

        $provider = (string) ($input['provider'] ?? PlatformMailSettings::PROVIDER_SENDGRID);
        $settings->provider = in_array($provider, PlatformMailSettings::PROVIDERS, true)
            ? $provider
            : PlatformMailSettings::PROVIDER_SENDGRID;
        $settings->ses_region = $this->blankToNull($input['ses_region'] ?? null);

        $settings->from_address = $this->blankToNull($input['from_address'] ?? null);
        $settings->from_name = $this->blankToNull($input['from_name'] ?? null);
        $settings->subdomain = $this->blankToNull($input['subdomain'] ?? null);
        $settings->save();

        $this->mount(); // re-mask the key
        Notification::make()->success()->title(__('platform_mail.saved'))->send();
    }

    public function requestDomain(): void
    {
        $result = app(PlatformSenderDomain::class)->request((string) ($this->form->getState()['domain'] ?? ''));

        $this->domainRecords = [];
        $this->report($result, __('platform_mail.domain.requested'));
    }

    public function checkDomain(): void
    {
        $result = app(PlatformSenderDomain::class)->check();

        $this->domainRecords = $result['records'] ?? [];
        $this->report($result, __('platform_mail.domain.verified_now'));
    }

    /**
     * A real send, through the real ladder, to the owner's own address.
     *
     * It is sent AS A SHOP deliberately — MailTransport is what production uses,
     * so a test that bypassed it would prove the key works and nothing about
     * whether a merchant's mail does. With no shops yet it falls back to the
     * platform mailer, which is the honest answer for an empty platform.
     */
    public function sendTest(string $recipient): void
    {
        // Which ladder — and WHOSE — this test will actually exercise, named
        // before sending so both toasts and both log lines can say it. The
        // lowest-id shop's ladder is not necessarily the platform account: a
        // shop with its own SMTP override would make this button prove nothing
        // about the SES/SendGrid key the owner is configuring, and the green
        // toast must not hide that.
        $shop = Shop::query()->orderBy('id')->first();
        $chosen = $shop instanceof Shop ? MailTransport::for($shop) : null;
        $relay = $chosen !== null
            ? (string) ($chosen['config']['host'] ?? config('mail.default'))
            : (string) config('mail.default');
        $as = $shop instanceof Shop
            ? __('platform_mail.test.as_shop', ['name' => (string) $shop->name, 'relay' => $relay])
            : __('platform_mail.test.as_platform', ['relay' => $relay]);

        try {
            $mailer = $shop instanceof Shop ? CampaignMailer::for($shop) : Mail::mailer();

            $sent = $mailer->send([], [], function ($message) use ($recipient): void {
                // A PLAIN STRING. Illuminate\Mail\Message::to() builds the
                // Symfony Address itself, so handing it a Mailables\Address
                // makes it call `new Address($anAddressObject)` and die on the
                // type — which is a crash at send time, not at build time, so
                // only a real send catches it.
                $message->to($recipient)
                    ->subject(__('platform_mail.test.subject'))
                    ->html(e(__('platform_mail.test.body')));
            });

            Log::info('platform_mail.test.sent', [
                'shop_id' => $shop?->getKey(),
                'relay' => $relay,
                'message_id' => $sent?->getMessageId(),
            ]);

            Notification::make()
                ->success()
                ->title(__('platform_mail.test.sent', ['email' => $recipient]))
                ->body($as)
                ->send();
        } catch (Throwable $e) {
            // The provider's own words: "the From is not a verified sender" is
            // the whole diagnosis, and hiding it behind "sending failed" would
            // send the owner hunting through logs they may not have. It is ALSO
            // logged: the owner's screen disappears; the trace must not.
            Log::error('platform_mail.test.failed', [
                'shop_id' => $shop?->getKey(),
                'relay' => $relay,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title(__('platform_mail.test.failed'))
                ->body(mb_substr($e->getMessage(), 0, 300))
                ->persistent()
                ->send();
        }
    }

    // === The panel reads these ===

    public function settings(): PlatformMailSettings
    {
        return PlatformMailSettings::current();
    }

    /**
     * The records to show, each with whether it resolves RIGHT NOW — a blank
     * verdict rather than a tick this page view did not earn.
     *
     * @return list<array<string, mixed>>
     */
    public function domainRecordRows(): array
    {
        if ($this->domainRecords !== []) {
            return $this->domainRecords;
        }

        return array_map(
            static fn (array $record): array => $record + ['resolved' => null],
            $this->settings()->dnsRecords(),
        );
    }

    // === Internals ===

    private function connectionLine(): string
    {
        $settings = $this->settings();

        if (! $settings->isConnected()) {
            return __('platform_mail.account.state_off');
        }

        return $settings->keyIsStored()
            ? __('platform_mail.account.state_on_saved')
            : __('platform_mail.account.state_on_env');
    }

    private function keyHint(): string
    {
        return $this->settings()->keyIsStored()
            ? __('platform_mail.account.key_stored')
            : __('platform_mail.account.key_help');
    }

    /** @param array{ok: bool, reason: ?string} $result */
    private function report(array $result, string $success): void
    {
        if ($result['ok']) {
            Notification::make()->success()->title($success)->send();

            return;
        }

        Notification::make()
            ->warning()
            ->title(__('mail.sender.reason.'.($result['reason'] ?? SenderDomains::REASON_PROVIDER_UNREACHABLE)))
            ->persistent()
            ->send();
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
