<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Ui\PanelAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

/**
 * The Shops / Accounts list (platform-admin only — gated by ShopResource). Shopify
 * shops are born from OAuth install; WooCommerce shops are HAND-ADDED here via the
 * "Add WooCommerce store" action (W11): enter the domain → a connection token + a
 * plugin-download link are generated and revealed ONCE (the plaintext key only
 * exists at mint time — only its hash + the encrypted secret are stored).
 */
class ListShops extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = ShopResource::class;

    /**
     * The freshly-minted connection details, held ONLY between minting and the "Done"
     * that closes the reveal modal — then cleared. Never persisted; this is the one
     * place the plaintext connection token exists after mint.
     *
     * @var array{token: string, plugin_url: string, domain: string}|null
     */
    public ?array $wcConnection = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addWooCommerce')
                ->label(__('platform.woo.add'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => PanelAccess::canSeePlatform())
                ->modalHeading(__('platform.woo.add_heading'))
                ->modalDescription(__('platform.woo.add_intro'))
                ->modalSubmitActionLabel(__('platform.woo.generate'))
                ->form([
                    TextInput::make('domain')
                        ->label(__('platform.woo.domain'))
                        ->placeholder('store.example.com')
                        ->helperText(__('platform.woo.domain_help'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('name')
                        ->label(__('platform.woo.name'))
                        ->maxLength(255),
                    TextInput::make('merchant_email')
                        ->label(__('platform.woo.email'))
                        ->helperText(__('platform.woo.email_help'))
                        ->email()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    if (! PanelAccess::canSeePlatform()) {
                        return;
                    }

                    $result = app(WooCommerceShopProvisioner::class)->provision(
                        $data['domain'],
                        $data['name'] ?? null,
                        $data['merchant_email'] ?? null,
                    );

                    $this->wcConnection = [
                        'token' => $result['connection_token'],
                        'plugin_url' => $result['plugin_url'],
                        'domain' => $result['domain'],
                    ];

                    Notification::make()
                        ->title(__('platform.woo.created', ['shop' => $result['domain']]))
                        ->success()
                        ->send();

                    // Swap the create modal for the reveal modal (token shown once).
                    $this->replaceMountedAction('showWooConnection');
                }),

            // Reveal-only modal; mountable only right after a mint (the prop is set).
            Action::make('showWooConnection')
                ->label(__('platform.woo.connection'))
                ->visible(fn (): bool => filled($this->wcConnection))
                ->modalHeading(__('platform.woo.connection'))
                ->modalIcon('heroicon-o-key')
                ->modalContent(fn (): View => view('filament.resources.shop-resource.woo-connection', [
                    'connection' => $this->wcConnection ?? [],
                ]))
                ->modalSubmitActionLabel(__('platform.woo.done'))
                ->closeModalByClickingAway(false)
                ->modalCancelAction(false)
                // "Done" clears the in-memory token.
                ->action(function (): void {
                    $this->wcConnection = null;
                }),
        ];
    }
}
