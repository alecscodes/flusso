<?php

namespace App\Services\Statements;

/**
 * Reads the text of a PDF statement (as `pdftotext -layout` prints it) line by
 * line: a date, an optional second date, a description and one or two amounts
 * (the transaction, then the running balance).
 *
 * Two things are decided from the whole statement, not line by line: whether the
 * all-numeric dates are day-first or month-first (like the CSV parser does), and,
 * when the header has separate debit and credit columns, the sign of an unsigned
 * amount from the column it sits in. An amount that cannot be placed in a column
 * stays positive and the line is flagged so the owner can fix its sign.
 *
 * @phpstan-type Marker array{kind: string, start: int, end: int}
 * @phpstan-type Row array{dates: list<string>, description: string, token: string, start: int, end: int, line: string, markers: list<Marker>|null}
 */
class PdfLineParser
{
    private const SUMMARY_LINE = '/^(saldo|balance|totale|total|opening|closing|riporto|brought forward|carried forward)\b/iu';

    /** Column header words, in English, Italian and Romanian (regex fragments, need the /iu flags). */
    private const DEBIT_WORDS = 'debit|debits|dare|addebiti|addebito|uscite|ie[sșş]iri|withdrawals?|money out';

    private const CREDIT_WORDS = 'credit|credits|avere|accrediti|accredito|entrate|intr[aă]ri|deposits?|money in';

    private const BALANCE_WORDS = 'saldo|balance|sold';

    /** How far (in characters) an amount may sit from its column header and still count as under it. */
    private const MAX_COLUMN_DRIFT = 8;

    /** How much closer the best column must be than the next one for the choice to be certain. */
    private const MIN_COLUMN_MARGIN = 3;

    public function parse(string $text): ?ParseResult
    {
        $rows = [];
        $markers = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $markers = $this->headerMarkers($rawLine) ?? $markers;
            $row = $this->row(rtrim($rawLine), $markers);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $order = Normalizer::dateOrder(array_merge(...array_column($rows, 'dates')));
        $lines = [];

        foreach ($rows as $row) {
            $posted = Normalizer::date($row['dates'][0], $order['dayFirst']);
            [$value, $uncertain] = $this->signedAmount($row);

            if ($posted === null || $value === null) {
                continue;
            }

            $lines[] = new ParsedLine($posted, mb_substr($row['description'], 0, 500), $value, null, $row['line'], 'pdf', $uncertain);
        }

        return $lines === [] ? null : new ParseResult($lines, 'pdf', dateOrderAssumed: $order['assumed']);
    }

    /**
     * The debit, credit and balance column headers of a header line, or null when the
     * line is not a header with both a debit and a credit column.
     *
     * @return list<Marker>|null
     */
    private function headerMarkers(string $line): ?array
    {
        $date = Normalizer::DATE_PATTERN;

        if (preg_match("/^\\s*(?:{$date})/iu", $line) === 1) {
            return null;
        }

        $markers = [];

        foreach (['debit' => self::DEBIT_WORDS, 'credit' => self::CREDIT_WORDS, 'balance' => self::BALANCE_WORDS] as $kind => $words) {
            preg_match_all("/(?<!\\p{L})(?:{$words})(?!\\p{L})/iu", $line, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as [$word, $byteOffset]) {
                $start = mb_strlen(substr($line, 0, $byteOffset));
                $markers[] = ['kind' => $kind, 'start' => $start, 'end' => $start + mb_strlen($word)];
            }
        }

        $kinds = array_column($markers, 'kind');

        return in_array('debit', $kinds, true) && in_array('credit', $kinds, true) ? $markers : null;
    }

    /**
     * A dated line with a description and an amount, with where the amount sits in the line.
     *
     * @param  list<Marker>|null  $markers
     * @return Row|null
     */
    private function row(string $line, ?array $markers): ?array
    {
        $date = Normalizer::DATE_PATTERN;
        $amount = Normalizer::AMOUNT_PATTERN;

        if (preg_match("/^\\s*({$date})(?:\\s+({$date}))?\\s+(.+)\$/iu", $line, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $rest = $matches[3][0];
        $restOffset = $matches[3][1];
        $amounts = [];

        while (count($amounts) < 2 && preg_match("/^(.*?)\\s+({$amount})(?:\\s?(?:EUR|RON|USD|GBP|€))?\$/iu", $rest, $found, PREG_OFFSET_CAPTURE) === 1) {
            array_unshift($amounts, ['token' => $found[2][0], 'offset' => $restOffset + $found[2][1]]);
            $rest = $found[1][0];
        }

        $description = trim((string) preg_replace('/\s+/u', ' ', $rest));

        if ($amounts === [] || preg_match('/\p{L}/u', $description) !== 1 || preg_match(self::SUMMARY_LINE, $description) === 1) {
            return null;
        }

        $start = mb_strlen(substr($line, 0, $amounts[0]['offset']));

        return [
            'dates' => array_filter([$matches[1][0], $matches[2][0]], fn (string $value) => $value !== ''),
            'description' => $description,
            'token' => $amounts[0]['token'],
            'start' => $start,
            'end' => $start + mb_strlen($amounts[0]['token']),
            'line' => trim((string) preg_replace('/\s+/u', ' ', $line)),
            'markers' => $markers,
        ];
    }

    /**
     * The signed amount of a row and whether its sign is a guess. An amount that
     * carries its own sign is taken as printed; an unsigned one in a debit column is
     * money out, in a credit column money in, and anywhere else a guess (flagged).
     *
     * @param  Row  $row
     * @return array{0: string|null, 1: bool}
     */
    private function signedAmount(array $row): array
    {
        $value = Normalizer::amount($row['token']);

        if ($value === null || (float) $value === 0.0 || $this->hasOwnSign($row['token'])) {
            return [$value, false];
        }

        // No sign of its own and no debit/credit columns to tell: money in or out is a guess.
        if ($row['markers'] === null) {
            return [$value, true];
        }

        return match ($this->columnOf($row['start'], $row['end'], $row['markers'])) {
            'debit' => ['-'.$value, false],
            'credit' => [$value, false],
            default => [$value, true],
        };
    }

    /**
     * A minus, a plus, brackets or CR/DR in the printed amount.
     */
    private function hasOwnSign(string $token): bool
    {
        return preg_match('/^[^\d]*[-+(]|[-)]\s*$|\b(?:CR|DR)\s*$/iu', $token) === 1;
    }

    /**
     * The kind of the header column the amount sits under (aligned by an edge or the
     * middle), or null when it is not clearly under one debit or credit column.
     *
     * @param  list<Marker>  $markers
     */
    private function columnOf(int $start, int $end, array $markers): ?string
    {
        $drifts = [];

        foreach ($markers as $marker) {
            $drifts[] = [
                'kind' => $marker['kind'],
                'drift' => min(
                    abs($end - $marker['end']),
                    abs($start - $marker['start']),
                    abs(intdiv($start + $end, 2) - intdiv($marker['start'] + $marker['end'], 2)),
                ),
            ];
        }

        usort($drifts, fn (array $a, array $b) => $a['drift'] <=> $b['drift']);

        $best = $drifts[0];
        $next = $drifts[1] ?? null;

        if ($best['drift'] > self::MAX_COLUMN_DRIFT || ($next !== null && $next['drift'] - $best['drift'] < self::MIN_COLUMN_MARGIN)) {
            return null;
        }

        return $best['kind'] === 'balance' ? null : $best['kind'];
    }
}
