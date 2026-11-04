<?php

namespace App\Enums;

/**
 * Every AI-assisted capability can be switched off on its own.
 */
enum AiFeature: string
{
    case Categorize = 'categorize';
    case Receipts = 'receipts';
    case QuickEntry = 'quick_entry';
    case Statements = 'statements';
    case Insights = 'insights';
}
