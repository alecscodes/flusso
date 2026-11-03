<?php

namespace App\Services\Statements;

/**
 * Reads the text of a PDF statement (as `pdftotext -layout` prints it) line by
 * line: a date, an optional second date, a description and one or two amounts
 * (the transaction, then the running balance). Amounts are taken as printed, so a
 * statement with separate unsigned debit and credit columns reads as positive.
 */
class PdfLineParser
{
    private const SUMMARY_LINE = '/^(saldo|balance|totale|total|opening|closing|riporto|brought forward|carried forward)\b/iu';

    public function parse(string $text): ?ParseResult
    {
        $date = Normalizer::DATE_PATTERN;
        $amount = Normalizer::AMOUNT_PATTERN;
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $rawLine));

            if (preg_match("/^({$date})(?:\\s+({$date}))?\\s+(.+)\$/iu", $line, $matches) !== 1) {
                continue;
            }

            $posted = Normalizer::date($matches[1]);
            $rest = $matches[3];
            $amounts = [];

            while (count($amounts) < 2 && preg_match("/^(.*?)\\s+({$amount})(?:\\s?(?:EUR|RON|USD|GBP|€))?\$/iu", $rest, $found) === 1) {
                array_unshift($amounts, $found[2]);
                $rest = $found[1];
            }

            $description = trim($rest);

            if ($posted === null || $amounts === [] || preg_match('/\p{L}/u', $description) !== 1 || preg_match(self::SUMMARY_LINE, $description) === 1) {
                continue;
            }

            $value = Normalizer::amount($amounts[0]);

            if ($value === null) {
                continue;
            }

            $lines[] = new ParsedLine($posted, mb_substr($description, 0, 500), $value, null, $line, 'pdf');
        }

        return $lines === [] ? null : new ParseResult($lines, 'pdf');
    }
}
