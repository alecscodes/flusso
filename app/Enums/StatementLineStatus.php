<?php

namespace App\Enums;

/**
 * Where a statement line stands against the transactions in the app.
 */
enum StatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Imported = 'imported';
    case Ignored = 'ignored';
}
