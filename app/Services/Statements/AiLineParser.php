<?php

namespace App\Services\Statements;

use App\Ai\Agents\StatementLinesAgent;
use App\Enums\AiFeature;
use App\Services\Ai\AiManager;
use ArrayAccess;

/**
 * The model-assisted fallback for statement text the deterministic parsers could
 * not read. The text goes in chunks; every line the model returns is validated
 * (a real date, a numeric amount that also appears in the chunk) before it counts.
 */
class AiLineParser
{
    public const MAX_CHUNKS = 6;

    public const MAX_LINES = 1500;

    private const CHUNK_CHARACTERS = 6000;

    private const TIMEOUT_SECONDS = 60;

    public function __construct(private readonly AiManager $ai) {}

    public function isAvailable(): bool
    {
        return $this->ai->isEnabled(AiFeature::Statements);
    }

    public function parse(string $text, int $maxChunks = self::MAX_CHUNKS): ?ParseResult
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $lines = [];
        $chunks = $this->chunks($text);
        $truncated = count($chunks) > $maxChunks;
        $partial = false;

        foreach (array_slice($chunks, 0, $maxChunks) as $chunk) {
            $response = $this->ai->prompt(new StatementLinesAgent, "Bank statement text:\n\n".$chunk, [], self::TIMEOUT_SECONDS);

            if ($response === null) {
                // A later chunk the model could not read: what was read so far is only part of the file.
                $partial = true;

                break;
            }

            $returned = $response instanceof ArrayAccess ? ($response['lines'] ?? []) : [];

            foreach ($this->validated(is_array($returned) ? $returned : [], $chunk) as $line) {
                $lines[] = $line;
            }

            if (count($lines) >= self::MAX_LINES) {
                $lines = array_slice($lines, 0, self::MAX_LINES);
                $truncated = true;

                break;
            }
        }

        return $lines === [] ? null : new ParseResult($lines, 'ai', null, $truncated, false, $partial);
    }

    /**
     * Whole lines of the text, grouped up to the chunk size.
     *
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $chunks = [];
        $current = '';

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ($line === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($line) > self::CHUNK_CHARACTERS) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $line."\n";
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Keeps only well-formed rows whose amount can be found in the source text.
     *
     * @param  array<mixed>  $returned
     * @return list<ParsedLine>
     */
    private function validated(array $returned, string $chunk): array
    {
        $numbers = $this->numbersIn($chunk);
        $signs = $this->printedSigns($chunk);
        $lines = [];

        foreach ($returned as $row) {
            if (! is_array($row) || ! is_string($row['date'] ?? null) || ! is_string($row['description'] ?? null) || ! is_scalar($row['amount'] ?? null)) {
                continue;
            }

            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($row['date'])) === 1 ? Normalizer::date($row['date']) : null;
            $amount = Normalizer::amount((string) $row['amount']);
            $description = trim((string) preg_replace('/\s+/u', ' ', $row['description']));

            if ($date === null || $amount === null || $description === '') {
                continue;
            }

            if (! isset($numbers[ltrim($amount, '-')])) {
                continue;
            }

            $lines[] = new ParsedLine($date, mb_substr($description, 0, 500), $amount, null, $description, 'ai', $this->contradicts($amount, $signs));
        }

        return $lines;
    }

    /**
     * Whether the text prints the amount only with the opposite sign of the one the model gave
     * (e.g. "-23,40" in the text, +23.40 from the model): then the model's sign is not trusted.
     *
     * @param  array<string, array{negative: bool, positive: bool, plain: bool}>  $signs
     */
    private function contradicts(string $amount, array $signs): bool
    {
        $seen = $signs[ltrim($amount, '-')] ?? null;

        if ($seen === null || $seen['plain']) {
            return false;
        }

        return str_starts_with($amount, '-')
            ? $seen['positive'] && ! $seen['negative']
            : $seen['negative'] && ! $seen['positive'];
    }

    /**
     * How each number in the text is printed: with a minus (or brackets, trailing minus, DR),
     * with a plus (or CR), or plain. Keyed like numbersIn().
     *
     * @return array<string, array{negative: bool, positive: bool, plain: bool}>
     */
    private function printedSigns(string $text): array
    {
        $signs = [];
        $text = Normalizer::unifyMinus($text);

        preg_match_all('/(?<![\p{L}\d])([-+(])?\s?(\d+(?:[.,\']\d{3})*[.,]\d{2})(?!\d)([-)]|\s?(?:DR|CR)\b)?/iu', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $number = Normalizer::amount($match[2]);

            if ($number === null) {
                continue;
            }

            $before = $match[1];
            $after = strtoupper(trim($match[3] ?? ''));
            $negative = $before === '-' || $before === '(' || $after === '-' || $after === ')' || $after === 'DR';
            $positive = ! $negative && ($before === '+' || $after === 'CR');

            $key = ltrim($number, '-');
            $signs[$key] ??= ['negative' => false, 'positive' => false, 'plain' => false];

            if ($negative) {
                $signs[$key]['negative'] = true;
            } elseif ($positive) {
                $signs[$key]['positive'] = true;
            } else {
                $signs[$key]['plain'] = true;
            }
        }

        return $signs;
    }

    /**
     * The absolute value of every number in the text, as "1234.56" keys.
     *
     * @return array<string, true>
     */
    private function numbersIn(string $text): array
    {
        $numbers = [];

        // Once allowing a space as thousands separator, once not, so "10 100,00" counts as 100.00 too.
        foreach (['/\d+(?:[.,\' ]\d{3})*(?:[.,]\d+)?/u', '/\d+(?:[.,\']\d{3})*(?:[.,]\d+)?/u'] as $pattern) {
            preg_match_all($pattern, $text, $matches);

            foreach ($matches[0] as $token) {
                $number = Normalizer::amount($token);

                if ($number !== null) {
                    $numbers[ltrim($number, '-')] = true;
                }
            }
        }

        return $numbers;
    }
}
