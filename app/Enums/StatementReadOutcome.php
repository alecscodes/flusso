<?php

namespace App\Enums;

/**
 * What reading a statement's file again did to its lines.
 */
enum StatementReadOutcome: string
{
    /** The lines were replaced by what was read. */
    case Replaced = 'replaced';

    /** Nothing could be read this time, so the lines it had stay as they were. */
    case Kept = 'kept';

    /** The owner imported, ignored or checked a line, so nothing was touched. */
    case Refused = 'refused';
}
