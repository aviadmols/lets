<?php

// Platform → AI. The owner's screen: the model account every studio chat runs
// on, the kill switch, the budget and the prompts.
// Mirror of lang/he/platform_ai.php — every key must exist in both files.
return [

    'title' => 'AI',
    'intro' => 'Every shop\'s newsletter studio runs on one AI account — yours. The key, the kill switch, the daily token budget and the actual spend live here.',

    'account' => [
        'heading' => 'The model account',
        'intro' => 'The key is stored encrypted and never shown again. It can also be an environment variable (ANTHROPIC_API_KEY) — a key saved here wins.',

        'state' => 'State',
        'state_off' => 'Not connected — the studio chat is dark; the block editor keeps working.',
        'state_on_saved' => 'Connected (key stored in the system).',
        'state_on_env' => 'Connected through the ANTHROPIC_API_KEY environment variable.',

        'key' => 'Anthropic API key',
        'key_help' => 'From console.anthropic.com → API Keys. Pasted once, stored encrypted.',
        'key_stored' => 'A key is already stored. Leave blank to keep it, or paste a new one to replace it.',

        'enabled' => 'Chat is on',
        'enabled_help' => 'The platform-wide kill switch: off, and the chat disappears from every shop at once while the block editor keeps working.',

        'budget' => 'Daily token budget',
        'budget_help' => 'Total tokens (input + output) all shops may spend per day. Blank = uncapped. Over budget, the chat politely says the quota is done for today.',
    ],

    'usage' => [
        'heading' => 'Usage',
        'today' => 'Tokens today',
        'window' => 'Tokens, last :days days',
        'failures' => 'Failed calls (:days days)',
    ],

    'prompts' => [
        'title' => 'AI prompts',
        'intro' => 'What each stage says to the model. A blank field means the shipped default (shown as the placeholder). Clearing the text is the reset.',
        'prompt' => 'Prompt',
        'prompt_help' => 'The system instructions for this stage. Blank = the default.',
        'model' => 'Model (optional)',
        'model_help' => 'A model id for this stage only. Blank = the global model.',
        'saved' => 'Prompts saved.',
    ],

    'stage' => [
        'draft_generator' => 'Draft generation',
        'draft_generator_help' => 'Builds a whole newsletter from the merchant\'s short brief.',
        'block_editor' => 'Block editing',
        'block_editor_help' => 'Changes a selected block (or the document) per a chat request.',
        'subject_writer' => 'Subject line',
        'subject_writer_help' => 'Suggests subject lines for a campaign.',
        'brand_analyzer' => 'Brand analysis',
        'brand_analyzer_help' => 'Summarises colors, fonts and tone out of scraped site data.',
    ],

    'actions' => [
        'save' => 'Save',
    ],

    'saved' => 'Settings saved.',
];
