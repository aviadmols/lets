<?php

namespace App\Domain\Campaigns\Studio\Ops;

use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;

/**
 * Raw model output → a list of ops worth showing a merchant.
 *
 * PER-OP, never all-or-nothing: one malformed op is dropped WITH its reason
 * while its siblings survive — a model that got nine of ten changes right
 * should not have the nine thrown away. What survives here is only SHAPE
 * (known verb, sane fields); the payload's VALUES are cleaned later by the
 * block definitions inside PatchApplier, the same wall a human's keystrokes
 * hit. Nothing here throws.
 */
final class OpValidator
{
    // === CONSTANTS ===
    /** More ops than blocks-cap is a runaway answer, not a plan. */
    public const MAX_OPS = 40;

    /** Rejection reason codes (the chat card translates). */
    public const REJECT_UNKNOWN_OP = 'unknown_op';

    public const REJECT_UNKNOWN_TYPE = 'unknown_type';

    public const REJECT_MISSING_TARGET = 'missing_target';

    public const REJECT_BAD_PAYLOAD = 'bad_payload';

    /**
     * @param  mixed  $raw  the tool input's `ops` value, as the model sent it
     * @return array{ops: list<PatchOp>, rejected: list<array{op: string, reason: string}>}
     */
    public function validate(mixed $raw): array
    {
        $ops = [];
        $rejected = [];

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (count($ops) >= self::MAX_OPS) {
                break;
            }

            if (! is_array($item)) {
                $rejected[] = ['op' => '?', 'reason' => self::REJECT_BAD_PAYLOAD];

                continue;
            }

            $op = is_string($item['op'] ?? null) ? $item['op'] : '';

            if (! in_array($op, PatchOp::OPS, true)) {
                // The one law that matters most, stated where it bites: a verb
                // outside the vocabulary — send, delete, anything — dies here.
                $rejected[] = ['op' => $op !== '' ? $op : '?', 'reason' => self::REJECT_UNKNOWN_OP];

                continue;
            }

            $targetId = is_string($item['target_id'] ?? null) ? trim($item['target_id']) : '';

            if (in_array($op, PatchOp::TARGETED_OPS, true) && $targetId === '') {
                $rejected[] = ['op' => $op, 'reason' => self::REJECT_MISSING_TARGET];

                continue;
            }

            $blockType = null;
            if ($op === PatchOp::OP_ADD_BLOCK) {
                $blockType = is_string($item['block_type'] ?? null) ? $item['block_type'] : '';
                if (BlockRegistry::for($blockType) === null) {
                    $rejected[] = ['op' => $op, 'reason' => self::REJECT_UNKNOWN_TYPE];

                    continue;
                }
            }

            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];

            // set_subject / set_preheader carry their text in the payload; an
            // empty one is a no-op not worth a card line.
            if (in_array($op, [PatchOp::OP_SET_SUBJECT, PatchOp::OP_SET_PREHEADER], true)
                && trim((string) ($payload['text'] ?? '')) === '') {
                $rejected[] = ['op' => $op, 'reason' => self::REJECT_BAD_PAYLOAD];

                continue;
            }

            $ops[] = new PatchOp(
                op: $op,
                targetId: $targetId !== '' ? $targetId : null,
                payload: $payload,
                reason: mb_substr(trim((string) ($item['reason'] ?? '')), 0, 300),
                confidence: max(0.0, min(1.0, (float) ($item['confidence'] ?? 0.5))),
                blockType: $blockType,
                position: isset($item['position']) && is_numeric($item['position']) ? (int) $item['position'] : null,
            );
        }

        return ['ops' => $ops, 'rejected' => $rejected];
    }
}
