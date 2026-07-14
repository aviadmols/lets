<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ShopScopedScreen;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusAccountDiscovery;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Settings → PayPlus Connection (docs/ux/50-settings.md §1, ARCHITECTURE.md
 * "Per-shop credentials"). REDESIGNED: the merchant pastes only their api_key +
 * secret_key and picks Production/Sandbox. The app then AUTO-DISCOVERS the
 * terminal, payment page, and cashier from PayPlus (PayPlusAccountDiscovery):
 *
 *   Connect  → GET /MyTerminals          → terminal_uid (picker if >1)
 *   Terminal → GET /PaymentPages/list    → payment_page_uid + cashier_uid (picker if >1)
 *   Save     → encrypted bag on the shop  (the existing payplus_credentials path)
 *
 * Secrets are masked after save (an empty secret field on save = "keep existing").
 * The opaque terminal/cashier/page UIDs are never free-text anymore — they come
 * from the discovered options, with a read-only "Connected to:" summary.
 *
 * Tenant-safe: reads/writes only Tenant::current(); never touches another shop.
 */
class ManagePayPlusConnection extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static string $view = 'filament.pages.payplus-connection';
    protected static ?string $slug = 'settings/payplus';
    protected static ?int $navigationSort = 10;

    /** Credential keys that are sensitive → masked after save, never re-shown. */
    public const SECRET_KEYS = ['api_key', 'secret_key', 'webhook_secret'];

    /** Plain (non-secret) keys persisted directly into the encrypted bag. */
    public const PLAIN_KEYS = ['terminal_uid', 'cashier_uid', 'payment_page_uid', 'base_url'];

    /** @var array<string, mixed> */
    public array $data = [];

    public ?string $connectionStatus = 'not_connected';

    /**
     * Discovered terminals, keyed by uid → label, for the reactive Select. Lives on
     * the Livewire component so it survives between the Connect action and render.
     *
     * @var array<string, string>
     */
    public array $terminalOptions = [];

    /**
     * Discovered payment pages for the selected terminal, keyed by uid → label.
     *
     * @var array<string, string>
     */
    public array $pageOptions = [];

    /**
     * The discovered page rows by uid, so terminal/page selection can recover the
     * cashier_uid + names: [uid => ['name' => ..., 'cashier_uid' => ...]].
     *
     * @var array<string, array{name:string,cashier_uid:string}>
     */
    public array $pageMeta = [];

    /** Human names for the connected summary line. */
    public ?string $connectedTerminalName = null;
    public ?string $connectedPageName = null;

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

        $bag = $shop?->payplus_credentials ?? [];

        // Pre-seed the discovered-option props from what is already saved so a
        // returning merchant sees their terminal/page already selected without a
        // re-Connect (the Select needs the value present in its options).
        $this->terminalOptions = [];
        $this->pageOptions = [];
        if (! empty($bag['terminal_uid'])) {
            $this->terminalOptions = [$bag['terminal_uid'] => (string) $bag['terminal_uid']];
        }
        if (! empty($bag['payment_page_uid'])) {
            $this->pageOptions = [$bag['payment_page_uid'] => (string) $bag['payment_page_uid']];
            $this->pageMeta = [
                $bag['payment_page_uid'] => [
                    'name' => (string) $bag['payment_page_uid'],
                    'cashier_uid' => (string) ($bag['cashier_uid'] ?? ''),
                ],
            ];
        }

        $this->connectedTerminalName = $bag['terminal_uid'] ?? null;
        $this->connectedPageName = $bag['payment_page_uid'] ?? null;

        // Secrets stay blank (masked); plain discovered values are pre-filled.
        $this->form->fill([
            'base_url' => $bag['base_url'] ?? config('payplus.base_url'),
            'terminal_uid' => $bag['terminal_uid'] ?? null,
            'payment_page_uid' => $bag['payment_page_uid'] ?? null,
            'cashier_uid' => $bag['cashier_uid'] ?? null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                $this->credentialsSection(),
                $this->discoverySection(),
                $this->advancedSection(),
            ]);
    }

    /** Step 1 — credentials + environment + the Connect action. */
    private function credentialsSection(): Section
    {
        return Section::make(__('settings.payplus.heading'))
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
                Radio::make('base_url')
                    ->label(__('settings.payplus.environment'))
                    ->options([
                        (string) config('payplus.base_url') => __('settings.payplus.env_production'),
                        (string) config('payplus.base_url_sandbox', config('payplus.base_url')) => __('settings.payplus.env_sandbox'),
                    ])
                    ->default((string) config('payplus.base_url'))
                    ->inline()
                    ->inlineLabel(false),
                Actions::make([
                    Action::make('connect')
                        ->label(__('settings.payplus.connect'))
                        ->icon('heroicon-m-link')
                        ->action('connect'),
                ]),
            ])
            ->columns(2);
    }

    /** Step 2 + 3 — discovered terminal + payment page (pickers when >1). */
    private function discoverySection(): Section
    {
        return Section::make(__('settings.payplus.discovery_heading'))
            ->description(__('settings.payplus.discovery_intro'))
            ->visible(fn (): bool => $this->terminalOptions !== [])
            ->schema([
                Select::make('terminal_uid')
                    ->label(__('settings.payplus.terminal'))
                    ->options(fn (): array => $this->terminalOptions)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        $this->onTerminalSelected($state);
                    })
                    ->visible(fn (): bool => count($this->terminalOptions) > 0),
                Select::make('payment_page_uid')
                    ->label(__('settings.payplus.payment_page'))
                    ->options(fn (): array => $this->pageOptions)
                    ->native(false)
                    ->live()
                    // PayPlus cannot mint a card page without this — it is NOT optional.
                    ->required()
                    ->afterStateUpdated(function (?string $state): void {
                        $this->onPageSelected($state);
                    })
                    ->visible(fn (): bool => count($this->pageOptions) > 0),
                // Page discovery failed (or PayPlus has none) → say so loudly and let the
                // merchant retry, instead of leaving a silently unusable connection.
                Placeholder::make('pages_missing')
                    ->label('')
                    ->content(fn (): string => __('settings.payplus.needs_payment_page_help'))
                    ->visible(fn (Get $get): bool => filled($get('terminal_uid')) && $this->pageOptions === []),
                Actions::make([
                    Action::make('rediscoverPages')
                        ->label(__('settings.payplus.rediscover'))
                        ->icon('heroicon-m-arrow-path')
                        ->color('gray')
                        ->action('rediscoverPages'),
                ])->visible(fn (Get $get): bool => filled($get('terminal_uid')) && $this->pageOptions === []),
                // Discovered, non-editable: the cashier rides along with the page.
                Placeholder::make('connected_summary')
                    ->label(__('settings.payplus.connected_label'))
                    ->content(fn (Get $get): string => $this->summaryLine($get))
                    ->visible(fn (Get $get): bool => filled($get('payment_page_uid'))),
            ])
            ->columns(2);
    }

    /** Advanced — the webhook secret (NOT API-discoverable), collapsed by default. */
    private function advancedSection(): Section
    {
        return Section::make(__('settings.payplus.advanced'))
            ->description(__('settings.payplus.advanced_intro'))
            ->collapsed()
            ->schema([
                TextInput::make('webhook_secret')
                    ->label(__('settings.payplus.webhook_secret'))
                    ->password()
                    ->revealable()
                    ->placeholder($this->maskHint('webhook_secret'))
                    ->autocomplete(false),
            ]);
    }

    /** Show a "saved — paste to replace" hint when a secret already exists. */
    public function maskHint(string $key): ?string
    {
        $bag = Tenant::current()?->payplus_credentials ?? [];

        return ! empty($bag[$key]) ? __('settings.payplus.masked_hint') : null;
    }

    /**
     * Connect action — discover the account's terminals from the typed creds (or
     * the stored secret when the field is left blank). Auto-selects a sole terminal
     * and immediately fetches its payment pages. Fails closed with a masked reason.
     */
    public function connect(): void
    {
        $shop = Tenant::current();
        if (! $shop) {
            return;
        }

        [$apiKey, $secretKey, $baseUrl] = $this->resolveCredentials($shop);

        if ($apiKey === '' || $secretKey === '') {
            Notification::make()
                ->title(__('settings.payplus.connect_need_creds'))
                ->danger()
                ->send();

            return;
        }

        $discovery = PayPlusAccountDiscovery::for($apiKey, $secretKey, $baseUrl);
        $terminals = $discovery->terminals();

        if ($terminals === []) {
            $this->terminalOptions = [];
            $this->pageOptions = [];
            Notification::make()
                ->title(__('settings.payplus.connect_failed', [
                    'reason' => __('settings.payplus.reason.'.($discovery->lastReason ?? 'transport')),
                ]))
                ->danger()
                ->send();

            return;
        }

        // Build the picker options (active terminals first, but keep all visible).
        $this->terminalOptions = [];
        foreach ($terminals as $t) {
            $label = $t['name'].' — '.$t['uid'];
            if (! $t['active']) {
                $label .= ' ('.__('settings.payplus.terminal_inactive').')';
            }
            $this->terminalOptions[$t['uid']] = $label;
        }

        Notification::make()
            ->title(__('settings.payplus.connect_found', ['count' => count($terminals)]))
            ->success()
            ->send();

        // Auto-select a sole terminal and chain straight to its pages.
        if (count($this->terminalOptions) === 1) {
            $only = array_key_first($this->terminalOptions);
            $this->data['terminal_uid'] = $only;
            $this->onTerminalSelected($only);
        }
    }

    /**
     * Terminal selected → discover that terminal's payment pages. Auto-selects a
     * sole page (carrying its cashier_uid). Reactive: invoked from the Select's
     * afterStateUpdated AND from the auto-select path in connect().
     */
    public function onTerminalSelected(?string $terminalUid): void
    {
        $this->pageOptions = [];
        $this->pageMeta = [];
        $this->connectedTerminalName = $this->terminalLabel($terminalUid);

        if (! $terminalUid) {
            return;
        }

        $shop = Tenant::current();
        if (! $shop) {
            return;
        }

        [$apiKey, $secretKey, $baseUrl] = $this->resolveCredentials($shop);

        $discovery = PayPlusAccountDiscovery::for($apiKey, $secretKey, $baseUrl);
        $pages = $discovery->paymentPages($terminalUid);

        if ($pages === []) {
            // NEVER null an existing payment_page_uid/cashier_uid here. A failed — or
            // simply empty — discovery must not destroy a working connection (the old
            // code cleared them up-front, so one flaky call silently un-configured the
            // shop and the next Save persisted the nulls). Keep what we have and tell
            // the merchant the REAL reason. REASON_EMPTY means PayPlus genuinely has no
            // payment page on this terminal → they must create one in PayPlus.
            Notification::make()
                ->title(__('settings.payplus.pages_failed', [
                    'reason' => __('settings.payplus.reason.'.($discovery->lastReason ?? 'transport')),
                ]))
                ->body(__('settings.payplus.pages_failed_help'))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        foreach ($pages as $p) {
            $this->pageOptions[$p['uid']] = $p['name'];
            $this->pageMeta[$p['uid']] = [
                'name' => $p['name'],
                'cashier_uid' => $p['cashier_uid'],
            ];
        }

        // The already-selected page still belongs to this terminal → keep it (a
        // re-Connect must not silently drop a working page).
        $current = (string) ($this->data['payment_page_uid'] ?? '');
        if ($current !== '' && isset($this->pageMeta[$current])) {
            $this->onPageSelected($current);

            return;
        }

        // Only now — we KNOW this terminal's real page list and the old page isn't in it.
        $this->data['payment_page_uid'] = null;
        $this->data['cashier_uid'] = null;
        $this->connectedPageName = null;

        // Auto-select a sole page (and its cashier).
        if (count($this->pageOptions) === 1) {
            $only = array_key_first($this->pageOptions);
            $this->data['payment_page_uid'] = $only;
            $this->onPageSelected($only);
        }
    }

    /** Retry page discovery for the currently-selected terminal (a flaky call is recoverable). */
    public function rediscoverPages(): void
    {
        $this->onTerminalSelected((string) ($this->data['terminal_uid'] ?? '') ?: null);
    }

    /** Payment page selected → capture its cashier_uid + names for the summary. */
    public function onPageSelected(?string $pageUid): void
    {
        if (! $pageUid || ! isset($this->pageMeta[$pageUid])) {
            $this->data['cashier_uid'] = null;
            $this->connectedPageName = null;

            return;
        }

        $this->data['cashier_uid'] = $this->pageMeta[$pageUid]['cashier_uid'];
        $this->connectedPageName = $this->pageMeta[$pageUid]['name'];
    }

    public function save(): void
    {
        $shop = Tenant::current();
        if (! $shop) {
            return;
        }

        $bag = $shop->payplus_credentials ?: [];
        $input = $this->form->getState();

        // A PayPlus connection WITHOUT a payment_page_uid cannot mint a hosted card page —
        // checkout dies with "no payment page". Refuse the save (and never report success)
        // rather than hard-committing an unusable connection that then reports "Connected".
        // This is the exact state shop 2 was left in.
        if (blank($this->data['payment_page_uid'] ?? null)) {
            Notification::make()
                ->title(__('settings.payplus.needs_payment_page'))
                ->body(__('settings.payplus.needs_payment_page_help'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        // Plain (discovered) fields are read from the raw Livewire state ($this->data),
        // NOT getState(): the discovery section is conditionally visible, and hidden
        // Filament components are excluded from getState()/dehydration. The raw state
        // always holds the discovered terminal/page/cashier we set programmatically.
        foreach (self::PLAIN_KEYS as $key) {
            if (array_key_exists($key, $this->data)) {
                $bag[$key] = $this->data[$key];
            }
        }
        // Secrets overwrite only when a new value was typed (an empty secret field
        // keeps the existing encrypted value). Secrets are always visible.
        foreach (self::SECRET_KEYS as $key) {
            if (! empty($input[$key])) {
                $bag[$key] = $input[$key];
            }
        }

        $shop->payplus_credentials = $bag;
        $shop->save();

        $this->connectionStatus = $shop->hasPayplusConnection() ? 'connected' : 'not_connected';
        $this->mount(); // re-mask secret fields + refresh the connected summary

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

    // === Internals ===

    /**
     * The credentials discovery should use: the TYPED secrets win; when a field is
     * left blank we fall back to the shop's already-stored secret (so a returning
     * merchant can re-Connect without re-pasting). Read from the raw Livewire state
     * so it works regardless of which form sections are currently visible.
     *
     * @return array{0:string,1:string,2:string} [apiKey, secretKey, baseUrl]
     */
    private function resolveCredentials(Shop $shop): array
    {
        $bag = $shop->payplus_credentials ?: [];

        $apiKey = (string) ($this->data['api_key'] ?? '') ?: (string) ($bag['api_key'] ?? '');
        $secretKey = (string) ($this->data['secret_key'] ?? '') ?: (string) ($bag['secret_key'] ?? '');
        $baseUrl = (string) ($this->data['base_url'] ?? '') ?: (string) config('payplus.base_url');

        return [$apiKey, $secretKey, $baseUrl];
    }

    private function terminalLabel(?string $uid): ?string
    {
        if (! $uid) {
            return null;
        }

        return $this->terminalOptions[$uid] ?? $uid;
    }

    /** The read-only "Connected to: {terminal} · {page}" line. */
    private function summaryLine(Get $get): string
    {
        $terminal = $this->connectedTerminalName ?: $get('terminal_uid');
        $page = $this->connectedPageName ?: $get('payment_page_uid');

        return trim((string) $terminal).' · '.trim((string) $page);
    }
}
