<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiSettings;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aiPayload(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama:11434',
        'model' => 'qwen3:4b',
        'api_key' => '',
        'vision' => false,
        'features' => [
            'categorize' => true,
            'receipts' => true,
            'quick_entry' => true,
            'statements' => true,
            'insights' => false,
        ],
    ], $overrides);
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
});

test('AI is off until the owner configures it', function () {
    expect(app(AiManager::class)->isEnabled())->toBeFalse();

    actingAs($this->owner)->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Ai')
            ->where('config.enabled', false)
            ->where('config.driver', 'ollama')
            ->where('config.has_api_key', false)
            ->where('config.features.categorize', true)
        );
});

test('only the owner manages the AI settings', function () {
    actingAs($this->member)->get(route('settings.ai.edit'))->assertForbidden();
    actingAs($this->member)->patch(route('settings.ai.update'), aiPayload())->assertForbidden();
    actingAs($this->member)->post(route('settings.ai.test'))->assertForbidden();

    expect(Setting::get(AiSettings::KEY))->toBeNull();
});

test('the owner saves a configuration and the API key is encrypted and never sent back', function () {
    actingAs($this->owner)
        ->patch(route('settings.ai.update'), aiPayload(['driver' => 'openai', 'url' => '', 'api_key' => 'sk-secret-123', 'model' => 'gpt-5-mini']))
        ->assertRedirect(route('settings.ai.edit'));

    expect(Setting::get(AiSettings::KEY))->not->toContain('sk-secret-123');

    $config = app(AiSettings::class)->all();
    expect($config['enabled'])->toBeTrue()
        ->and($config['driver'])->toBe('openai')
        ->and($config['url'])->toBeNull()
        ->and($config['model'])->toBe('gpt-5-mini')
        ->and($config['api_key'])->toBe('sk-secret-123')
        ->and($config['features']['insights'])->toBeFalse();

    actingAs($this->owner)->get(route('settings.ai.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.has_api_key', true)
            ->missing('config.api_key')
        );
});

test('an empty key keeps the stored one and the clear box removes it', function () {
    app(AiSettings::class)->save(aiPayload(['api_key' => 'sk-keep-me']));

    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['api_key' => '']))->assertRedirect();
    expect(app(AiSettings::class)->all()['api_key'])->toBe('sk-keep-me');

    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['clear_api_key' => true]))->assertRedirect();
    expect(app(AiSettings::class)->all()['api_key'])->toBeNull();
});

test('a new key wins over the remove box when both are sent', function () {
    app(AiSettings::class)->save(aiPayload(['api_key' => 'sk-old']));

    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['api_key' => 'sk-new', 'clear_api_key' => true]))->assertRedirect();

    expect(app(AiSettings::class)->all()['api_key'])->toBe('sk-new');
});

test('the configuration is validated', function () {
    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['driver' => 'skynet']))
        ->assertSessionHasErrors('driver');

    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['driver' => 'openai-compatible', 'url' => '']))
        ->assertSessionHasErrors('url');

    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['url' => 'ftp://example.com']))
        ->assertSessionHasErrors('url');

    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['model' => '']))
        ->assertSessionHasErrors('model');

    expect(Setting::get(AiSettings::KEY))->toBeNull();
});

test('a model is not needed while AI is switched off', function () {
    actingAs($this->owner)->patch(route('settings.ai.update'), aiPayload(['enabled' => false, 'model' => '']))
        ->assertSessionHasNoErrors();

    expect(app(AiManager::class)->isEnabled())->toBeFalse();
});

test('a key that can no longer be decrypted counts as missing', function () {
    Setting::set(AiSettings::KEY, json_encode(['enabled' => true, 'driver' => 'openai', 'model' => 'x', 'api_key' => 'not-a-valid-payload']));

    expect(app(AiSettings::class)->all()['api_key'])->toBeNull();
});

test('testing the connection without a usable configuration says so', function () {
    actingAs($this->owner)->post(route('settings.ai.test'))->assertRedirect(route('settings.ai.edit'));

    expect(app(AiManager::class)->isEnabled())->toBeFalse();
});
