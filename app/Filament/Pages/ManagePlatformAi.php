<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Models\AiUsageEvent;
use App\Models\PlatformAiSettings;
use App\Support\Ui\PanelAccess;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Platform → AI. THE OWNER'S screen: the account every shop's studio chat
 * runs on, its kill switch, its daily budget, and what it all cost.
 *
 * The ManagePlatformMail discipline, applied to the model key: encrypted at
 * rest, env fallback (`ANTHROPIC_API_KEY`), never rendered back, blank on
 * save means keep. The kill switch here darkens the AI PANEL everywhere at
 * once — the block editor keeps working, because a merchant's newsletter must
 * never be hostage to a model's availability.
 */
class ManagePlatformAi extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string $view = 'filament.pages.platform-ai';

    protected static ?string $slug = 'platform/ai';

    protected static ?int $navigationSort = 25;

    /** How many days back the usage table looks. */
    public const USAGE_DAYS = 30;

    /** @var array<string, mixed> the form state (statePath: data). */
    public array $data = [];

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
        return __('platform_ai.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('platform_ai.title');
    }

    public function mount(): void
    {
        $settings = PlatformAiSettings::current();

        $this->form->fill([
            'anthropic_api_key' => null, // never re-shown
            'enabled' => $settings->isEnabled(),
            'daily_token_budget' => $settings->getAttribute('daily_token_budget'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make(__('platform_ai.account.heading'))
                    ->description(__('platform_ai.account.intro'))
                    ->schema([
                        Placeholder::make('connection_state')
                            ->label(__('platform_ai.account.state'))
                            ->content(fn (): string => $this->connectionLine()),

                        TextInput::make('anthropic_api_key')
                            ->label(__('platform_ai.account.key'))
                            ->helperText(fn (): string => PlatformAiSettings::current()->keyIsStored()
                                ? __('platform_ai.account.key_stored')
                                : __('platform_ai.account.key_help'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->maxLength(255),

                        Toggle::make('enabled')
                            ->label(__('platform_ai.account.enabled'))
                            ->helperText(__('platform_ai.account.enabled_help')),

                        TextInput::make('daily_token_budget')
                            ->label(__('platform_ai.account.budget'))
                            ->helperText(__('platform_ai.account.budget_help'))
                            ->numeric()
                            ->minValue(0)
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ])
                    ->columns(2),

                Section::make(__('platform_ai.usage.heading'))
                    ->schema([
                        Placeholder::make('usage_today')
                            ->label(__('platform_ai.usage.today'))
                            ->content(fn (): string => number_format(AiUsageEvent::platformTokensToday())),

                        Placeholder::make('usage_month')
                            ->label(__('platform_ai.usage.window', ['days' => self::USAGE_DAYS]))
                            ->content(fn (): string => number_format($this->windowTokens())),

                        Placeholder::make('usage_failures')
                            ->label(__('platform_ai.usage.failures'))
                            ->content(fn (): string => number_format($this->windowFailures())),
                    ])
                    ->columns(3),
            ]);
    }

    public function save(): void
    {
        $input = $this->form->getState();
        $settings = PlatformAiSettings::current();

        // A blank key keeps what is stored — the field is never re-shown, and
        // an owner flipping the switch must not blank the platform credential.
        $key = trim((string) ($input['anthropic_api_key'] ?? ''));
        if ($key !== '') {
            $settings->anthropic_api_key = $key;
        }

        $settings->enabled = (bool) ($input['enabled'] ?? true);

        $budget = $input['daily_token_budget'] ?? null;
        $settings->daily_token_budget = is_numeric($budget) && (int) $budget > 0 ? (int) $budget : null;

        $settings->save();

        $this->mount(); // re-mask the key
        Notification::make()->success()->title(__('platform_ai.saved'))->send();
    }

    // === Internals ===

    private function connectionLine(): string
    {
        $settings = PlatformAiSettings::current();

        if (! $settings->isConnected()) {
            return __('platform_ai.account.state_off');
        }

        return $settings->keyIsStored()
            ? __('platform_ai.account.state_on_saved')
            : __('platform_ai.account.state_on_env');
    }

    private function windowTokens(): int
    {
        return (int) AiUsageEvent::acrossAllTenants()
            ->where('created_at', '>=', now()->subDays(self::USAGE_DAYS))
            ->sum(DB::raw('input_tokens + output_tokens'));
    }

    private function windowFailures(): int
    {
        return (int) AiUsageEvent::acrossAllTenants()
            ->where('created_at', '>=', now()->subDays(self::USAGE_DAYS))
            ->where('status', '<>', AiUsageEvent::STATUS_OK)
            ->count();
    }
}
