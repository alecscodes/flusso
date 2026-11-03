<?php

namespace App\Services\Statements;

/**
 * Revolut's account statement export: Type, Product, Started Date, Completed Date,
 * Description, Amount, Fee, Currency, State, Balance. Only completed rows count,
 * and a fee becomes a line of its own because the amount excludes it.
 */
class RevolutCsvParser
{
    public function parse(string $content): ?ParseResult
    {
        $rows = CsvReader::rows($content, null, CsvReader::MAX_ROWS, $truncated);

        foreach (array_slice($rows, 0, 5, true) as $index => $row) {
            $columns = $this->columns($row);

            if ($columns !== null) {
                return new ParseResult($this->lines(array_slice($rows, $index + 1), $columns), 'revolut', 'Revolut', $truncated);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>|null
     */
    private function columns(array $headers): ?array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $columns[Normalizer::header($header)] ??= $index;
        }

        foreach (['started date', 'completed date', 'description', 'amount'] as $required) {
            if (! isset($columns[$required])) {
                return null;
            }
        }

        return $columns;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $columns
     * @return list<ParsedLine>
     */
    private function lines(array $rows, array $columns): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $cell = fn (string $name): string => isset($columns[$name]) ? ($row[$columns[$name]] ?? '') : '';

            $state = strtoupper($cell('state'));

            if ($state !== '' && $state !== 'COMPLETED') {
                continue;
            }

            $date = Normalizer::date($cell('completed date') !== '' ? $cell('completed date') : $cell('started date'));
            $amount = Normalizer::amount($cell('amount'));

            if ($date === null || $amount === null) {
                continue;
            }

            $currency = strtoupper($cell('currency'));
            $currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
            $description = $cell('description');
            $raw = implode(' | ', $row);

            $lines[] = new ParsedLine($date, $description, $amount, $currency, $raw, 'revolut');

            $fee = Normalizer::amount($cell('fee'));

            if ($fee !== null && (float) $fee != 0.0) {
                $lines[] = new ParsedLine($date, 'Fee: '.$description, '-'.ltrim($fee, '-'), $currency, $raw, 'revolut');
            }
        }

        return $lines;
    }
}
