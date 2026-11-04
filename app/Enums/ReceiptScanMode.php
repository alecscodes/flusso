<?php

namespace App\Enums;

/**
 * How a scanned receipt becomes transactions: one for the total, or one per category of its lines.
 */
enum ReceiptScanMode: string
{
    case Total = 'total';
    case Split = 'split';
}
