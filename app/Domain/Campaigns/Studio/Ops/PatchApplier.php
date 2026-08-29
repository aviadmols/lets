<?php

namespace App\Domain\Campaigns\Studio\Ops;

use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;
use App\Domain\Campaigns\Studio\NewsletterDocument;

/**
 * Ops → a new document. PURE: no I/O, no clock, no state — which is what
 * makes the dry run honest: the proposal card's before/after IS this function
 * over a copy, and an approval is the same function over the real thing.
 *
 * REJECTS, NEVER THROWS. An op that cannot apply (a vanished target, a cap
 * about to overflow, the last footer of a marketing document) is set aside
 * with its reason while its siblings land — and every payload passes through
 * the block definitions' own cleaners, so an AI payload and a human keystroke
 * hit the same wall.
 *
 * Only the document and the subject can come out of here. There is no path
 * from an op to a send, a schedule, or a delete — by construction.
 */
final class PatchApplier
{
    // === CONSTANTS ===
    /** Application-time rejection reasons (the chat card translates). */
    public const REJECT_TARGET_GONE = 'target_gone';

    public const REJECT_TOO_MANY_BLOCKS = 'too_many_blocks';

    public const REJECT_LAST_FOOTER = 'last_footer';

    /**
     * @param  list<PatchOp>  $ops
     * @return array{
     *     document: NewsletterDocument,
     *     subject: ?string,
     *     applied: list<PatchOp>,
     *     rejected: list<array{op: string, reason: string}>,
     * }
     */
    public function apply(NewsletterDocument $document, array $ops, bool $isMarketing): array
    {
        $subject = null;
        $applied = [];
        $rejected = [];

        foreach ($ops as $op) {
            $outcome = $this->applyOne($document, $op, $isMarketing);

            if (is_string($outcome)) {
                $rejected[] = ['op' => $op->op, 'reason' => $outcome];

                continue;
            }

            [$document, $subjectChange] = $outcome;
            if ($subjectChange !== null) {
                $subject = $subjectChange;
            }
            $applied[] = $op;
        }

        return ['document' => $document, 'subject' => $subject, 'applied' => $applied, 'rejected' => $rejected];
    }

    /**
     * One op onto one document — the new state, or a rejection reason.
     *
     * @return array{0: NewsletterDocument, 1: ?string}|string
     */
    private function applyOne(NewsletterDocument $document, PatchOp $op, bool $isMarketing): array|string
    {
        $blocks = $document->blocks();

        switch ($op->op) {
            case PatchOp::OP_SET_SUBJECT:
                return [$document, mb_substr(trim((string) $op->payload['text']), 0, 255)];

            case PatchOp::OP_SET_PREHEADER:
                return [$document->withPreheader((string) $op->payload['text']), null];

            case PatchOp::OP_SET_GLOBAL_STYLE:
                // fromArray re-guards every global — an unknown key or a bad
                // color simply does not land.
                return [$document->withGlobals($op->payload), null];

            case PatchOp::OP_ADD_BLOCK:
                if (count($blocks) >= NewsletterDocument::MAX_BLOCKS) {
                    return self::REJECT_TOO_MANY_BLOCKS;
                }

                $definition = BlockRegistry::for((string) $op->blockType);
                if ($definition === null) {
                    return OpValidator::REJECT_UNKNOWN_TYPE;
                }

                $new = [
                    // SERVER-minted — the AI never names an id into existence.
                    'id' => NewsletterDocument::newBlockId(),
                    'type' => $op->blockType,
                    'content' => $definition->cleanContent($op->payload),
                    'styles' => [],
                ];

                $at = $op->position !== null
                    ? max(0, min(count($blocks), $op->position))
                    : count($blocks);

                array_splice($blocks, $at, 0, [$new]);

                return [$document->withBlocks($blocks), null];

            case PatchOp::OP_UPDATE_BLOCK_CONTENT:
            case PatchOp::OP_UPDATE_BLOCK_STYLE:
                $index = $document->blockIndex((string) $op->targetId);
                if ($index === null) {
                    return self::REJECT_TARGET_GONE;
                }

                $key = $op->op === PatchOp::OP_UPDATE_BLOCK_CONTENT ? 'content' : 'styles';

                // MERGED over the existing bag, then re-guarded by the block's
                // own cleaner inside withBlocks — a partial update keeps what
                // it did not mention, and a poisoned value dies at the wall.
                $blocks[$index][$key] = $op->payload + $blocks[$index][$key];

                return [$document->withBlocks($blocks), null];

            case PatchOp::OP_MOVE_BLOCK:
                $index = $document->blockIndex((string) $op->targetId);
                if ($index === null) {
                    return self::REJECT_TARGET_GONE;
                }

                $to = max(0, min(count($blocks) - 1, (int) ($op->position ?? $index)));
                [$moved] = array_splice($blocks, $index, 1);
                array_splice($blocks, $to, 0, [$moved]);

                return [$document->withBlocks($blocks), null];

            case PatchOp::OP_DUPLICATE_BLOCK:
                $index = $document->blockIndex((string) $op->targetId);
                if ($index === null) {
                    return self::REJECT_TARGET_GONE;
                }
                if (count($blocks) >= NewsletterDocument::MAX_BLOCKS) {
                    return self::REJECT_TOO_MANY_BLOCKS;
                }

                $copy = $blocks[$index];
                $copy['id'] = NewsletterDocument::newBlockId();
                array_splice($blocks, $index + 1, 0, [$copy]);

                return [$document->withBlocks($blocks), null];

            case PatchOp::OP_REMOVE_BLOCK:
                $index = $document->blockIndex((string) $op->targetId);
                if ($index === null) {
                    return self::REJECT_TARGET_GONE;
                }

                // The unsubscribe law, enforced where a removal happens: a
                // marketing document must keep at least one footer.
                if ($isMarketing
                    && $blocks[$index]['type'] === 'footer'
                    && $document->countBlocksOf('footer') <= 1) {
                    return self::REJECT_LAST_FOOTER;
                }

                array_splice($blocks, $index, 1);

                return [$document->withBlocks($blocks), null];
        }

        return OpValidator::REJECT_UNKNOWN_OP;
    }
}
