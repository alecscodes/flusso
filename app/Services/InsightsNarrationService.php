<?php

namespace App\Services;

use App\Ai\Agents\InsightsNarrator;
use App\Enums\AiFeature;
use App\Models\User;
use App\Services\Ai\AiManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The optional plain-language paragraph of the Insights page. The model only receives
 * aggregated figures and category names (never descriptions, people, accounts or balances),
 * its answer is cached per user, period and input, and the deterministic sentence
 * is used whenever AI is off, fails or answers with nothing usable.
 *
 * @phpstan-import-type Insights from InsightsService
 */
class InsightsNarrationService
{
    public const MAX_LENGTH = 600;

    private const CACHE_DAYS = 14;

    private const LANGUAGES = ['ro' => 'Romanian', 'it' => 'Italian', 'en' => 'English'];

    public function __construct(private AiManager $ai) {}

    /**
     * @param  Insights  $insights
     * @return array{text: string, source: 'ai'|'template'}
     */
    public function narrate(User $user, array $insights, string $locale = 'en'): array
    {
        $template = ['text' => $insights['sentence'], 'source' => 'template'];

        if (! $this->ai->isEnabled(AiFeature::Insights)) {
            return $template;
        }

        $language = self::LANGUAGES[$locale] ?? self::LANGUAGES['en'];
        $input = $this->input($insights);
        $key = sprintf(
            'insights:narrative:%d:%s:%s',
            $user->id,
            $insights['period']['start'],
            hash('sha256', json_encode([$input, $language], JSON_THROW_ON_ERROR)),
        );

        $text = Cache::remember($key, now()->addDays(self::CACHE_DAYS), function () use ($input, $language): ?string {
            $prompt = "Answer in {$language}.\nFigures:\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $response = $this->ai->prompt(new InsightsNarrator, $prompt, timeout: 30);

            return $response === null ? null : $this->clean($response->text);
        });

        return is_string($text) && $text !== '' ? ['text' => $text, 'source' => 'ai'] : $template;
    }

    /**
     * The only data that ever reaches the model.
     *
     * @param  Insights  $insights
     * @return array<string, mixed>
     */
    public function input(array $insights): array
    {
        return [
            'period' => ['start' => $insights['period']['start'], 'end' => $insights['period']['end']],
            'currency' => $insights['currency'],
            'is_estimate' => $insights['is_estimate'],
            'income' => $insights['summary']['income'],
            'expenses' => $insights['summary']['expenses'],
            'net' => $insights['summary']['net'],
            'transaction_count' => $insights['summary']['transaction_count'],
            'previous_period_expenses' => $insights['previous_summary']['expenses'],
            'expenses_change_percent' => $insights['expense_change']['percent'],
            'top_categories' => array_map(fn (array $category): array => [
                'name' => $category['name'],
                'total' => $category['total'],
                'change_percent' => $category['change_percent'],
            ], $insights['top_categories']),
            'unusual_categories' => array_map(fn (array $category): array => [
                'name' => $category['name'],
                'total' => $category['total'],
                'usual_average' => $category['average'],
            ], $insights['unusual_categories']),
        ];
    }

    /**
     * Plain text on one line, capped; null when nothing is left.
     */
    private function clean(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return $text === '' ? null : Str::limit($text, self::MAX_LENGTH - 1, '…');
    }
}
