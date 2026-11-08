<?php

namespace App\Services\Statements;

use Illuminate\Support\Str;

/**
 * Turns the dates and amounts found in statements (any bank, Italian or
 * English) into 'Y-m-d' and signed '-1234.56' strings, or null when unsure.
 */
final class Normalizer
{
    /** A date as printed in a statement line (regex fragment, needs the /u flag). */
    public const DATE_PATTERN = '\d{4}-\d{2}-\d{2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-](?:\d{4}|\d{2})|\d{1,2}[ \-]\p{L}{3,9}\.?[ \-](?:\d{4}|\d{2})|\p{L}{3,9}\.? \d{1,2}, \d{4}';

    /** An amount with two decimals, either 1.234,56 or 1,234.56 (regex fragment, needs the /u and /i flags). */
    public const AMOUNT_PATTERN = '[-+]?\(?[€$£]?\s?(?:\d{1,3}(?:[.,\']\d{3})+|\d+)[.,]\d{2}\)?(?:-|\s?(?:CR|DR)\b)?';

    private const MONTHS = [
        'gen' => 1, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mag' => 5, 'may' => 5,
        'giu' => 6, 'jun' => 6, 'lug' => 7, 'jul' => 7, 'ago' => 8, 'aug' => 8,
        'set' => 9, 'sep' => 9, 'ott' => 10, 'oct' => 10, 'nov' => 11, 'dic' => 12, 'dec' => 12,
    ];

    /**
     * A signed amount with two decimals ("-1234.56"), from 1.234,56 / 1,234.56 /
     * (12.50) / 12,50- / 12.50 DR and similar. Null when it is not a number.
     */
    public static function amount(string $value): ?string
    {
        $value = trim(str_replace(["\u{00A0}", "\u{2009}", "\u{202F}"], ' ', $value));
        $negative = false;

        if (preg_match('/^\((.*)\)$/u', $value, $matches) === 1) {
            $negative = true;
            $value = $matches[1];
        }

        if (preg_match('/\s*(DR|CR)$/i', $value, $matches) === 1) {
            $negative = $negative || strtoupper($matches[1]) === 'DR';
            $value = substr($value, 0, -strlen($matches[0]));
        }

        $value = (string) preg_replace('/[^\d.,\'\s+-]/u', '', $value);

        if (str_contains($value, '-')) {
            $negative = true;
        }

        $value = str_replace(['-', '+', ' ', "'"], '', $value);

        if ($value === '' || preg_match('/^\d[\d.,]*$/', $value) !== 1) {
            return null;
        }

        $value = self::withDotDecimal($value);

        if (preg_match('/^\d{1,12}(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $number = round((float) $value, 2);

        return ($negative && $number != 0 ? '-' : '').number_format($number, 2, '.', '');
    }

    /**
     * A 'Y-m-d' date, or null. Day-first ("03/04/2024" is 3 April) unless told otherwise.
     */
    public static function date(string $value, bool $dayFirst = true): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})(?:[T\s].*)?$/', $value, $m) === 1) {
            return self::buildDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})(?:[T\s,].*)?$/', $value, $m) === 1) {
            return $dayFirst
                ? self::buildDate((int) $m[3], (int) $m[2], (int) $m[1])
                : self::buildDate((int) $m[3], (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{1,2})[\s.\-\/]+(\p{L}{3,12})\.?,?[\s.\-\/]+(\d{4}|\d{2})(?:\D.*)?$/u', $value, $m) === 1) {
            $month = self::monthNumber($m[2]);

            return $month === null ? null : self::buildDate((int) $m[3], $month, (int) $m[1]);
        }

        if (preg_match('/^(\p{L}{3,12})\.?\s+(\d{1,2}),?\s+(\d{4})(?:\D.*)?$/u', $value, $m) === 1) {
            $month = self::monthNumber($m[1]);

            return $month === null ? null : self::buildDate((int) $m[3], $month, (int) $m[2]);
        }

        return null;
    }

    /**
     * Whether the all-numeric dates of a column are day-first: false only when one
     * of them has a month in first position (e.g. "01/31/2024").
     *
     * @param  list<string>  $values
     */
    public static function detectDayFirst(array $values): bool
    {
        return self::dateOrder($values)['dayFirst'];
    }

    /**
     * The order of the all-numeric dates ("03/04/2024") of a whole file: any date
     * with a first number above 12 proves day-first, one with a second number above
     * 12 proves month-first. Without proof it is day-first, and `assumed` says that
     * this was a guess (there were numeric dates, none of them decisive).
     *
     * @param  list<string>  $values
     * @return array{dayFirst: bool, assumed: bool}
     */
    public static function dateOrder(array $values): array
    {
        $numeric = false;
        $monthFirst = false;

        foreach ($values as $value) {
            if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-]\d{2,4}/', trim($value), $m) !== 1) {
                continue;
            }

            $numeric = true;

            if ((int) $m[1] > 12) {
                return ['dayFirst' => true, 'assumed' => false];
            }

            if ((int) $m[2] > 12) {
                $monthFirst = true;
            }
        }

        return $monthFirst
            ? ['dayFirst' => false, 'assumed' => false]
            : ['dayFirst' => true, 'assumed' => $numeric];
    }

    /**
     * A column header reduced to lowercase ASCII without a "(EUR)" suffix, for matching.
     */
    public static function header(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim(ltrim($value, "\u{FEFF}"))));
        $value = (string) preg_replace('/\s*\(.*?\)/', '', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function withDotDecimal(string $value): string
    {
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';

            return str_replace($decimal, '.', str_replace($thousands, '', $value));
        }

        if ($lastComma === false && $lastDot === false) {
            return $value;
        }

        $separator = $lastComma !== false ? ',' : '.';
        $position = (int) strrpos($value, $separator);
        $before = substr($value, 0, $position);
        $after = substr($value, $position + 1);

        // "1.234" or "1.234.567" is thousands; banks always print two decimals.
        $isThousands = substr_count($value, $separator) > 1
            || (strlen($after) === 3 && strlen($before) >= 1 && strlen($before) <= 3 && $before !== '0');

        return $isThousands ? str_replace($separator, '', $value) : $before.'.'.$after;
    }

    private static function monthNumber(string $name): ?int
    {
        return self::MONTHS[Str::ascii(mb_strtolower(mb_substr($name, 0, 3)))] ?? null;
    }

    private static function buildDate(int $year, int $month, int $day): ?string
    {
        if ($year < 100) {
            $year += 2000;
        }

        if ($year < 1990 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
