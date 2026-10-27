<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

use function Pest\Laravel\actingAs;

function connectApp(User $user, Client $client, string $id): Token
{
    $token = Token::forceCreate(['id' => $id, 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => '["mcp:use"]', 'revoked' => false, 'expires_at' => now()->addDays(30)]);
    RefreshToken::forceCreate(['id' => "r-{$id}", 'access_token_id' => $id, 'revoked' => false, 'expires_at' => now()->addYear()]);

    return $token;
}

test('connected apps lists the apps the user authorized, grouped by app', function () {
    $user = User::factory()->create();
    $claude = Client::factory()->create(['name' => 'Claude']);
    connectApp($user, $claude, 'a1');
    connectApp($user, $claude, 'a2');
    connectApp(User::factory()->create(), Client::factory()->create(['name' => 'Someone else']), 'b1');
    Token::forceCreate(['id' => 'old', 'user_id' => $user->id, 'client_id' => Client::factory()->create(['name' => 'Gone'])->id, 'revoked' => true]);

    actingAs($user)->get(route('settings.connected-apps.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Settings/ConnectedApps')
        ->has('apps', 1)
        ->where('apps.0.name', 'Claude')
        ->where('apps.0.id', $claude->id));
});

test('revoking an app revokes its access and refresh tokens, and only the user\'s own', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $claude = Client::factory()->create(['name' => 'Claude']);
    connectApp($user, $claude, 'a1');
    connectApp($user, $claude, 'a2');
    connectApp($other, $claude, 'b1');

    actingAs($user)->delete(route('settings.connected-apps.destroy', $claude->id))->assertRedirect(route('settings.connected-apps.index'));

    expect(Token::whereIn('id', ['a1', 'a2'])->where('revoked', false)->count())->toBe(0)
        ->and(RefreshToken::whereIn('id', ['r-a1', 'r-a2'])->where('revoked', false)->count())->toBe(0)
        ->and(Token::find('b1')->revoked)->toBeFalse()
        ->and(RefreshToken::find('r-b1')->revoked)->toBeFalse();

    actingAs($user)->get(route('settings.connected-apps.index'))->assertInertia(fn ($page) => $page->has('apps', 0));
    actingAs($user)->delete(route('settings.connected-apps.destroy', Client::factory()->create()->id))->assertRedirect(route('settings.connected-apps.index'));
});

test('a connection lasts as long as its refresh token, not its access token', function () {
    $user = User::factory()->create();
    $claude = Client::factory()->create(['name' => 'Claude']);
    connectApp($user, $claude, 'a1');

    actingAs($user)->get(route('settings.connected-apps.index'))->assertInertia(fn ($page) => $page
        ->where('apps.0.expires_at', fn ($expiresAt) => abs(Carbon::parse($expiresAt)->diffInDays(now()->addYear(), true)) < 1));
});

test('a revoked token of an app is ignored and revoking an app that is not connected says so', function () {
    $user = User::factory()->create();
    $claude = Client::factory()->create(['name' => 'Claude']);
    connectApp($user, $claude, 'a1');
    Token::forceCreate(['id' => 'orphan', 'user_id' => $user->id, 'client_id' => 'missing-client', 'revoked' => false, 'expires_at' => now()->addDay()]);

    actingAs($user)->get(route('settings.connected-apps.index'))->assertInertia(fn ($page) => $page->has('apps', 1));

    actingAs($user)->delete(route('settings.connected-apps.destroy', $claude->id))->assertSessionHas('inertia.flash_data.toast.message', 'Access revoked.');
    $response = actingAs($user)->delete(route('settings.connected-apps.destroy', $claude->id));

    $response->assertRedirect(route('settings.connected-apps.index'))->assertSessionHas('inertia.flash_data.toast.message', 'That app is not connected.');
});
