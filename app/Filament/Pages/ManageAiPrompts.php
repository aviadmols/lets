<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Models\AiPrompt;
use App\Domain\Ai\PromptRepository;
use App\Support\Ui\PanelAccess;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Platform → AI prompts. The owner steers every stage's behaviour in their own
 * words: one section per pipeline stage, the shipped default as the
 * placeholder, and "reset" is just clearing the text — the default is always
 * there to come back to (the spec's full version/test-case machinery is a
 * later phase; this is the smallest thing that makes prompts OWNED).
 */
class ManageAiPrompts extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static string $view = 'filament.pages.ai-prompts';

    protected static ?string $slug = 'platform/ai-prompts';

    protected static ?int $navigationSort = 26;

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
        return __('platform_ai.prompts.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('platform_ai.prompts.title');
    }

    public function mount(): void
    {
        $state = [];

        foreach (AiPrompt::STAGES as $stage) {
            $row = AiPrompt::query()->where('stage', $stage)->first();
            $state[$stage.'_prompt'] = $row?->system_prompt;
            $state[$stage.'_model'] = $row?->model;
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        $repository = new PromptRepository;

        return $form
            ->statePath('data')
            ->schema(array_map(
                fn (string $stage): Section => Section::make(__('platform_ai.stage.'.$stage))
                    ->description(__('platform_ai.stage.'.$stage.'_help'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Textarea::make($stage.'_prompt')
                            ->label(__('platform_ai.prompts.prompt'))
                            ->helperText(__('platform_ai.prompts.prompt_help'))
                            ->placeholder($repository->defaultFor($stage))
                            ->rows(10),

                        TextInput::make($stage.'_model')
                            ->label(__('platform_ai.prompts.model'))
                            ->helperText(__('platform_ai.prompts.model_help'))
                            ->placeholder((string) config('ai.stages.'.$stage.'.model'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->maxLength(64),
                    ]),
                AiPrompt::STAGES,
            ));
    }

    public function save(): void
    {
        $input = $this->form->getState();
        $repository = new PromptRepository;

        foreach (AiPrompt::STAGES as $stage) {
            $repository->saveFor(
                $stage,
                $input[$stage.'_prompt'] ?? null,
                $input[$stage.'_model'] ?? null,
                auth()->id(),
            );
        }

        Notification::make()->success()->title(__('platform_ai.prompts.saved'))->send();
    }
}
