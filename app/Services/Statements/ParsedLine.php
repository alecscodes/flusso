<?php

namespace App\Services\Statements;

/**
 * One transaction read from a statement, before it is stored.
 */
final readonly class ParsedLine
{
    /**
     * @param  string  $date  Y-m-d
     * @param  string  $amount  signed, two decimals (negative is money out)
     * @param  string  $parsedBy  revolut, csv, pdf or ai
     */
    public function __construct(
        public string $date,
        public string $description,
        public string $amount,
        public ?string $currency,
        public string $raw,
        public string $parsedBy,
    ) {}
}
