<?php

namespace App\Services\Receipts;

use App\Ai\Agents\ReceiptReader;
use App\Enums\AiFeature;
use App\Enums\CategoryType;
use App\Enums\ReceiptAmountMode;
use App\Enums\ReceiptScanMode;
use App\Models\User;
use App\Services\Ai\AiManager;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;

/**
 * Turns a receipt image into a PROPOSAL (nothing is saved). The model only sees
 * the receipt and the names of the owner's expense categories; everything it
 * answers is validated here before it reaches the owner.
 *
 * @phpstan-type ReceiptLine array{description: string, amount: float, category_id: int|null}
 * @phpstan-type ReceiptProposal array{mode: string, amounts: string, merchant: string|null, date: string|null, total: float|null, lines: list<ReceiptLine>, lines_sum: float|null, difference: float|null, transactions: list<array{category_id: int|null, amount: float, description: string|null}>}
 */
class ReceiptScanner
{
    public const MAX_LINES = 60;

    private const MAX_CATEGORIES = 100;

    private const MAX_TEXT_LENGTH = 8000;

    /** A gap between the lines and the printed total up to this is rounding noise. */
    private const TOLERANCE = 0.05;

    public function __construct(
        private readonly AiManager $ai,
        private readonly ReceiptOcr $ocr,
    ) {}

    /**
     * @return ReceiptProposal
     *
     * @throws ReceiptScanException
     */
    public function scan(User $user, string $imagePath, ReceiptScanMode $mode, ReceiptAmountMode $amounts): array
    {
        if (! $this->ai->isEnabled(AiFeature::Receipts)) {
            throw new ReceiptScanException('Receipt scanning is not available.');
        }

        $categories = $user->categories()
            ->where('type', CategoryType::Expense)
            ->orderBy('name')
            ->limit(self::MAX_CATEGORIES)
            ->get(['id', 'name']);

        $categoryList = $categories->map(fn ($category) => "{$category->id}: ".Str::limit($category->name, 60, ''))->implode("\n");

        $attachments = [];

        if ($this->ai->supportsVision()) {
            $attachments[] = Image::fromPath($imagePath);
            $receipt = 'The receipt is the attached image.';
        } else {
            $text = $this->ocr->read($imagePath);

            if ($text === null) {
                throw new ReceiptScanException('The text of the receipt could not be read. Try a sharper, well-lit photo or enter the transaction by hand.');
            }

            $receipt = "Receipt text:\n<receipt>\n".Str::limit($text, self::MAX_TEXT_LENGTH, '')."\n</receipt>";
        }

        $response = $this->ai->prompt(
            new ReceiptReader($mode),
            "Categories (id: name):\n{$categoryList}\n\n{$receipt}",
            $attachments,
        );

        if (! $response instanceof Arrayable) {
            throw new ReceiptScanException('The receipt could not be read. Try again or enter the transaction by hand.');
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        return $this->proposal($data, $mode, $amounts, $categories->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $categoryIds
     * @return ReceiptProposal
     *
     * @throws ReceiptScanException
     */
    private function proposal(array $data, ReceiptScanMode $mode, ReceiptAmountMode $amounts, array $categoryIds): array
    {
        $merchant = $this->cleanText($data['merchant'] ?? null, 100);
        $total = ReceiptAmounts::parse($data['total'] ?? null);

        $proposal = [
            'mode' => $mode->value,
            'amounts' => $amounts->value,
            'merchant' => $merchant,
            'date' => $this->validDate($data['date'] ?? null),
            'total' => $total,
            'lines' => [],
            'lines_sum' => null,
            'difference' => null,
            'transactions' => [],
        ];

        if ($mode === ReceiptScanMode::Total) {
            if ($total === null) {
                throw new ReceiptScanException('The total of the receipt could not be found. Try again or enter the transaction by hand.');
            }

            $proposal['transactions'] = [[
                'category_id' => $this->allowedCategory($data['category_id'] ?? null, $categoryIds),
                'amount' => $amounts->apply($total),
                'description' => $merchant,
            ]];

            return $proposal;
        }

        $lines = $this->lines($data['lines'] ?? null, $categoryIds);

        if ($lines === []) {
            throw new ReceiptScanException('No items were found on the receipt. Try again, or scan it as a single total.');
        }

        $proposal['lines'] = $lines;
        $proposal['lines_sum'] = $this->sum(array_column($lines, 'amount'));

        if ($total !== null && abs($total - $proposal['lines_sum']) > self::TOLERANCE) {
            $proposal['difference'] = round($total - $proposal['lines_sum'], 2);
        }

        $proposal['transactions'] = $this->groupByCategory($lines, $merchant, $amounts);

        return $proposal;
    }

    /**
     * @param  list<int>  $categoryIds
     * @return list<ReceiptLine>
     */
    private function lines(mixed $rawLines, array $categoryIds): array
    {
        if (! is_array($rawLines)) {
            return [];
        }

        $lines = [];

        foreach (array_slice(array_values($rawLines), 0, self::MAX_LINES) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $amount = ReceiptAmounts::parse($raw['amount'] ?? null);

            if ($amount === null) {
                continue;
            }

            $lines[] = [
                'description' => $this->cleanText($raw['description'] ?? null, 100) ?? '',
                'amount' => $amount,
                'category_id' => $this->allowedCategory($raw['category_id'] ?? null, $categoryIds),
            ];
        }

        return $lines;
    }

    /**
     * One transaction per category, in the order the categories first appear on the receipt.
     *
     * @param  list<ReceiptLine>  $lines
     * @return list<array{category_id: int|null, amount: float, description: string|null}>
     */
    private function groupByCategory(array $lines, ?string $merchant, ReceiptAmountMode $amounts): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $key = $line['category_id'] ?? 0;
            $groups[$key] = ($groups[$key] ?? 0) + (int) round($line['amount'] * 100);
        }

        $transactions = [];

        foreach ($groups as $key => $cents) {
            $transactions[] = [
                'category_id' => $key === 0 ? null : $key,
                'amount' => $amounts->apply($cents / 100),
                'description' => $merchant,
            ];
        }

        return $transactions;
    }

    /**
     * @param  list<float>  $amounts
     */
    private function sum(array $amounts): float
    {
        return array_sum(array_map(fn (float $amount) => (int) round($amount * 100), $amounts)) / 100;
    }

    /**
     * The model may only pick one of the owner's own expense categories.
     *
     * @param  list<int>  $categoryIds
     */
    private function allowedCategory(mixed $value, array $categoryIds): ?int
    {
        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return is_int($value) && in_array($value, $categoryIds, true) ? $value : null;
    }

    private function cleanText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $value) ?? '');

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    /**
     * A plausible purchase date: not in the future and not older than five years.
     */
    private function validDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        if ($value > now()->addDay()->toDateString() || $value < now()->subYears(5)->toDateString()) {
            return null;
        }

        return $value;
    }
}
