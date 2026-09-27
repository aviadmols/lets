<?php

namespace App\Filament\Pages;

use App\Domain\Auth\TwoFactor\TwoFactorAuthenticator;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

/**
 * Account → Security: turn on sign-in with an authenticator app (Google
 * Authenticator, Microsoft Authenticator, Authy, 1Password…).
 *
 * Reached from the user menu, never the sidebar, and never tenant-scoped: the
 * setting belongs to the PERSON, not the shop, and a platform admin must reach
 * it before entering any shop (for them it is mandatory — see
 * RequireTwoFactorEnrollment).
 *
 * Every change asks for a live code first, so a session left open on someone's
 * laptop cannot turn the second factor off or read fresh recovery codes. The
 * secret being enrolled waits in the SESSION until confirmed, never in a
 * Livewire property (which would round-trip through the browser's snapshot).
 */
class TwoFactorSecurity extends Page implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    // === CONSTANTS ===
    /** Code-checked actions per minute — a left-open session cannot brute-force them. */
    public const MAX_CODE_ATTEMPTS = 5;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.two-factor-security';

    protected static ?string $slug = 'account/security';

    protected static bool $shouldRegisterNavigation = false;

    /** The secret shown in the QR while the user has not yet confirmed a code. */
    public const SETUP_SECRET_KEY = 'auth.two_factor_setup_secret';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Plain recovery codes — present only right after they were made, shown once. */
    public array $freshRecoveryCodes = [];

    public bool $settingUp = false;

    public function mount(): void
    {
        $this->settingUp = Session::has(self::SETUP_SECRET_KEY) && ! $this->user()->hasTwoFactorEnabled();
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('two_factor.page.title');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label(__('two_factor.page.code_label'))
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 7])
                    ->required(),
            ])
            ->statePath('data');
    }

    public function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public function isMandatory(): bool
    {
        return $this->user()->mustUseTwoFactor();
    }

    public function isEnabled(): bool
    {
        return $this->user()->hasTwoFactorEnabled();
    }

    public function remainingRecoveryCodes(): int
    {
        return app(TwoFactorAuthenticator::class)->remainingRecoveryCodes($this->user());
    }

    public function setupSecret(): ?string
    {
        $secret = Session::get(self::SETUP_SECRET_KEY);

        return is_string($secret) ? $secret : null;
    }

    public function qrCodeSvg(): ?string
    {
        $secret = $this->setupSecret();

        return $secret === null ? null : app(TwoFactorAuthenticator::class)->qrCodeSvg($this->user(), $secret);
    }

    public function startSetup(TwoFactorAuthenticator $twoFactor): void
    {
        if ($this->isEnabled()) {
            return;
        }

        Session::put(self::SETUP_SECRET_KEY, $twoFactor->generateSecret());
        $this->settingUp = true;
        $this->freshRecoveryCodes = [];
        $this->form->fill();
    }

    public function cancelSetup(): void
    {
        Session::forget(self::SETUP_SECRET_KEY);
        $this->settingUp = false;
        $this->form->fill();
    }

    public function confirmSetup(TwoFactorAuthenticator $twoFactor): void
    {
        $secret = $this->setupSecret();
        if ($secret === null || $this->isEnabled()) {
            $this->cancelSetup();

            return;
        }

        $codes = $twoFactor->enable($this->user(), $secret, $this->code());
        if ($codes === null) {
            $this->failCode();
        }

        Session::forget(self::SETUP_SECRET_KEY);
        $this->settingUp = false;
        $this->freshRecoveryCodes = $codes;
        $this->form->fill();

        Notification::make()->title(__('two_factor.page.enabled_toast'))->success()->send();
    }

    public function regenerateRecoveryCodes(TwoFactorAuthenticator $twoFactor): void
    {
        if (! $twoFactor->verifyCode($this->user(), $this->code())) {
            $this->failCode();
        }

        $this->freshRecoveryCodes = $twoFactor->regenerateRecoveryCodes($this->user());
        $this->form->fill();
    }

    public function disable(TwoFactorAuthenticator $twoFactor): void
    {
        // Mandatory for platform admins: they may re-enrol a new phone (via the
        // auth:two-factor-reset command), never switch the factor off.
        if ($this->isMandatory()) {
            return;
        }

        if (! $twoFactor->verifyCode($this->user(), $this->code())) {
            $this->failCode();
        }

        $twoFactor->disable($this->user());
        $this->freshRecoveryCodes = [];
        $this->form->fill();

        Notification::make()->title(__('two_factor.page.disabled_toast'))->success()->send();
    }

    private function code(): string
    {
        try {
            $this->rateLimit(self::MAX_CODE_ATTEMPTS, method: 'code');
        } catch (TooManyRequestsException $exception) {
            throw ValidationException::withMessages([
                'data.code' => __('two_factor.challenge.throttled', ['seconds' => $exception->secondsUntilAvailable]),
            ]);
        }

        // Codes shown after the last change are dropped from the page state on
        // the next action, so they do not ride along in every later request.
        $this->freshRecoveryCodes = [];

        return (string) ($this->form->getState()['code'] ?? '');
    }

    private function failCode(): never
    {
        throw ValidationException::withMessages([
            'data.code' => __('two_factor.page.invalid_code'),
        ]);
    }
}
