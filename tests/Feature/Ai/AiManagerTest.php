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

test('a failing model gives null, not an exception, and the prompt is never logged', function () {
    configureAi();
    Http::fake(['*' => Http::response('boom', 500)]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), 'my-private-spending'));

    expect(app(AiManager::class)->prompt(agent(instructions: 'Reply OK.'), 'my-private-spending'))->toBeNull();
});
