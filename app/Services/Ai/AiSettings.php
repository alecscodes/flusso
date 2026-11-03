<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The instance-wide AI configuration, kept in one `settings` row. The API key is
 * encrypted at rest and never leaves this class except through `all()`/`apiKey()`.
 *
 * @phpstan-type AiConfig array{enabled: bool, driver: string, url: string|null, model: string|null, api_key: string|null, vision: bool, features: array<string, bool>}
 */
class AiSettings
{
    public const KEY = 'ai_config';

    /** @var AiConfig|null */
    private ?array $config = null;

    /**
     * Text providers of the Laravel AI SDK that need nothing beyond a key, a URL and a model.
     *
     * @var list<string>
     */
    public const DRIVERS = [
        'ollama',
        'openai-compatible',
        'openai',
        'anthropic',
        'gemini',
        'openrouter',
        'groq',
        'mistral',
        'deepseek',
        'xai',
    ];

    /**
     * @return AiConfig
     */
    public function all(): array
    {
        return $this->config ??= $this->load();
    }

    /**
     * @return AiConfig
     */
    private function load(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);
        $stored = is_array($stored) ? $stored : [];

        $features = [];
        foreach (AiFeature::cases() as $feature) {
            $features[$feature->value] = (bool) ($stored['features'][$feature->value] ?? true);
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'driver' => in_array($stored['driver'] ?? null, self::DRIVERS, true) ? $stored['driver'] : 'ollama',
            'url' => $this->filled($stored['url'] ?? null),
            'model' => $this->filled($stored['model'] ?? null),
            'api_key' => $this->decrypt($stored['api_key'] ?? null),
            'vision' => (bool) ($stored['vision'] ?? false),
            'features' => $features,
        ];
    }

    public function hasApiKey(): bool
    {
        return $this->all()['api_key'] !== null;
    }

    /**
     * Save the form. An empty `api_key` keeps the stored one; `clear_api_key` removes it.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(array $input): void
    {
        $current = $this->all();

        $apiKey = match (true) {
            is_string($input['api_key'] ?? null) && trim($input['api_key']) !== '' => trim($input['api_key']),
            (bool) ($input['clear_api_key'] ?? false) => null,
            default => $current['api_key'],
        };

        $features = [];
        foreach (AiFeature::cases() as $feature) {
            $features[$feature->value] = (bool) ($input['features'][$feature->value] ?? false);
        }

        Setting::set(self::KEY, json_encode([
            'enabled' => (bool) ($input['enabled'] ?? false),
            'driver' => $input['driver'],
            'url' => $this->filled($input['url'] ?? null),
            'model' => $this->filled($input['model'] ?? null),
            'api_key' => $apiKey === null ? null : Crypt::encryptString($apiKey),
            'vision' => (bool) ($input['vision'] ?? false),
            'features' => $features,
        ], JSON_THROW_ON_ERROR));

        $this->config = null;
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A key stored under another APP_KEY cannot be read any more: treat it as missing.
     */
    private function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
