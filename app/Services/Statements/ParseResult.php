<?php

namespace App\Services\Statements;

/**
 * What a parser found in a statement: the lines and, when it could tell, the bank.
 */
final readonly class ParseResult
{
    /**
     * @param  list<ParsedLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $parser,
        public ?string $institution = null,
    ) {}

    /**
     * The earliest line date (Y-m-d).
     */
    public function periodStart(): ?string
    {
        $dates = array_map(fn (ParsedLine $line) => $line->date, $this->lines);

        return $dates === [] ? null : min($dates);
    }

    /**
     * The latest line date (Y-m-d).
     */
    public function periodEnd(): ?string
    {
        $dates = array_map(fn (ParsedLine $line) => $line->date, $this->lines);

        return $dates === [] ? null : max($dates);
    }
}
