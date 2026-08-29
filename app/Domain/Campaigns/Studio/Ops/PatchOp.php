<?php

namespace App\Domain\Campaigns\Studio\Ops;

/**
 * One proposed change, as data.
 *
 * OPS is the ENTIRE vocabulary a model may speak — and, deliberately, it
 * contains NO send, schedule, cancel or delete-campaign verb. The AI cannot do
 * what the list cannot say; that absence is pinned by test, and it is the
 * structural half of the spec's "AI never sends" law (the other half being
 * that PatchApplier only ever touches the document and the subject).
 */
final readonly class PatchOp
{
    // === CONSTANTS ===
    public const OP_ADD_BLOCK = 'add_block';

    public const OP_UPDATE_BLOCK_CONTENT = 'update_block_content';

    public const OP_UPDATE_BLOCK_STYLE = 'update_block_style';

    public const OP_MOVE_BLOCK = 'move_block';

    public const OP_DUPLICATE_BLOCK = 'duplicate_block';

    public const OP_REMOVE_BLOCK = 'remove_block';

    public const OP_SET_GLOBAL_STYLE = 'set_global_style';

    public const OP_SET_PREHEADER = 'set_preheader';

    public const OP_SET_SUBJECT = 'set_subject';

    /** The whole vocabulary. No send. No schedule. No delete-campaign. */
    public const OPS = [
        self::OP_ADD_BLOCK,
        self::OP_UPDATE_BLOCK_CONTENT,
        self::OP_UPDATE_BLOCK_STYLE,
        self::OP_MOVE_BLOCK,
        self::OP_DUPLICATE_BLOCK,
        self::OP_REMOVE_BLOCK,
        self::OP_SET_GLOBAL_STYLE,
        self::OP_SET_PREHEADER,
        self::OP_SET_SUBJECT,
    ];

    /** The ops that must name an existing block. */
    public const TARGETED_OPS = [
        self::OP_UPDATE_BLOCK_CONTENT,
        self::OP_UPDATE_BLOCK_STYLE,
        self::OP_MOVE_BLOCK,
        self::OP_DUPLICATE_BLOCK,
        self::OP_REMOVE_BLOCK,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $op,
        public ?string $targetId,
        public array $payload,
        public string $reason,
        public float $confidence,
        /** add_block only: the block type; position rides the payload. */
        public ?string $blockType = null,
        public ?int $position = null,
    ) {}

    /** @return array<string, mixed> the storable shape (the chat row's `ops`) */
    public function toArray(): array
    {
        return [
            'op' => $this->op,
            'target_id' => $this->targetId,
            'payload' => $this->payload,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'block_type' => $this->blockType,
            'position' => $this->position,
        ];
    }

    /** @param array<string, mixed> $raw a stored toArray() shape, trusted */
    public static function fromArray(array $raw): self
    {
        return new self(
            op: (string) ($raw['op'] ?? ''),
            targetId: isset($raw['target_id']) ? (string) $raw['target_id'] : null,
            payload: is_array($raw['payload'] ?? null) ? $raw['payload'] : [],
            reason: (string) ($raw['reason'] ?? ''),
            confidence: (float) ($raw['confidence'] ?? 0),
            blockType: isset($raw['block_type']) ? (string) $raw['block_type'] : null,
            position: isset($raw['position']) ? (int) $raw['position'] : null,
        );
    }
}
