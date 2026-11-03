<?php

namespace App\Services\Statements;

/**
 * Deterministic parsers first; the model only for text they could not read.
 * A statement with no readable lines is still kept, just without lines.
 */
class StatementParser
{
    public const MAX_LINES = 5000;

    public function __construct(
        private readonly RevolutCsvParser $revolut,
        private readonly GenericCsvParser $genericCsv,
        private readonly PdfLineParser $pdf,
        private readonly AiLineParser $ai,
    ) {}

    public function parse(string $text, bool $isCsv, int $aiChunks = AiLineParser::MAX_CHUNKS): ParseResult
    {
        $attempts = $isCsv
            ? [fn () => $this->revolut->parse($text), fn () => $this->genericCsv->parse($text)]
            : [fn () => $this->pdf->parse($text)];

        foreach ($attempts as $attempt) {
            $result = $attempt();

            if ($result !== null && $result->lines !== []) {
                return $this->capped($result);
            }
        }

        $result = $this->ai->parse($text, $aiChunks);

        return $result !== null ? $this->capped($result) : new ParseResult([], 'none');
    }

    private function capped(ParseResult $result): ParseResult
    {
        if (count($result->lines) <= self::MAX_LINES) {
            return $result;
        }

        return new ParseResult(array_slice($result->lines, 0, self::MAX_LINES), $result->parser, $result->institution);
    }
}
