<?php

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stage's owner-edited prompt. A row WINS over the config default for its
 * stage; deleting the text is "reset to default". Platform-level, no tenant —
 * prompts steer every shop's generation and belong to the house.
 */
class AiPrompt extends Model
{
    // === CONSTANTS ===
    protected $table = 'ai_prompts';

    /** The pipeline stages an owner may steer. */
    public const STAGE_DRAFT_GENERATOR = 'draft_generator';

    public const STAGE_BLOCK_EDITOR = 'block_editor';

    public const STAGE_SUBJECT_WRITER = 'subject_writer';

    public const STAGE_BRAND_ANALYZER = 'brand_analyzer';

    public const STAGES = [
        self::STAGE_DRAFT_GENERATOR,
        self::STAGE_BLOCK_EDITOR,
        self::STAGE_SUBJECT_WRITER,
        self::STAGE_BRAND_ANALYZER,
    ];

    protected $guarded = ['id'];
}
