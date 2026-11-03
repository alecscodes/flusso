<?php

use App\Enums\AiFeature;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Providers\Provider;

use function Laravel\Ai\agent;

/**
 * @param  array<string, mixed>  $overrides
 */
function configureAi(array $overrides = []): void
{
    app(AiSettings::class)->save(array_merge([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [$feature->value => true])->all(),
    ], $overrides));
}

test('AI is unavailable until a model is configured', function () {
    expect(app(AiManager::class)->isEnabled())->toBeFalse();

    configureAi(['model' => '']);
    expect(app(AiManager::class)->isEnabled())->toBeFalse();

    configureAi();
    expect(app(AiManager::class)->isEnabled())->toBeTrue();
});

test('an OpenAI-compatible endpoint needs a URL', function () {
    configureAi(['driver' => 'openai-compatible', 'url' => '']);

    expect(app(AiManager::class)->isEnabled())->toBeFalse();
});

test('each feature can be switched off on its own', function () {
    configureAi(['features' => ['categorize' => false, 'receipts' => true, 'quick_entry' => true, 'statements' => true, 'insights' => true]]);

    $ai = app(AiManager::class);

    expect($ai->isEnabled())->toBeTrue()
        ->and($ai->isEnabled(AiFeature::Categorize))->toBeFalse()
        ->and($ai->isEnabled(AiFeature::Receipts))->toBeTrue();
});

test('the configured driver builds a provider for any SDK provider', function (string $driver) {
    configureAi(['driver' => $driver, 'url' => 'http://gateway.test/v1', 'api_key' => 'k']);

    expect(app(AiManager::class)->provider())->toBeInstanceOf(Provider::class);
})->with(['ollama', 'openai-compatible', 'openai', 'anthropic', 'gemini', 'openrouter', 'groq']);

test('prompting does nothing while AI is off', function () {
    Http::preventStrayRequests();

    expect(app(AiManager::class)->prompt(agent(instructions: 'Reply OK.'), 'Ping'))->toBeNull();
});

test('a failing model gives null, not an exception, and nothing but the class is logged', function () {
    configureAi();
    Http::fake(['*' => Http::response('sk-leaky-key and my-private-spending echoed back', 500)]);
    Log::spy();

    expect(app(AiManager::class)->prompt(agent(instructions: 'Reply OK.'), 'my-private-spending'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), 'my-private-spending')
            && ! str_contains(json_encode($context), 'sk-leaky-key'));
});

test('a hosted provider needs an API key, saved or set in the environment', function () {
    configureAi(['driver' => 'openai', 'url' => '', 'api_key' => '']);
    config(['ai.providers.openai.key' => null]);

    expect(app(AiManager::class)->isEnabled())->toBeFalse();

    config(['ai.providers.openai.key' => 'env-key']);
    expect(app(AiManager::class)->isEnabled())->toBeTrue();

    config(['ai.providers.openai.key' => null]);
    configureAi(['driver' => 'openai', 'url' => '', 'api_key' => 'saved-key']);
    expect(app(AiManager::class)->isEnabled())->toBeTrue();
});

test('a local provider works without a key', function () {
    configureAi(['driver' => 'ollama', 'api_key' => '']);

    expect(app(AiManager::class)->isEnabled())->toBeTrue();
});
