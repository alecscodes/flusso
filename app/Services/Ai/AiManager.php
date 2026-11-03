<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * The one door to a language model. The provider, model and key come from the
 * owner's settings, so any provider of the Laravel AI SDK works, and every AI
 * feature degrades to "not available" instead of failing when it is off or down.
 */
class AiManager
{
    /** Drivers that work without an API key (local models and gateways). */
    private const KEYLESS_DRIVERS = ['ollama', 'openai-compatible'];

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Whether AI is switched on and complete enough to be called (optionally for one feature).
     */
    public function isEnabled(?AiFeature $feature = null): bool
    {
        $config = $this->settings->all();

        if (! $config['enabled'] || $config['model'] === null) {
            return false;
        }

        if ($config['driver'] === 'openai-compatible' && $config['url'] === null) {
            return false;
        }

        if (! in_array($config['driver'], self::KEYLESS_DRIVERS, true) && $this->apiKey($config['driver'], $config['api_key']) === null) {
            return false;
        }

        return $feature === null || $config['features'][$feature->value];
    }

    /**
     * Whether the configured model accepts images (so a receipt photo can go to it directly).
     */
    public function supportsVision(): bool
    {
        return $this->settings->all()['vision'];
    }

    public function provider(): Provider
    {
        $config = $this->settings->all();

        // Start from the SDK's own entry for the driver so default URLs stay intact.
        /** @var array<string, mixed> $base */
        $base = config("ai.providers.{$config['driver']}", ['driver' => $config['driver']]);

        return Ai::build([
            ...$base,
            'driver' => $config['driver'],
            'key' => $this->apiKey($config['driver'], $config['api_key']) ?? '',
            ...($config['url'] !== null ? ['url' => $config['url']] : []),
            'models' => ['text' => ['default' => $config['model']]],
        ]);
    }

    /**
     * The key saved in the settings, else the one the deployment sets for the provider in its environment.
     */
    private function apiKey(string $driver, ?string $saved): ?string
    {
        if ($saved !== null) {
            return $saved;
        }

        $fromConfig = config("ai.providers.{$driver}.key");

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    /**
     * Prompt an agent with the configured provider. Returns null when AI is off
     * or the call fails: callers must always have a non-AI path.
     *
     * @param  list<mixed>  $attachments
     */
    public function prompt(Agent $agent, string $prompt, array $attachments = [], int $timeout = 90): ?AgentResponse
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            return $agent->prompt($prompt, attachments: $attachments, provider: [$this->provider()], timeout: $timeout);
        } catch (Throwable $exception) {
            // Only the class: the message can carry the request URL (and a key in it) or an echo of the prompt.
            Log::warning('AI request failed.', [
                'agent' => $agent::class,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }
}
