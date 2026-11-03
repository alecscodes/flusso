<?php

namespace App\Services\Receipts;

use RuntimeException;

/**
 * A scan that cannot produce a proposal; the message is safe to show to the owner.
 */
class ReceiptScanException extends RuntimeException {}
