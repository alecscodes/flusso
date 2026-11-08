<?php

namespace App\Enums;

/**
 * Where the reading of a statement stands: the deterministic parsers answer at
 * once, the model-assisted fallback runs in the background.
 */
enum StatementParseStatus: string
{
    case Done = 'done';
    case PendingAi = 'pending_ai';
    case AiFailed = 'ai_failed';
}
