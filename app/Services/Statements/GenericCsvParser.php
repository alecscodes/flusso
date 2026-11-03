<?php

namespace App\Services\Statements;

/**
 * Any bank's CSV: the header row is found by looking for a date column, a
 * description column and either one signed amount column or debit/credit columns
 * (English and Italian names, comma or semicolon, any common date format).
 */
class GenericCsvParser
{
    private const DATE_HEADERS = [
        'data operazione', 'booking date', 'transaction date', 'posting date', 'data contabile',
        'data registrazione', 'completed date', 'date', 'data', 'fecha', 'data valuta', 'value date', 'started date',
    ];

    private const DESCRIPTION_HEADERS = [
        'description', 'descrizione', 'descrizione operazione', 'causale', 'details', 'dettagli', 'memo',
        'narrative', 'reference', 'payee', 'beneficiary', 'beneficiario', 'transaction description',
        'merchant', 'name', 'operazione', 'note',
    ];

    private const AMOUNT_HEADERS = ['amount', 'importo', 'value', 'totale', 'betrag', 'montant'];

    private const DEBIT_HEADERS = [
        'debit', 'debits', 'debit amount', 'uscite', 'uscita', 'dare', 'importo dare', 'addebito', 'addebiti',
        'withdrawal', 'withdrawals', 'money out', 'paid out',
    ];

    private const CREDIT_HEADERS = [
        'credit', 'credits', 'credit amount', 'entrate', 'entrata', 'avere', 'importo avere', 'accredito',
        'accrediti', 'deposit', 'deposits', 'money in', 'paid in',
    ];

    private const CURRENCY_HEADERS = ['currency', 'valuta', 'divisa', 'ccy'];

    public function parse(string $content): ?ParseResult
    {
        $rows = CsvReader::rows($content, null, CsvReader::MAX_ROWS, $truncated);

        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $columns = $this->columns($row);

            if ($columns !== null) {
                return new ParseResult($this->lines(array_slice($rows, $index + 1), $columns), 'csv', null, $truncated);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @return array{date: int, description: list<int>, amount: int|null, debit: int|null, credit: int|null, currency: int|null}|null
     */
    private function columns(array $headers): ?array
    {
        $names = array_map(Normalizer::header(...), $headers);

        $date = $this->pick($names, self::DATE_HEADERS);
        $amount = $this->pick($names, self::AMOUNT_HEADERS);
        $debit = $this->pick($names, self::DEBIT_HEADERS);
        $credit = $this->pick($names, self::CREDIT_HEADERS);

        $description = [];

        foreach ($names as $index => $name) {
            if (in_array($name, self::DESCRIPTION_HEADERS, true) && count($description) < 3) {
                $description[] = $index;
            }
        }

        if ($date === null || $description === [] || ($amount === null && $debit === null && $credit === null)) {
            return null;
        }

        return [
            'date' => $date,
            'description' => $description,
            'amount' => $amount,
            'debit' => $debit,
            'credit' => $credit,
            'currency' => $this->pick($names, self::CURRENCY_HEADERS),
        ];
    }

    /**
     * The index of the first wanted header (in order of preference) that is present.
     *
     * @param  list<string>  $names
     * @param  list<string>  $wanted
     */
    private function pick(array $names, array $wanted): ?int
    {
        foreach ($wanted as $candidate) {
            $index = array_search($candidate, $names, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array{date: int, description: list<int>, amount: int|null, debit: int|null, credit: int|null, currency: int|null}  $columns
     * @return list<ParsedLine>
     */
    private function lines(array $rows, array $columns): array
    {
        $dayFirst = Normalizer::detectDayFirst(array_map(fn (array $row) => $row[$columns['date']] ?? '', $rows));
        $lines = [];

        foreach ($rows as $row) {
            $date = Normalizer::date($row[$columns['date']] ?? '', $dayFirst);
            $amount = $this->amount($row, $columns);

            if ($date === null || $amount === null) {
                continue;
            }

            $parts = [];

            foreach ($columns['description'] as $index) {
                $part = $row[$index] ?? '';

                if ($part !== '' && ! in_array($part, $parts, true)) {
                    $parts[] = $part;
                }
            }

            $currency = $columns['currency'] !== null ? strtoupper($row[$columns['currency']] ?? '') : '';

            $lines[] = new ParsedLine(
                $date,
                mb_substr(implode(' - ', $parts), 0, 500),
                $amount,
                preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
                implode(' | ', $row),
                'csv',
            );
        }

        return $lines;
    }

    /**
     * @param  list<string>  $row
     * @param  array{date: int, description: list<int>, amount: int|null, debit: int|null, credit: int|null, currency: int|null}  $columns
     */
    private function amount(array $row, array $columns): ?string
    {
        if ($columns['amount'] !== null) {
            return Normalizer::amount($row[$columns['amount']] ?? '');
        }

        $debit = $columns['debit'] !== null ? Normalizer::amount($row[$columns['debit']] ?? '') : null;
        $credit = $columns['credit'] !== null ? Normalizer::amount($row[$columns['credit']] ?? '') : null;

        if ($debit === null && $credit === null) {
            return null;
        }

        $total = abs((float) ($credit ?? 0)) - abs((float) ($debit ?? 0));

        return number_format($total, 2, '.', '');
    }
}
