<?php

namespace App\Domain\Campaigns\Studio;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;
use App\Domain\Campaigns\Studio\Models\EmailCampaignDocumentVersion;
use App\Domain\Campaigns\Studio\Render\EmailRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE write seam for studio documents. Nothing else writes `document`,
 * `document_version`, or a version row — not the page, not the AI pipeline.
 *
 * One save is one transaction that does four things in lockstep: guard the
 * document, COMPILE it into the body columns the whole existing pipeline
 * reads, bump the version, snapshot. Because the compile happens inside the
 * save, `body_html` can never drift from `document` — a studio campaign's
 * preview, test send and real send are always the artifact of the document
 * the merchant is looking at.
 *
 * A compile that would overflow EmailCampaign::MAX_BODY REFUSES the save —
 * a truncated email is a broken promise (a lost unsubscribe link, at worst),
 * and refusing loudly is the only honest answer.
 *
 * Undo is restore, restore is a new version — history is append-only, so
 * "redo" is just restoring forward and an audit can always answer what the
 * document said at any moment.
 */
final class DocumentService
{
    // === CONSTANTS ===
    /** Snapshots kept per campaign. Beyond this the oldest quietly go. */
    public const MAX_VERSIONS = 50;

    /** Save refusal reasons (the screen translates). */
    public const REFUSED_TOO_LARGE = 'too_large';

    public function __construct(private readonly EmailRenderer $renderer = new EmailRenderer) {}

    /**
     * Seed a fresh campaign with the starter document and its first version.
     * Idempotent: a campaign that already has a document is left alone.
     */
    public function initFor(EmailCampaign $campaign, ?int $userId = null): NewsletterDocument
    {
        $existing = $this->documentFor($campaign);
        if ($existing !== null) {
            return $existing;
        }

        $starter = $this->starterDocument();
        $this->save($campaign, $starter, EmailCampaignDocumentVersion::CAUSE_INIT, $userId);

        return $starter;
    }

    /** The campaign's document, guarded on the way out; null = not a studio row. */
    public function documentFor(EmailCampaign $campaign): ?NewsletterDocument
    {
        $raw = $campaign->document;

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return NewsletterDocument::fromArray($raw);
    }

    /**
     * Persist a new state: compile, bump, snapshot — or refuse with a reason.
     *
     * @return array{ok: bool, reason: ?string, version: int}
     */
    public function save(
        EmailCampaign $campaign,
        NewsletterDocument $document,
        string $cause,
        ?int $userId = null,
        ?int $chatMessageId = null,
    ): array {
        $rendered = $this->renderer->render($document);

        if (mb_strlen($rendered->html) > EmailCampaign::MAX_BODY) {
            return ['ok' => false, 'reason' => self::REFUSED_TOO_LARGE, 'version' => (int) $campaign->document_version];
        }

        $cause = in_array($cause, EmailCampaignDocumentVersion::CAUSES, true)
            ? $cause
            : EmailCampaignDocumentVersion::CAUSE_MANUAL;

        $version = DB::transaction(function () use ($campaign, $document, $rendered, $cause, $userId, $chatMessageId): int {
            $version = ((int) $campaign->document_version) + 1;

            $campaign->forceFill([
                'document' => $document->toArray(),
                'document_version' => $version,
                'body_html' => $rendered->html,
                'body_text' => $rendered->text,
            ])->save();

            $snapshot = new EmailCampaignDocumentVersion;
            $snapshot->forceFill([
                'shop_id' => (int) $campaign->shop_id,
                'email_campaign_id' => (int) $campaign->getKey(),
                'version' => $version,
                'document' => $document->toArray(),
                'cause' => $cause,
                'created_by_user_id' => $userId,
                'ai_chat_message_id' => $chatMessageId,
            ])->save();

            $this->prune($campaign);

            return $version;
        });

        return ['ok' => true, 'reason' => null, 'version' => $version];
    }

    /**
     * Bring back an earlier state — as a NEW version, so history only grows.
     *
     * @return array{ok: bool, reason: ?string, version: int}
     */
    public function restore(EmailCampaign $campaign, int $version, ?int $userId = null): array
    {
        $snapshot = EmailCampaignDocumentVersion::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->where('version', $version)
            ->first();

        if ($snapshot === null) {
            return ['ok' => false, 'reason' => 'not_found', 'version' => (int) $campaign->document_version];
        }

        return $this->save(
            $campaign,
            NewsletterDocument::fromArray((array) $snapshot->document),
            EmailCampaignDocumentVersion::CAUSE_RESTORE,
            $userId,
        );
    }

    /**
     * The history, newest first, for the versions drawer.
     *
     * @return Collection<int, EmailCampaignDocumentVersion>
     */
    public function versionsFor(EmailCampaign $campaign, int $limit = 30)
    {
        return EmailCampaignDocumentVersion::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->orderByDesc('version')
            ->limit(max(1, $limit))
            ->get();
    }

    // === Internals ===

    /**
     * The document a fresh studio campaign opens on: a heading, a paragraph,
     * a button, a footer — enough shape to edit into, never a blank stare.
     */
    private function starterDocument(): NewsletterDocument
    {
        $registry = BlockRegistry::all();

        $block = static function (string $type, array $content = []) use ($registry): array {
            $definition = $registry[$type];

            return [
                'id' => NewsletterDocument::newBlockId(),
                'type' => $type,
                'content' => $content + $definition->defaultContent(),
                'styles' => [],
            ];
        };

        return NewsletterDocument::fromArray([
            'globals' => NewsletterDocument::DEFAULT_GLOBALS,
            'blocks' => [
                $block('heading', ['text' => (string) __('studio.starter.heading')]),
                $block('text', ['html' => '<p>'.e(__('studio.starter.text')).'</p>']),
                $block('button', ['label' => (string) __('studio.starter.button'), 'url' => '{account_login_url}']),
                $block('footer'),
            ],
        ]);
    }

    /** Keep the newest MAX_VERSIONS snapshots; the rest served their purpose. */
    private function prune(EmailCampaign $campaign): void
    {
        $cutoff = EmailCampaignDocumentVersion::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->orderByDesc('version')
            ->skip(self::MAX_VERSIONS)
            ->value('version');

        if ($cutoff !== null) {
            EmailCampaignDocumentVersion::query()
                ->where('email_campaign_id', $campaign->getKey())
                ->where('version', '<=', (int) $cutoff)
                ->delete();
        }
    }
}
