<?php

namespace App\Filament\Pages;

use App\Domain\Billing\BillingPlan;
use App\Domain\Billing\PlanGate;
use App\Domain\Campaigns\Email\CampaignMailVars;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Domain\Campaigns\Studio\Jobs\RunStudioChatJob;
use App\Domain\Campaigns\Studio\Models\AiChatMessage;
use App\Domain\Campaigns\Studio\Models\EmailCampaignDocumentVersion;
use App\Domain\Campaigns\Studio\NewsletterDocument;
use App\Domain\Campaigns\Studio\Ops\PatchApplier;
use App\Domain\Campaigns\Studio\Ops\PatchOp;
use App\Domain\Campaigns\Studio\Render\EmailRenderer;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\CampaignResource;
use App\Mail\Support\TemplateRenderer;
use App\Models\PlatformAiSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The newsletter studio — a block document edited as blocks, previewed as the
 * email it compiles to.
 *
 * FULL-NAV PAGE (the FlowBuilder shape): reached from a campaign, never from
 * the sidebar. Livewire state is SCALARS AND SMALL ARRAYS ONLY — the document
 * itself is never a Livewire prop; every mutation loads the campaign fresh,
 * goes through DocumentService (the one write seam), and re-renders.
 *
 * `knownVersion` is the optimistic-concurrency token: every mutation asserts
 * the document has not moved since this screen last saw it. Two tabs editing
 * one campaign fail LOUDLY ("המסמך השתנה") instead of silently overwriting
 * each other — the same posture the AI patch pipeline takes in the next unit.
 *
 * The canvas iframe is `sandbox=""` and its HTML is rendered SERVER-side with
 * the SAME strtr + sample vars production substitution uses — never a live
 * credential, never a second client-side renderer to drift.
 */
class NewsletterStudio extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static string $view = 'filament.pages.newsletter-studio';

    protected static ?string $slug = 'campaigns/{campaign}/studio';

    protected static bool $shouldRegisterNavigation = false;

    /** How many versions the drawer lists. */
    public const VERSIONS_SHOWN = 20;

    // --- Identity + concurrency ---
    public int $campaignId = 0;

    public int $knownVersion = 0;

    // --- Selection + the selected block's editable copy ---
    public string $selectedBlockId = '';

    /** @var array<string, mixed> */
    public array $blockContent = [];

    /** @var array<string, mixed> */
    public array $blockStyles = [];

    // --- Campaign-level settings (the drawer) ---
    public string $subject = '';

    public string $preheader = '';

    /** @var array<string, mixed> */
    public array $globals = [];

    public bool $showSettings = false;

    public bool $showVersions = false;

    // --- Chat ---
    public string $chatInput = '';

    /** '' = no run in flight; the poll is gated on it. */
    public string $activeRunId = '';

    public function getTitle(): string|Htmlable
    {
        return __('studio.title');
    }

    public function mount(int|string $campaign): void
    {
        $this->campaignId = (int) $campaign;

        $shop = Tenant::current();
        if ($shop instanceof Shop && ! PlanGate::for($shop)->allows(BillingPlan::FEATURE_AI_NEWSLETTER)) {
            $this->redirect(CampaignResource::getUrl());

            return;
        }

        $row = $this->campaign();

        // A foreign id resolves to null under the global scope — never another
        // shop's campaign; it bounces, it does not load.
        if ($row === null) {
            Notification::make()->title(__('studio.missing'))->warning()->send();
            $this->redirect(CampaignResource::getUrl());

            return;
        }

        // Only a STUDIO campaign opens here; only an editable one is editable.
        if (! $row->isStudio() || ! $row->isEditable()) {
            $this->redirect(CampaignResource::getUrl('edit', ['record' => $row]));

            return;
        }

        // A studio row created without its document (a crash between create and
        // init) self-heals here — initFor is idempotent.
        app(DocumentService::class)->initFor($row, auth()->id());

        $this->refreshFromCampaign($row->refresh());
    }

    // === Reads the blade renders from ===

    public function campaign(): ?EmailCampaign
    {
        return EmailCampaign::query()->find($this->campaignId);
    }

    public function document(): NewsletterDocument
    {
        $row = $this->campaign();

        return ($row !== null ? app(DocumentService::class)->documentFor($row) : null)
            ?? NewsletterDocument::empty();
    }

    /**
     * The canvas: the compiled email with SAMPLE vars substituted through the
     * production strtr, and the selected block outlined. Sandboxed by the view.
     */
    public function previewHtml(): string
    {
        $shop = Tenant::current();

        $rendered = (new EmailRenderer)->render($this->document(), $this->selectedBlockId);

        return TemplateRenderer::render(
            $rendered->html,
            CampaignMailVars::sample($shop instanceof Shop ? $shop : null),
        );
    }

    /** @return list<array{code: string, block_id: ?string, detail: ?string}> */
    public function warnings(): array
    {
        return (new EmailRenderer)->render($this->document())->warnings;
    }

    /** @return array<string, string> type → translated label, palette order */
    public function palette(): array
    {
        $out = [];
        foreach (BlockRegistry::all() as $type => $definition) {
            $out[$type] = (string) __($definition->labelKey());
        }

        return $out;
    }

    /** @return Collection<int, EmailCampaignDocumentVersion> */
    public function versions(): Collection
    {
        $row = $this->campaign();

        return $row !== null
            ? app(DocumentService::class)->versionsFor($row, self::VERSIONS_SHOWN)
            : collect();
    }

    /** @return array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>}|null */
    public function selectedBlock(): ?array
    {
        return $this->selectedBlockId !== ''
            ? $this->document()->findBlock($this->selectedBlockId)
            : null;
    }

    // === Selection ===

    public function selectBlock(string $id): void
    {
        $block = $this->document()->findBlock($id);
        if ($block === null) {
            return;
        }

        $this->selectedBlockId = $id;
        $this->blockContent = $block['content'];
        $this->blockStyles = $block['styles'];

        // The social form edits a flat network → url map; the document keeps
        // the ordered links list. Derive one from the other on the way in.
        if ($block['type'] === 'social_links') {
            $map = [];
            foreach ($block['content']['links'] ?? [] as $link) {
                $map[$link['network']] = $link['url'];
            }
            $this->blockContent['links_map'] = $map;
        }
    }

    public function deselect(): void
    {
        $this->selectedBlockId = '';
        $this->blockContent = [];
        $this->blockStyles = [];
    }

    // === Block mutations (all through the one guarded seam) ===

    public function addBlock(string $type): void
    {
        $definition = BlockRegistry::for($type);
        if ($definition === null) {
            return;
        }

        $this->mutate(function (NewsletterDocument $document) use ($type, $definition): NewsletterDocument {
            $blocks = $document->blocks();

            $new = [
                'id' => NewsletterDocument::newBlockId(),
                'type' => $type,
                'content' => $definition->defaultContent(),
                'styles' => [],
            ];

            // After the selection when there is one — a merchant adding a block
            // means "here", not "at the bottom of everything".
            $at = $this->selectedBlockId !== ''
                ? ($document->blockIndex($this->selectedBlockId) ?? count($blocks) - 1) + 1
                : count($blocks);

            array_splice($blocks, $at, 0, [$new]);

            $this->selectBlockAfterSave = $new['id'];

            return $document->withBlocks($blocks);
        });
    }

    public function removeBlock(string $id): void
    {
        $this->mutate(function (NewsletterDocument $document) use ($id): NewsletterDocument {
            $blocks = array_values(array_filter(
                $document->blocks(),
                static fn (array $block): bool => $block['id'] !== $id,
            ));

            if ($this->selectedBlockId === $id) {
                $this->deselect();
            }

            return $document->withBlocks($blocks);
        });
    }

    public function duplicateBlock(string $id): void
    {
        $this->mutate(function (NewsletterDocument $document) use ($id): NewsletterDocument {
            $index = $document->blockIndex($id);
            if ($index === null) {
                return $document;
            }

            $blocks = $document->blocks();
            $copy = $blocks[$index];
            $copy['id'] = NewsletterDocument::newBlockId();

            array_splice($blocks, $index + 1, 0, [$copy]);

            return $document->withBlocks($blocks);
        });
    }

    /** Server clamps the target — the FlowBuilder saveLayout law. */
    public function moveBlock(string $id, int $toIndex): void
    {
        $this->mutate(function (NewsletterDocument $document) use ($id, $toIndex): NewsletterDocument {
            $blocks = $document->blocks();
            $from = $document->blockIndex($id);

            if ($from === null) {
                return $document;
            }

            $to = max(0, min(count($blocks) - 1, $toIndex));

            [$block] = array_splice($blocks, $from, 1);
            array_splice($blocks, $to, 0, [$block]);

            return $document->withBlocks($blocks);
        });
    }

    /** The properties panel's save: the edited copy back through the guards. */
    public function saveBlock(): void
    {
        $id = $this->selectedBlockId;
        if ($id === '') {
            return;
        }

        // Fold the social form's flat map back into the ordered links list the
        // document (and its cleaner) speak. Empty fields simply are not links;
        // the leftover links_map key is ignored by the cleaner's allowlist.
        if (isset($this->blockContent['links_map']) && is_array($this->blockContent['links_map'])) {
            $links = [];
            foreach ($this->blockContent['links_map'] as $network => $url) {
                if (trim((string) $url) !== '') {
                    $links[] = ['network' => (string) $network, 'url' => (string) $url];
                }
            }
            $this->blockContent['links'] = $links;
        }

        $this->mutate(function (NewsletterDocument $document) use ($id): NewsletterDocument {
            $blocks = $document->blocks();

            foreach ($blocks as $index => $block) {
                if ($block['id'] === $id) {
                    // Raw form input — fromArray re-guards every value through
                    // the block's own cleaners, the same wall an AI payload hits.
                    $blocks[$index]['content'] = $this->blockContent;
                    $blocks[$index]['styles'] = $this->blockStyles;
                }
            }

            return $document->withBlocks($blocks);
        });

        // Re-fill the panel with the CLEANED values, so what the merchant sees
        // is what was actually stored.
        $this->selectBlock($id);
    }

    // === Campaign-level settings ===

    public function saveSettings(): void
    {
        $row = $this->editableCampaign();
        if ($row === null) {
            return;
        }

        // The subject stays a COLUMN (the whole existing pipeline reads it);
        // preheader + globals live on the document.
        $subject = mb_substr(trim($this->subject), 0, EmailCampaign::MAX_SUBJECT);
        if ($subject !== '' && $subject !== (string) $row->subject) {
            $row->forceFill(['subject' => $subject])->save();
        }

        $this->mutate(fn (NewsletterDocument $document): NewsletterDocument => $document
            ->withPreheader($this->preheader)
            ->withGlobals($this->globals));
    }

    public function restoreVersion(int $version): void
    {
        $row = $this->editableCampaign();
        if ($row === null) {
            return;
        }

        $result = app(DocumentService::class)->restore($row, $version, auth()->id());

        if ($result['ok']) {
            $this->refreshFromCampaign($row->refresh());
            Notification::make()->success()->title(__('studio.restored'))->send();
        }
    }

    // === Chat ===

    /** Is the AI half of the screen alive at all? The editor never depends on it. */
    public function aiAvailable(): bool
    {
        $settings = PlatformAiSettings::current();

        return $settings->isEnabled() && $settings->isConnected();
    }

    /** @return Collection<int, AiChatMessage> oldest first, for the panel */
    public function chatMessages(): Collection
    {
        return AiChatMessage::query()
            ->where('email_campaign_id', $this->campaignId)
            ->orderBy('id')
            ->limit(60)
            ->get();
    }

    public function sendChat(): void
    {
        $text = mb_substr(trim($this->chatInput), 0, 4000);
        if ($text === '' || ! $this->aiAvailable()) {
            return;
        }

        $row = $this->editableCampaign();
        if ($row === null) {
            return;
        }

        // ONE run in flight per campaign — a second ask mid-run would race the
        // first over the same document.
        $open = AiChatMessage::query()
            ->where('email_campaign_id', $row->getKey())
            ->whereIn('status', AiChatMessage::OPEN_STATUSES)
            ->exists();

        if ($open) {
            Notification::make()->warning()->title(__('studio.chat.busy'))->send();

            return;
        }

        $user = new AiChatMessage;
        $user->forceFill([
            'shop_id' => (int) $row->shop_id,
            'email_campaign_id' => (int) $row->getKey(),
            'role' => AiChatMessage::ROLE_USER,
            'status' => AiChatMessage::STATUS_SENT,
            'content' => $text,
            'created_by_user_id' => auth()->id(),
        ])->save();

        $runId = (string) Str::ulid();

        $assistant = new AiChatMessage;
        $assistant->forceFill([
            'shop_id' => (int) $row->shop_id,
            'email_campaign_id' => (int) $row->getKey(),
            'role' => AiChatMessage::ROLE_ASSISTANT,
            'status' => AiChatMessage::STATUS_PENDING,
            'run_id' => $runId,
            'selected_block_id' => $this->selectedBlockId !== '' ? $this->selectedBlockId : null,
            'created_by_user_id' => auth()->id(),
        ])->save();

        RunStudioChatJob::dispatch((int) $row->shop_id, (int) $row->getKey(), (int) $assistant->getKey());

        $this->chatInput = '';
        $this->activeRunId = $runId;
    }

    /** The wire:poll target — stops itself the moment the run settles. */
    public function pollChat(): void
    {
        if ($this->activeRunId === '') {
            return;
        }

        $row = AiChatMessage::query()->where('run_id', $this->activeRunId)->first();

        if ($row === null || ! in_array($row->status(), AiChatMessage::OPEN_STATUSES, true)) {
            $this->activeRunId = '';
        }
    }

    /** Approve one proposal: the dry-run result becomes the document — or stale. */
    public function approvePatch(int $messageId): void
    {
        $row = $this->editableCampaign();
        if ($row === null) {
            return;
        }

        $message = AiChatMessage::query()
            ->where('email_campaign_id', $row->getKey())
            ->find($messageId);

        if ($message === null || $message->status() !== AiChatMessage::STATUS_PROPOSED) {
            return;
        }

        // THE STALE WALL: ops computed against yesterday's document are never
        // merged over newer work — re-ask is the resolution.
        if ((int) $message->base_version !== (int) $row->document_version) {
            $message->markStale();
            Notification::make()->warning()->title(__('studio.chat.stale'))->send();

            return;
        }

        $service = app(DocumentService::class);
        $document = $service->documentFor($row) ?? NewsletterDocument::empty();

        $ops = [];
        foreach ((array) $message->ops as $raw) {
            if (is_array($raw) && ! isset($raw['rejected'])) {
                $ops[] = PatchOp::fromArray($raw);
            }
        }

        $outcome = (new PatchApplier)->apply($document, $ops, (bool) $row->is_marketing);

        $result = $service->save(
            $row,
            $outcome['document'],
            EmailCampaignDocumentVersion::CAUSE_AI_PATCH,
            auth()->id(),
            (int) $message->getKey(),
        );

        if (! $result['ok']) {
            Notification::make()->danger()->title(__('studio.refused.'.$result['reason']))->send();

            return;
        }

        // A subject op lands on the COLUMN, in the same approval.
        if ($outcome['subject'] !== null) {
            $row->forceFill(['subject' => $outcome['subject']])->save();
        }

        $message->markApplied($result['version']);
        $this->refreshFromCampaign($row->refresh());
        Notification::make()->success()->title(__('studio.chat.applied'))->send();
    }

    public function discardPatch(int $messageId): void
    {
        $message = AiChatMessage::query()
            ->where('email_campaign_id', $this->campaignId)
            ->find($messageId);

        $message?->markDiscarded();
    }

    /**
     * The proposal's per-op Hebrew lines, built server-side from the stored ops.
     *
     * @return list<array{line: string, rejected: bool}>
     */
    public function opLines(AiChatMessage $message): array
    {
        $document = $this->document();
        $lines = [];

        foreach ((array) $message->ops as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            if (isset($raw['rejected'])) {
                $lines[] = [
                    'line' => __('studio.chat.rejected_op', [
                        'op' => (string) ($raw['op'] ?? '?'),
                        'reason' => __('studio.chat.reject.'.$raw['rejected']),
                    ]),
                    'rejected' => true,
                ];

                continue;
            }

            $op = PatchOp::fromArray($raw);

            $blockLabel = '';
            if ($op->targetId !== null) {
                $block = $document->findBlock($op->targetId);
                $blockLabel = $block !== null ? (string) __('studio.block.'.$block['type']) : '';
            } elseif ($op->blockType !== null) {
                $blockLabel = (string) __('studio.block.'.$op->blockType);
            }

            $lines[] = [
                'line' => trim((string) __('studio.chat.op.'.$op->op, ['block' => $blockLabel])
                    .($op->reason !== '' ? ' — '.$op->reason : '')),
                'rejected' => false,
            ];
        }

        return $lines;
    }

    // === Internals ===

    /** Set inside a mutation that wants the new block selected after the save. */
    private ?string $selectBlockAfterSave = null;

    /**
     * Every document mutation goes through here: fresh row, editable check,
     * VERSION CHECK, the change, the one write seam, state refresh.
     *
     * @param  callable(NewsletterDocument): NewsletterDocument  $change
     */
    private function mutate(callable $change): void
    {
        $row = $this->editableCampaign();
        if ($row === null) {
            return;
        }

        // The optimistic-concurrency wall: this screen may only move the
        // document it was LOOKING at. Another tab (or an approved AI patch)
        // having moved it is a loud refresh, never a silent overwrite.
        if ((int) $row->document_version !== $this->knownVersion) {
            $this->refreshFromCampaign($row);
            Notification::make()->warning()->title(__('studio.stale'))->send();

            return;
        }

        $service = app(DocumentService::class);
        $document = $service->documentFor($row) ?? NewsletterDocument::empty();

        $result = $service->save(
            $row,
            $change($document),
            EmailCampaignDocumentVersion::CAUSE_MANUAL,
            auth()->id(),
        );

        if (! $result['ok']) {
            Notification::make()->danger()->title(__('studio.refused.'.$result['reason']))->send();

            return;
        }

        $this->knownVersion = $result['version'];

        if ($this->selectBlockAfterSave !== null) {
            $this->selectBlock($this->selectBlockAfterSave);
            $this->selectBlockAfterSave = null;
        }
    }

    /** Fresh row that may still be edited, or null (with the reason shown). */
    private function editableCampaign(): ?EmailCampaign
    {
        $row = $this->campaign();

        if ($row === null || ! $row->isStudio()) {
            return null;
        }

        if (! $row->isEditable()) {
            Notification::make()->warning()->title(__('studio.not_editable'))->send();

            return null;
        }

        return $row;
    }

    private function refreshFromCampaign(EmailCampaign $row): void
    {
        $this->knownVersion = (int) $row->document_version;
        $this->subject = (string) $row->subject;

        $document = app(DocumentService::class)->documentFor($row) ?? NewsletterDocument::empty();
        $this->preheader = $document->preheader();
        $this->globals = $document->globals();

        if ($this->selectedBlockId !== '' && $document->findBlock($this->selectedBlockId) === null) {
            $this->deselect();
        }
    }
}
