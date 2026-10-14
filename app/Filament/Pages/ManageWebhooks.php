<?php

namespace App\Filament\Pages;

use App\Domain\Brand\SafeSiteFetcher;
use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Domain\OutboundWebhooks\SamplePayloads;
use App\Domain\OutboundWebhooks\WebhookDeliverer;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Filament\Clusters\Settings as SettingsCluster;
use App\Filament\Concerns\ShopScopedScreen;
use App\Support\Tenant;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Webhooks. The merchant names their own URLs (Make, Zapier, n8n, an
 * ERP) and picks what each one hears: paid orders, recurring charges that
 * succeeded, recurring charges that failed.
 *
 * Three parts, one screen:
 *   1. the endpoints table — add / edit / pause / delete, reveal or rotate the
 *      signing secret, send a test;
 *   2. the test-JSON generator — build a sample for any topic (from the shop's
 *      latest real event, or a made-up one), copy it or download it;
 *   3. the delivery log — what was sent, what the URL answered, resend.
 *
 * Tenant-safe: every query runs under BelongsToShop's scope; a URL is judged by
 * the SSRF walls (SafeSiteFetcher) on save AND on every send.
 */
class ManageWebhooks extends Page implements HasTable
{
    use InteractsWithTable;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static string $view = 'filament.pages.webhooks';

    protected static ?string $slug = 'webhooks';

    /** Behind the one Settings item: in-page left index, URL /admin/settings/{slug}. */
    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 60;

    public const LOG_PER_PAGE = 25;

    /** Log filters: every request, only the ones the URL received, only the ones it did not. */
    public const LOG_ALL = 'all';

    public const LOG_RECEIVED = 'received';

    public const LOG_NOT_RECEIVED = 'not_received';

    public const LOG_FILTERS = [self::LOG_ALL, self::LOG_RECEIVED, self::LOG_NOT_RECEIVED];

    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** Test-JSON generator state. */
    public string $sampleTopic = WebhookTopic::ORDER_PAID;

    public string $sampleOrigin = SamplePayloads::ORIGIN_REAL;

    public ?string $sampleJson = null;

    public ?string $sampleBuiltFrom = null;

    /** The delivery whose JSON is unfolded in the log. */
    public ?int $openDeliveryId = null;

    /** Delivery-log filter + page (1-based). */
    public string $logFilter = self::LOG_ALL;

    public int $logPage = 1;

    public static function getNavigationGroup(): ?string
    {
        return null; // the cluster index is flat
    }

    public static function getNavigationLabel(): string
    {
        return __('webhooks.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('webhooks.title');
    }

    public function getSubheading(): ?string
    {
        return __('webhooks.intro');
    }

    // === 1. Endpoints ===

    public function table(Table $table): Table
    {
        return $table
            ->query(WebhookEndpoint::query()->latest('id'))
            ->paginated(false)
            ->emptyStateHeading(__('webhooks.empty.title'))
            ->emptyStateDescription(__('webhooks.empty.body'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('webhooks.col.name'))
                    ->weight('semibold')
                    ->description(fn (WebhookEndpoint $record): string => (string) $record->url),
                TextColumn::make('topics')
                    ->label(__('webhooks.col.topics'))
                    ->state(fn (WebhookEndpoint $record): array => array_map(
                        static fn (string $topic): string => __('webhooks.topic.'.$topic),
                        $record->subscribedTopics(),
                    ))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label(__('webhooks.col.active'))
                    ->boolean(),
                TextColumn::make('health')
                    ->label(__('webhooks.col.health'))
                    ->state(fn (WebhookEndpoint $record): string => $this->healthLabel($record))
                    ->badge()
                    ->color(fn (WebhookEndpoint $record): string => $record->consecutive_failures > 0 ? 'danger' : 'gray'),
            ])
            ->headerActions([
                Action::make('create')
                    ->label(__('webhooks.action.create'))
                    ->icon('heroicon-o-plus')
                    ->form($this->endpointForm())
                    ->action(fn (array $data) => $this->saveEndpoint(new WebhookEndpoint(['secret' => WebhookEndpoint::newSecret()]), $data)),
            ])
            ->actions([
                Action::make('test')
                    ->label(__('webhooks.action.test'))
                    ->icon('heroicon-o-paper-airplane')
                    ->form([
                        Select::make('topic')
                            ->label(__('webhooks.field.topic'))
                            ->options(WebhookTopic::options())
                            ->default(WebhookTopic::ORDER_PAID)
                            ->required(),
                        Radio::make('origin')
                            ->label(__('webhooks.field.origin'))
                            ->options($this->originOptions())
                            ->default(SamplePayloads::ORIGIN_REAL)
                            ->required(),
                    ])
                    ->action(fn (WebhookEndpoint $record, array $data) => $this->sendTest($record, $data)),
                ActionGroup::make([
                    Action::make('edit')
                        ->label(__('common.edit'))
                        ->icon('heroicon-o-pencil-square')
                        ->fillForm(fn (WebhookEndpoint $record): array => [
                            'name' => $record->name,
                            'url' => $record->url,
                            'topics' => $record->subscribedTopics(),
                            'is_active' => $record->is_active,
                        ])
                        ->form($this->endpointForm())
                        ->action(fn (WebhookEndpoint $record, array $data) => $this->saveEndpoint($record, $data)),
                    Action::make('secret')
                        ->label(__('webhooks.action.secret'))
                        ->icon('heroicon-o-key')
                        ->modalHeading(__('webhooks.secret.heading'))
                        ->modalContent(fn (WebhookEndpoint $record): View => view('filament.pages.webhooks.secret', [
                            'secret' => (string) $record->secret,
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('webhooks.action.close')),
                    Action::make('rotate')
                        ->label(__('webhooks.action.rotate'))
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalDescription(__('webhooks.secret.rotate_warning'))
                        ->action(function (WebhookEndpoint $record): void {
                            $record->forceFill(['secret' => WebhookEndpoint::newSecret()])->save();
                            Notification::make()->success()->title(__('webhooks.notice.rotated'))->send();
                        }),
                    Action::make('delete')
                        ->label(__('common.delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (WebhookEndpoint $record) => $record->delete()),
                ]),
            ]);
    }

    /** @return array<int, Component> */
    private function endpointForm(): array
    {
        return [
            TextInput::make('name')
                ->label(__('webhooks.field.name'))
                ->placeholder(__('webhooks.field.name_placeholder'))
                ->required()
                ->maxLength(120),
            TextInput::make('url')
                ->label(__('webhooks.field.url'))
                ->helperText(__('webhooks.field.url_help'))
                ->placeholder('https://hook.eu1.make.com/…')
                ->url()
                ->required()
                ->maxLength(2048),
            CheckboxList::make('topics')
                ->label(__('webhooks.field.topics'))
                ->options(WebhookTopic::options())
                ->descriptions($this->topicDescriptions())
                ->default(WebhookTopic::ALL)
                ->required(),
            Toggle::make('is_active')
                ->label(__('webhooks.field.active'))
                ->default(true),
        ];
    }

    /** @param array<string, mixed> $data */
    private function saveEndpoint(WebhookEndpoint $endpoint, array $data): void
    {
        $url = trim((string) ($data['url'] ?? ''));
        $refusal = app(SafeSiteFetcher::class)->refusalFor($url);

        if ($refusal !== null || ! str_starts_with(strtolower($url), 'https://')) {
            Notification::make()->danger()
                ->title(__('webhooks.notice.url_refused'))
                ->body(__('webhooks.notice.url_refused_body'))
                ->send();

            return;
        }

        $endpoint->fill([
            'name' => trim((string) $data['name']),
            'url' => $url,
            'topics' => array_values(array_intersect(WebhookTopic::ALL, (array) ($data['topics'] ?? []))),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        Notification::make()->success()->title(__('webhooks.notice.saved'))->send();
    }

    /** @param array<string, mixed> $data */
    private function sendTest(WebhookEndpoint $endpoint, array $data): void
    {
        $topic = in_array($data['topic'] ?? null, WebhookTopic::ALL, true) ? $data['topic'] : WebhookTopic::ORDER_PAID;
        $sample = app(SamplePayloads::class)->for(
            Tenant::current(),
            $topic,
            ($data['origin'] ?? null) !== SamplePayloads::ORIGIN_EXAMPLE,
        );

        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'topic' => $topic,
            'event_key' => $sample['event_key'],
            'payload' => $sample['payload'],
            'is_test' => true,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $delivery = app(WebhookDeliverer::class)->attempt($delivery, scheduleRetry: false);
        $this->openDeliveryId = (int) $delivery->getKey();

        $notice = Notification::make()->body($this->outcomeLine($delivery));
        $delivery->status === WebhookDelivery::STATUS_SUCCEEDED
            ? $notice->success()->title(__('webhooks.notice.test_sent'))
            : $notice->danger()->title(__('webhooks.notice.test_failed'));
        $notice->send();
    }

    // === 2. Test-JSON generator ===

    public function generateSample(): void
    {
        $topic = in_array($this->sampleTopic, WebhookTopic::ALL, true) ? $this->sampleTopic : WebhookTopic::ORDER_PAID;
        $sample = app(SamplePayloads::class)->for(
            Tenant::current(),
            $topic,
            $this->sampleOrigin !== SamplePayloads::ORIGIN_EXAMPLE,
        );

        $this->sampleJson = (string) json_encode($sample['payload'], self::JSON_FLAGS);
        $this->sampleBuiltFrom = $sample['origin'];
    }

    public function downloadSample(): ?StreamedResponse
    {
        if ($this->sampleJson === null) {
            return null;
        }

        $json = $this->sampleJson;
        $name = 'lets-'.str_replace('.', '-', $this->sampleTopic).'-sample.json';

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $name, ['Content-Type' => 'application/json']);
    }

    /** @return array<string, string> */
    public function topicOptions(): array
    {
        return WebhookTopic::options();
    }

    /** @return array<string, string> */
    public function originOptions(): array
    {
        return [
            SamplePayloads::ORIGIN_REAL => __('webhooks.origin.real'),
            SamplePayloads::ORIGIN_EXAMPLE => __('webhooks.origin.example'),
        ];
    }

    // === 3. Delivery log ===

    /** @return Collection<int, WebhookDelivery> */
    public function deliveries(): Collection
    {
        return $this->logQuery()
            ->with('endpoint')
            ->latest('id')
            ->offset((max(1, $this->logPage) - 1) * self::LOG_PER_PAGE)
            ->limit(self::LOG_PER_PAGE)
            ->get();
    }

    /** @return array<string, int> filter => how many requests it holds */
    public function logCounts(): array
    {
        $counts = [];
        foreach (self::LOG_FILTERS as $filter) {
            $counts[$filter] = $this->logQuery($filter)->count();
        }

        return $counts;
    }

    public function hasNextLogPage(): bool
    {
        return $this->logQuery()->count() > $this->logPage * self::LOG_PER_PAGE;
    }

    public function setLogFilter(string $filter): void
    {
        $this->logFilter = in_array($filter, self::LOG_FILTERS, true) ? $filter : self::LOG_ALL;
        $this->logPage = 1;
        $this->openDeliveryId = null;
    }

    public function logPageBy(int $step): void
    {
        $this->logPage = max(1, $this->logPage + ($step < 0 ? -1 : 1));
        $this->openDeliveryId = null;
    }

    /**
     * Received = the URL answered 2xx. Not received = it answered something else,
     * did not answer, or we are still retrying it.
     */
    private function logQuery(?string $filter = null): Builder
    {
        return match ($filter ?? $this->logFilter) {
            self::LOG_RECEIVED => WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_SUCCEEDED),
            self::LOG_NOT_RECEIVED => WebhookDelivery::query()->whereIn('status', [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_RETRYING]),
            default => WebhookDelivery::query(),
        };
    }

    /** "Order #1042 · dana@example.com" — which sale a request was about, read from what we sent. */
    public function subjectLine(WebhookDelivery $delivery): string
    {
        $data = (array) data_get($delivery->payload ?? [], 'data', []);
        $order = data_get($data, 'order.number') ?? data_get($data, 'order.id');
        $who = data_get($data, 'customer.email') ?? data_get($data, 'customer.name');

        $parts = array_filter([
            $order !== null ? __('webhooks.log.order', ['number' => $order]) : null,
            $who,
        ]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /** What the URL said, in words: "HTTP 200", "HTTP 500", "no answer: …". */
    public function answerLine(WebhookDelivery $delivery): string
    {
        if ($delivery->response_status !== null) {
            return 'HTTP '.$delivery->response_status;
        }

        return $delivery->error ? __('webhooks.log.no_answer', ['error' => $delivery->error]) : '—';
    }

    public function toggleDelivery(int $id): void
    {
        $this->openDeliveryId = $this->openDeliveryId === $id ? null : $id;
    }

    public function resend(int $id): void
    {
        $delivery = WebhookDelivery::query()->find($id);
        if ($delivery === null) {
            return;
        }

        if ($delivery->status === WebhookDelivery::STATUS_SUCCEEDED) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_PENDING])->save();
        }

        $delivery = app(WebhookDeliverer::class)->attempt($delivery, scheduleRetry: false);

        $notice = Notification::make()->body($this->outcomeLine($delivery));
        $delivery->status === WebhookDelivery::STATUS_SUCCEEDED
            ? $notice->success()->title(__('webhooks.notice.resent'))
            : $notice->danger()->title(__('webhooks.notice.resend_failed'));
        $notice->send();
    }

    public function prettyPayload(WebhookDelivery $delivery): string
    {
        return $delivery->payload === null
            ? __('webhooks.log.not_built')
            : (string) json_encode($delivery->payload, self::JSON_FLAGS);
    }

    public function statusTone(string $status): string
    {
        return match ($status) {
            WebhookDelivery::STATUS_SUCCEEDED => 'green',
            WebhookDelivery::STATUS_FAILED => 'red',
            WebhookDelivery::STATUS_RETRYING => 'amber',
            default => 'gray',
        };
    }

    // === Helpers ===

    private function outcomeLine(WebhookDelivery $delivery): string
    {
        return $delivery->response_status !== null
            ? __('webhooks.notice.http_status', ['status' => $delivery->response_status])
            : __('webhooks.notice.no_response', ['error' => (string) $delivery->error]);
    }

    private function healthLabel(WebhookEndpoint $endpoint): string
    {
        if ($endpoint->consecutive_failures > 0) {
            return __('webhooks.health.failing', ['count' => $endpoint->consecutive_failures]);
        }

        return $endpoint->last_success_at !== null
            ? __('webhooks.health.ok', ['when' => $endpoint->last_success_at->diffForHumans()])
            : __('webhooks.health.never');
    }

    /** @return array<string, string> */
    private function topicDescriptions(): array
    {
        $descriptions = [];
        foreach (WebhookTopic::ALL as $topic) {
            $descriptions[$topic] = __('webhooks.topic_help.'.$topic);
        }

        return $descriptions;
    }
}
