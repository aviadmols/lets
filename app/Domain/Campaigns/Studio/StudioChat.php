<?php

namespace App\Domain\Campaigns\Studio;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\Models\AiPrompt;
use App\Domain\Brand\Models\ShopBrandProfile;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;
use App\Domain\Campaigns\Studio\Models\AiChatMessage;
use App\Domain\Campaigns\Studio\Ops\OpValidator;
use App\Domain\Campaigns\Studio\Ops\PatchApplier;
use App\Domain\Campaigns\Studio\Ops\PatchOp;

/**
 * One chat turn, orchestrated: pick the stage, assemble the context, call the
 * gateway, validate the shape, DRY-RUN the ops, write the proposal.
 *
 * STAGE ROUTING IS DETERMINISTIC — no intent-classifier call. A block selected
 * means block_editor; an (almost) empty document means draft_generator;
 * everything else is block_editor over the whole document. A routing rule that
 * is visibly wrong beats a second model call that is invisibly wrong, doubles
 * the latency and bills twice.
 *
 * THE DRY RUN IS THE HONESTY: ops are applied to a copy before they are ever
 * proposed, so the card's per-op lines describe changes that actually apply,
 * and application-time rejections (a vanished target, the last footer) are on
 * the card too — not discovered at approval.
 */
final class StudioChat
{
    // === CONSTANTS ===
    public const TOOL_NAME = 'propose_newsletter_patch';

    /** Chat turns sent back as context. The document itself carries the state. */
    public const HISTORY_TURNS = 6;

    /** A document at or under this many blocks is a brief, not an edit target. */
    private const DRAFT_THRESHOLD = 0;

    public function __construct(
        private readonly AiGateway $gateway = new AiGateway,
        private readonly OpValidator $validator = new OpValidator,
        private readonly PatchApplier $applier = new PatchApplier,
    ) {}

    /**
     * Run one assistant turn. The row moves to proposed/failed; nothing else
     * is written — application happens only at the merchant's approval.
     */
    public function run(EmailCampaign $campaign, AiChatMessage $message, NewsletterDocument $document): void
    {
        $baseVersion = (int) $campaign->document_version;

        $result = $this->gateway->complete(new AiRequest(
            stage: $this->stageFor($message, $document),
            shopId: (int) $campaign->shop_id,
            messages: $this->messages($campaign, $message, $document),
            toolName: self::TOOL_NAME,
            toolSchema: self::toolSchema(),
            campaignId: (int) $campaign->getKey(),
        ));

        if (! $result->ok) {
            $message->fail((string) $result->failureReason);

            return;
        }

        $explanation = mb_substr(trim((string) ($result->toolInput['explanation'] ?? '')), 0, 4000);
        $validated = $this->validator->validate($result->toolInput['ops'] ?? []);

        // The dry run — against the SNAPSHOT the ops were computed for.
        $outcome = $this->applier->apply($document, $validated['ops'], (bool) $campaign->is_marketing);

        if ($outcome['applied'] === []) {
            // Nothing survived shape + application. The explanation still
            // reaches the merchant — "I need to know X" is a real answer.
            $message->fail(AiResult::FAIL_BAD_TOOL_OUTPUT, $explanation !== '' ? $explanation : null);

            return;
        }

        $rejectedNotes = array_merge($validated['rejected'], $outcome['rejected']);

        $message->propose(
            explanation: $explanation,
            ops: array_merge(
                array_map(static fn (PatchOp $op): array => $op->toArray(), $outcome['applied']),
                // Rejections ride the same list, flagged — the card shows both.
                array_map(static fn (array $r): array => ['op' => $r['op'], 'rejected' => $r['reason']], $rejectedNotes),
            ),
            baseVersion: $baseVersion,
        );
    }

    // === Context assembly ===

    private function stageFor(AiChatMessage $message, NewsletterDocument $document): string
    {
        // A quick action stamped its stage on the row — the button IS the
        // intent, so the router honors it before its own rules. Only known
        // stamps count; anything else falls through to the document rules.
        if ($message->stage_hint === AiPrompt::STAGE_SUBJECT_WRITER) {
            return AiPrompt::STAGE_SUBJECT_WRITER;
        }

        if (count($document->blocks()) <= self::DRAFT_THRESHOLD) {
            return 'draft_generator';
        }

        return 'block_editor';
    }

    /**
     * The turns the model sees: the working context as a first user message
     * (document + vocabulary + scope), recent history, then the ask.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(EmailCampaign $campaign, AiChatMessage $message, NewsletterDocument $document): array
    {
        $context = [
            'subject' => (string) $campaign->subject,
            'is_marketing' => (bool) $campaign->is_marketing,
            'selected_block_id' => $message->selected_block_id,
            'variables' => VariableRegistry::wrapped(),
            'block_types' => BlockRegistry::types(),
            'document' => $document->toArray(),
        ];

        // An APPROVED brand rides along — already re-guarded values only, so
        // the draft speaks the shop's colors and tone without being told.
        $brand = ShopBrandProfile::forShop((int) $campaign->shop_id);
        if ($brand !== null && $brand->isApproved()) {
            $context['brand'] = array_filter([
                'globals' => $brand->dnaGlobals(),
                'tone' => $brand->tone(),
            ]);
        }

        $turns = [[
            'role' => 'user',
            'content' => "מצב הקמפיין הנוכחי (JSON):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]];

        // The ask itself: the user row that spawned this assistant row.
        $ask = AiChatMessage::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->where('role', AiChatMessage::ROLE_USER)
            ->where('id', '<', $message->getKey())
            ->orderByDesc('id')
            ->first();

        // Recent conversation BEFORE the ask, oldest first, so "עוד קצת"
        // means something — and the ask itself is not sent twice.
        $history = AiChatMessage::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->where('id', '<', (int) ($ask?->getKey() ?? $message->getKey()))
            ->whereIn('status', [
                AiChatMessage::STATUS_SENT,
                AiChatMessage::STATUS_APPLIED,
                AiChatMessage::STATUS_PROPOSED,
                AiChatMessage::STATUS_DISCARDED,
            ])
            ->orderByDesc('id')
            ->limit(self::HISTORY_TURNS)
            ->get()
            ->reverse();

        foreach ($history as $turn) {
            $content = trim((string) $turn->content);
            if ($content === '') {
                continue;
            }

            $turns[] = [
                'role' => $turn->role === AiChatMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => mb_substr($content, 0, 2000),
            ];
        }

        $turns[] = [
            'role' => 'user',
            'content' => mb_substr(trim((string) ($ask?->content ?? '')), 0, 4000),
        ];

        return $turns;
    }

    /**
     * The forced tool's input schema — the shape the model MUST answer in.
     *
     * @return array<string, mixed>
     */
    public static function toolSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['explanation', 'ops'],
            'properties' => [
                'explanation' => [
                    'type' => 'string',
                    'description' => 'הסבר קצר בעברית למשתמש: מה שינית ולמה.',
                ],
                'ops' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['op'],
                        'properties' => [
                            'op' => ['type' => 'string', 'enum' => PatchOp::OPS],
                            'target_id' => ['type' => 'string', 'description' => 'block id, לפעולות על בלוק קיים'],
                            'block_type' => ['type' => 'string', 'enum' => BlockRegistry::types()],
                            'position' => ['type' => 'integer'],
                            'payload' => ['type' => 'object', 'description' => 'התוכן/הסגנון; ל-set_subject/set_preheader: {text}'],
                            'reason' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
