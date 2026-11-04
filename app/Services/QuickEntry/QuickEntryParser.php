<?php

namespace App\Services\QuickEntry;

use App\Enums\TransactionType;
use App\Support\DescriptionMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Reads lines like "mama amazon 40 revolut ieri" without any model: amount, kind, the
 * owner's own account, a date, and what is left as the description. The amount is kept
 * exactly as typed (only the decimal separator is normalised), and a currency word is
 * never a conversion: it can at most help to find the account.
 */
class QuickEntryParser
{
    /** More entries than this in one request are handed back as not understood. */
    public const MAX_ENTRIES = 10;

    public const MAX_DESCRIPTION_LENGTH = 200;

    /** Words that only say "this is money in" and are not part of the description. */
    private const INCOME_MARKERS = ['primit', 'primita', 'incasat', 'incasata', 'received'];

    /** Words that say "money in" but also describe it, so they stay in the description. */
    private const INCOME_HINTS = ['incasare', 'salary', 'salariu', 'stipendio', 'stipendiu', 'income', 'venit'];

    /** @var array<string, string> */
    private const CURRENCY_WORDS = [
        'eur' => 'EUR', 'euro' => 'EUR', 'euros' => 'EUR',
        'ron' => 'RON', 'lei' => 'RON', 'leu' => 'RON',
    ];

    /** @var array<string, int> ISO weekday numbers (Monday is 1) by normalised name. */
    private const WEEKDAYS = [
        'luni' => 1, 'marti' => 2, 'miercuri' => 3, 'joi' => 4, 'vineri' => 5, 'sambata' => 6, 'duminica' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        'lunedi' => 1, 'martedi' => 2, 'mercoledi' => 3, 'giovedi' => 4, 'venerdi' => 5, 'sabato' => 6, 'domenica' => 7,
    ];

    /** @var array<string, int> Day offsets from today by normalised word. */
    private const DAY_WORDS = [
        'azi' => 0, 'astazi' => 0, 'today' => 0,
        'ieri' => -1, 'yesterday' => -1,
        'alaltaieri' => -2, 'alaltaier' => -2,
    ];

    /** Said right after a day word ("ieri seara"): no information of its own. */
    private const TIME_OF_DAY_WORDS = ['seara', 'dimineata', 'noaptea', 'diseara', 'sera', 'mattina', 'morning', 'evening', 'night'];

    /**
     * @param  array<int, array{id: int, name: string, currency: string}>  $accounts  the owner's own accounts, nobody else's
     * @return array{entries: list<array{type: string, amount: string, account_id: int|null, description: string|null, date: string}>, unparsed: list<string>}
     */
    public function parse(string $text, array $accounts, CarbonImmutable $today): array
    {
        $entries = [];
        $unparsed = [];

        foreach (preg_split('/[\r\n;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $entry = count($entries) < self::MAX_ENTRIES ? $this->parseSegment($segment, $accounts, $today) : null;

            if ($entry === null) {
                $unparsed[] = Str::limit($segment, self::MAX_DESCRIPTION_LENGTH, '');

                continue;
            }

            $entries[] = $entry;
        }

        return ['entries' => $entries, 'unparsed' => $unparsed];
    }

    /**
     * @param  array<int, array{id: int, name: string, currency: string}>  $accounts
     * @return array{type: string, amount: string, account_id: int|null, description: string|null, date: string}|null
     */
    private function parseSegment(string $segment, array $accounts, CarbonImmutable $today): ?array
    {
        $tokens = preg_split('/\s+/u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lower = array_map(fn (string $token): string => mb_strtolower($token), $tokens);
        $keys = array_map(fn (string $token): string => DescriptionMatcher::normalize($token), $tokens);
        $consumed = array_fill(0, count($tokens), false);

        $isIncome = false;
        $date = null;

        // Words and unambiguous dates first.
        foreach (array_keys($tokens) as $index) {
            if ($date === null) {
                $found = $this->dateFromToken($lower[$index], $keys[$index], $today);

                if ($found !== null) {
                    $date = $found;
                    $consumed[$index] = true;

                    if (isset(self::DAY_WORDS[$keys[$index]]) && in_array($keys[$index + 1] ?? '', self::TIME_OF_DAY_WORDS, true)) {
                        $consumed[$index + 1] = true;
                    }

                    continue;
                }
            }

            if (in_array($keys[$index], self::INCOME_MARKERS, true) || ($index === 0 && $lower[$index] === '+')) {
                $isIncome = true;
                $consumed[$index] = true;
            } elseif (in_array($keys[$index], self::INCOME_HINTS, true)) {
                $isIncome = true;
            }
        }

        $candidates = [];

        foreach (array_keys($tokens) as $index) {
            if ($consumed[$index] || preg_match('/^([+-]?)(?:€\s*)?(\d[\d.,]*)(€|eur|euro|euros|ron|lei|leu)?$/u', $lower[$index], $match) !== 1) {
                continue;
            }

            $amount = $this->amountFromNumber($match[2]);

            if ($amount === null) {
                continue;
            }

            $attachedCurrency = ($match[3] ?? '') === '€' ? 'EUR' : (self::CURRENCY_WORDS[$match[3] ?? ''] ?? null);

            $candidates[] = [
                'index' => $index,
                'amount' => $amount,
                'income' => $match[1] === '+',
                'currency' => $attachedCurrency ?? $this->neighbouringCurrency($lower, $keys, $consumed, $index),
                'maybeDate' => preg_match('/^\d{1,2}\.(0[1-9]|1[0-2])$/', $match[2]) === 1 ? $this->dateFromParts($match[2], '.', $today) : null,
            ];
        }

        // Only a second number lets a bare "12.03" be a date rather than the amount.
        if ($date === null && count($candidates) > 1) {
            foreach ($candidates as $position => $candidate) {
                if ($candidate['maybeDate'] !== null) {
                    $date = $candidate['maybeDate'];
                    $consumed[$candidate['index']] = true;
                    unset($candidates[$position]);

                    break;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        $candidates = array_values($candidates);
        $withCurrency = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['currency'] !== null));
        $chosen = $withCurrency[0] ?? $candidates[count($candidates) - 1];

        $consumed[$chosen['index']] = true;
        $isIncome = $isIncome || $chosen['income'];

        foreach ([$chosen['index'] - 1, $chosen['index'] + 1] as $neighbour) {
            if (isset($keys[$neighbour]) && ! $consumed[$neighbour] && $this->currencyOf($lower[$neighbour], $keys[$neighbour]) !== null) {
                $consumed[$neighbour] = true;
            }
        }

        $currency = $chosen['currency'];

        // "40 revolut lei": a currency word further away still says which currency is meant.
        foreach (array_keys($tokens) as $index) {
            if ($currency === null && ! $consumed[$index] && $this->currencyOf($lower[$index], $keys[$index]) !== null) {
                $currency = $this->currencyOf($lower[$index], $keys[$index]);
                $consumed[$index] = true;
            }
        }

        $account = $this->matchAccount($accounts, $keys, $consumed, $currency);

        $description = trim(implode(' ', array_filter(
            $tokens,
            fn (string $token, int $index): bool => ! $consumed[$index] && $keys[$index] !== '',
            ARRAY_FILTER_USE_BOTH,
        )));

        return [
            'type' => $isIncome ? TransactionType::Income->value : TransactionType::Expense->value,
            'amount' => $chosen['amount'],
            'account_id' => $account,
            'description' => $description === '' ? null : Str::limit($description, self::MAX_DESCRIPTION_LENGTH, ''),
            'date' => ($date ?? $today)->toDateString(),
        ];
    }

    /**
     * The decimal separator becomes a dot and thousands separators go; the digits stay as typed
     * ("2,5" is "2.5", "13.70" stays "13.70"). Null when it is not a plain amount with at most
     * two decimals, so a number is never silently rounded.
     */
    private function amountFromNumber(string $number): ?string
    {
        if (preg_match('/^\d([\d.,]*\d)?$/', $number) !== 1) {
            return null;
        }

        $lastDot = strrpos($number, '.');
        $lastComma = strrpos($number, ',');
        $decimalSeparator = null;

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSeparator = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false || $lastComma !== false) {
            $separator = $lastDot !== false ? '.' : ',';
            $parts = explode($separator, $number);
            $looksLikeThousands = strlen($parts[count($parts) - 1]) === 3 && strlen($parts[0]) <= 3 && $parts[0] !== '0';

            if (count($parts) === 2 && ! $looksLikeThousands) {
                $decimalSeparator = $separator;
            }
        }

        $fraction = '';
        $integerPart = $number;

        if ($decimalSeparator !== null) {
            $parts = explode($decimalSeparator, $number);

            if (count($parts) !== 2 || strlen($parts[1]) > 2) {
                return null;
            }

            [$integerPart, $fraction] = $parts;
        }

        $groups = preg_split('/[.,]/', $integerPart) ?: [];

        if (count($groups) > 1 && (strlen($groups[0]) > 3 || array_filter(array_slice($groups, 1), fn (string $group): bool => strlen($group) !== 3) !== [])) {
            return null;
        }

        $integer = ltrim(implode('', $groups), '0');
        $integer = $integer === '' ? '0' : $integer;

        if (strlen($integer) > 10 || preg_match('/[1-9]/', $integer.$fraction) !== 1) {
            return null;
        }

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    /**
     * The currency word right next to the amount ("40 eur", "€ 40") when it is not attached to it.
     *
     * @param  array<int, string>  $lower
     * @param  array<int, string>  $keys
     * @param  array<int, bool>  $consumed
     */
    private function neighbouringCurrency(array $lower, array $keys, array $consumed, int $index): ?string
    {
        foreach ([$index - 1, $index + 1] as $neighbour) {
            if (isset($keys[$neighbour]) && ! $consumed[$neighbour] && $this->currencyOf($lower[$neighbour], $keys[$neighbour]) !== null) {
                return $this->currencyOf($lower[$neighbour], $keys[$neighbour]);
            }
        }

        return null;
    }

    private function currencyOf(string $lower, string $key): ?string
    {
        return $lower === '€' ? 'EUR' : (self::CURRENCY_WORDS[$key] ?? null);
    }

    /**
     * A day word ("ieri"), a weekday (the latest one up to today) or an explicit date that cannot be
     * an amount (dd/mm, dd.mm.yyyy, yyyy-mm-dd). A bare dd.mm is decided by the caller.
     */
    private function dateFromToken(string $lower, string $key, CarbonImmutable $today): ?CarbonImmutable
    {
        if (isset(self::DAY_WORDS[$key])) {
            return $today->addDays(self::DAY_WORDS[$key]);
        }

        if (isset(self::WEEKDAYS[$key])) {
            return $today->subDays(($today->dayOfWeekIso - self::WEEKDAYS[$key] + 7) % 7);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $lower, $match) === 1) {
            return $this->validDate((int) $match[1], (int) $match[2], (int) $match[3]);
        }

        if (preg_match('/^\d{1,2}([\/.])\d{1,2}\1(\d{2}|\d{4})$/', $lower, $match) === 1) {
            return $this->dateFromParts($lower, $match[1], $today);
        }

        if (preg_match('/^\d{1,2}\/\d{1,2}$/', $lower) === 1) {
            return $this->dateFromParts($lower, '/', $today);
        }

        return null;
    }

    /**
     * "dd<sep>mm" or "dd<sep>mm<sep>yy(yy)". Without a year, a date still ahead of today is last year's.
     */
    private function dateFromParts(string $text, string $separator, CarbonImmutable $today): ?CarbonImmutable
    {
        $parts = explode($separator, $text);
        $day = (int) $parts[0];
        $month = (int) $parts[1];

        if (isset($parts[2])) {
            $year = (int) $parts[2];

            return $this->validDate(strlen($parts[2]) === 2 ? 2000 + $year : $year, $month, $day);
        }

        $date = $this->validDate($today->year, $month, $day);

        return $date !== null && $date->greaterThan($today) ? $this->validDate($today->year - 1, $month, $day) : $date;
    }

    private function validDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if ($year < 1900 || $year > 2200 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, (string) config('app.timezone'));
    }

    /**
     * The owner's account named in the text: a whole name, else a word only one account has
     * ("revolut" together with a RON word picks the RON one among several). A currency word alone
     * decides only when exactly one account has that currency. The matched words are consumed.
     *
     * @param  array<int, array{id: int, name: string, currency: string}>  $accounts
     * @param  array<int, string>  $keys
     * @param  array<int, bool>  $consumed
     */
    private function matchAccount(array $accounts, array $keys, array &$consumed, ?string $currency): ?int
    {
        $words = [];

        foreach ($keys as $index => $key) {
            if ($consumed[$index]) {
                continue;
            }

            foreach (explode(' ', $key) as $word) {
                if ($word !== '') {
                    $words[] = ['word' => $word, 'index' => $index];
                }
            }
        }

        $named = [];

        foreach ($accounts as $account) {
            $nameWords = array_values(array_filter(explode(' ', DescriptionMatcher::normalize($account['name'])), fn (string $word): bool => $word !== ''));

            if ($nameWords !== []) {
                $named[] = ['account' => $account, 'words' => $nameWords];
            }
        }

        // The longest names first, so "Revolut Savings" wins over "Revolut".
        usort($named, fn (array $a, array $b): int => count($b['words']) <=> count($a['words']));

        foreach ($named as $candidate) {
            $length = count($candidate['words']);

            for ($start = 0; $start + $length <= count($words); $start++) {
                $window = array_slice($words, $start, $length);

                if (array_column($window, 'word') === $candidate['words']) {
                    $this->consume($consumed, array_column($window, 'index'));

                    return $candidate['account']['id'];
                }
            }
        }

        foreach ($words as $found) {
            if (strlen($found['word']) < 3 || isset(self::CURRENCY_WORDS[$found['word']])) {
                continue;
            }

            $holders = array_values(array_filter($named, fn (array $candidate): bool => in_array($found['word'], $candidate['words'], true)));

            if (count($holders) > 1 && $currency !== null) {
                $holders = array_values(array_filter($holders, fn (array $candidate): bool => $candidate['account']['currency'] === $currency));
            }

            if (count($holders) === 1) {
                $this->consume($consumed, [$found['index']]);

                return $holders[0]['account']['id'];
            }
        }

        if ($currency !== null) {
            $sameCurrency = array_values(array_filter($accounts, fn (array $account): bool => $account['currency'] === $currency));

            return count($sameCurrency) === 1 ? $sameCurrency[0]['id'] : null;
        }

        return null;
    }

    /**
     * @param  array<int, bool>  $consumed
     * @param  array<int, int>  $indexes
     */
    private function consume(array &$consumed, array $indexes): void
    {
        foreach ($indexes as $index) {
            $consumed[$index] = true;
        }
    }
}
