<?php

namespace App\Filament\Pages;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Settings → PayPlus Connection (docs/ux/50-settings.md §1, ARCHITECTURE.md
 * "Per-shop credentials"). The merchant pastes THEIR OWN PayPlus credentials,
 * stored encrypted on the current Shop via EncryptedCredentials. Secrets are
 * masked after save (we never re-display the full value; an empty secret field
 * on save means "keep the existing one"). A "Test connection" action probes the
 * gateway WITHOUT charging.
 *
 * Tenant-safe: writes only to Tenant::current(); never touches another shop.
 */
class ManagePayPlusConnection extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static string $view = 'filament.pages.payplus-connection';
    protected static ?string $slug = 'settings/payplus';
    protected static ?int $navigationSort = 10;

    /** Credential keys that are sensitive → masked after save, never re-shown. */
    public const SECRET_KEYS = ['api_key', 'secret_key', 'webhook_secret'];
    public const PLAIN_KEYS = ['terminal_uid', 'cashier_uid', 'payment_page_uid', 'base_url'];

    /** @var array<string, mixed> */
    public array $data = [];

    public ?string $connectionStatus = 'not_connected';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.section.payplus');
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.payplus.heading');
    }

    public function mount(): void
    {
        $shop = Tenant::current();
        $this->connectionStatus = $shop?->hasPayplusConnection() ? 'connected' : 'not_connected';

        // Pre-fill plain fields; secrets stay blank (masked) — a saved secret shows
        // the masked hint, an empty field keeps the stored value untouched.
        $bag = $shop?->payplus_credentials ?? [];
        $this->form->fill([
            'terminal_uid' => $bag['terminal_uid'] ?? null,
            'cashier_uid' => $bag['cashier_uid'] ?? null,
            'payment_page_uid' => $bag['payment_page_uid'] ?? null,
            'base_url' => $bag['base_url'] ?? config('payplus.base_url'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make(__('settings.payplus.heading'))
                    ->description(__('settings.payplus.intro'))
                    ->schema([
                        TextInput::make('api_key')
                            ->label(__('settings.payplus.api_key'))
                            ->password()
                            ->revealable()
                            ->placeholder($this->maskHint('api_key'))
                            ->autocomplete(false),
                        TextInput::make('secret_key')
                            ->label(__('settings.payplus.secret_key'))
                            ->password()
                            ->revealable()
                            ->placeholder($this->maskHint('secret_key'))
                            ->autocomplete(false),
                        TextInput::make('terminal_uid')
                            ->label(__('settings.payplus.terminal_uid')),
                        TextInput::make('cashier_uid')
                            ->label(__('settings.payplus.cashier_uid')),
                        TextInput::make('payment_page_uid')
                            ->label(__('settings.payplus.payment_page_uid')),
                        Select::make('base_url')
                            ->label(__('settings.payplus.base_url'))
                            ->options([
                                config('payplus.base_url') => 'Production',
                                config('payplus.base_url_sandbox', config('payplus.base_url')) => 'Sandbox',
                            ])
                            ->native(false),
                        TextInput::make('webhook_secret')
                            ->label(__('settings.payplus.webhook_secret'))
                            ->password()
                            ->revealable()
                            ->placeholder($this->maskHint('webhook_secret'))
                            ->autocomplete(false),
                    ])
                    ->columns(2),
            ]);
    }

    /** Show a "saved — paste to replace" hint when a secret already exists. */
    public function maskHint(string $key): ?string
    {
        $bag = Tenant::current()?->payplus_credentials ?? [];

        return ! empty($bag[$key]) ? __('settings.payplus.masked_hint') : null;
    }

    public function save(): void
    {
        $shop = Tenant::current();
        if (! $shop) {
            return;
        }

        $bag = $shop->payplus_credentials ?: [];
        $input = $this->form->getState();

        // Plain fields overwrite directly; secrets only overwrite when a new value
        // was typed (an empty secret field keeps the existing encrypted value).
        foreach (self::PLAIN_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $bag[$key] = $input[$key];
            }
        }
        foreach (self::SECRET_KEYS as $key) {
            if (! empty($input[$key])) {
                $bag[$key] = $input[$key];
            }
        }

        $shop->payplus_credentials = $bag;
        $shop->save();

        $this->connectionStatus = $shop->hasPayplusConnection() ? 'connected' : 'not_connected';
        $this->mount(); // re-mask the secret fields

        Notification::make()->title(__('settings.payplus.saved'))->success()->send();
    }

    /**
     * Test connection — a NON-charging probe. Builds the per-shop gateway and
     * issues a lightweight authenticated lookup; a transport/auth failure surfaces
     * the (masked) reason. Never charges, never reveals a secret in the message.
     */
    public function testConnection(): void
    {
        $shop = Tenant::current();
        if (! $shop || ! $shop->hasPayplusConnection()) {
            $this->connectionStatus = 'not_connected';
            Notification::make()->title(__('settings.payplus.test_fail', ['reason' => __('settings.payplus.status.not_connected')]))->danger()->send();

            return;
        }

        try {
            $gateway = PayPlusGatewayFactory::for($shop);
            $result = $gateway->lookupVaultToken(['probe' => true]);

            if ($result->success) {
                $this->connectionStatus = 'connected';
                Notification::make()->title(__('settings.payplus.test_ok'))->success()->send();
            } else {
                $this->connectionStatus = 'error';
                Notification::make()
                    ->title(__('settings.payplus.test_fail', ['reason' => (string) ($result->errorMessage ?? $result->errorCode ?? 'error')]))
                    ->danger()
                    ->send();
            }
        } catch (Throwable $e) {
            $this->connectionStatus = 'error';
            Notification::make()
                ->title(__('settings.payplus.test_fail', ['reason' => class_basename($e)]))
                ->danger()
                ->send();
        }
    }
}
