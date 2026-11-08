<?php

namespace App\Services\Statements;

/**
 * Deterministic parsers first; the model-assisted fallback only for text they
 * could not read, and run separately because it can take minutes. A statement
 * with no readable lines is still kept, just without lines.
 */
class StatementParser
{
    public const MAX_LINES = 20000;

    public function __construct(
        private readonly RevolutCsvParser $revolut,
        private readonly GenericCsvParser $genericCsv,
        private readonly PdfLineParser $pdf,
        private readonly AiLineParser $ai,
    ) {}

    /**
     * The result of the deterministic parsers: parser 'none' without lines when none could read the text.
     */
    public function parse(string $text, bool $isCsv): ParseResult
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

        return new ParseResult([], 'none');
    }

    public function canUseAi(): bool
    {
        return $this->ai->isAvailable();
    }

    /**
     * Ask the model to read text the deterministic parsers could not. Null when it found nothing usable.
     */
    public function parseWithAi(string $text): ?ParseResult
    {
        $result = $this->ai->parse($text);

        return $result !== null ? $this->capped($result) : null;
    }

    private function capped(ParseResult $result): ParseResult
    {
        if (count($result->lines) <= self::MAX_LINES) {
            return $result;
        }

        return new ParseResult(array_slice($result->lines, 0, self::MAX_LINES), $result->parser, $result->institution, true, $result->dateOrderAssumed);
    }
}
