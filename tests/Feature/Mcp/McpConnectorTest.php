<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

use function Pest\Laravel\actingAs;

const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

/**
 * OAuth signs tokens with an RSA key pair; one is generated once per test run, in a
 * temporary directory, never in the project.
 */
beforeEach(function () {
    $dir = sys_get_temp_dir().'/flusso-test-oauth-keys';

    if (! is_file($dir.'/oauth-private.key')) {
        @mkdir($dir, 0700, true);
        Passport::loadKeysFrom($dir);
        $this->artisan('passport:keys', ['--length' => 2048])->assertSuccessful();
    }

    Passport::loadKeysFrom($dir);
});

function mcpHeaders(array $extra = []): array
{
    return ['Accept' => 'application/json, text/event-stream', 'User-Agent' => 'python-httpx/0.27', ...$extra];
}

function registerClaude(): Client
{
    $response = test()->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CALLBACK]], ['User-Agent' => 'python-httpx/0.27'])->assertCreated();

    return Client::findOrFail($response->json('client_id'));
}

/**
 * @return array{url: string, verifier: string}
 */
/**
 * The consent page's props, as the browser receives them.
 *
 * @return array<string, mixed>
 */
function consentProps(string $url): array
{
    $props = [];

    test()->get($url, ['User-Agent' => 'Mozilla/5.0'])->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * Laravel redirects back with the query sorted, which is the same URL for the OAuth server.
 */
function assertRedirectsBackTo(TestResponse $response, string $url): void
{
    $location = parse_url($response->headers->get('Location'));
    $wanted = parse_url($url);
    parse_str($location['query'] ?? '', $actual);
    parse_str($wanted['query'], $expected);

    expect($location['path'])->toBe($wanted['path'])->and($actual)->toEqual($expected);
}

function authorizeUrl(Client $client, string $state = 'xyz'): array
{
    $verifier = str_repeat('a1b2c3d4', 8);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return ['verifier' => $verifier, 'url' => '/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ])];
}

test('an unauthenticated /mcp call gets a 401 pointing at the protected resource metadata, even from a library user agent', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], mcpHeaders())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
});

test('the discovery documents are public and point at this app', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp', mcpHeaders())->assertOk()
        ->assertJsonPath('authorization_servers.0', url('/'))->assertJsonPath('scopes_supported.0', 'mcp:use');

    $this->getJson('/.well-known/oauth-authorization-server', mcpHeaders())->assertOk()
        ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('token_endpoint', url('/oauth/token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported.0', 'S256');
});

test('404s on connector paths never ban the caller, other 404s still do', function () {
    $headers = ['CF-Connecting-IP' => '203.0.113.50', 'CF-Ray' => 'abc', 'User-Agent' => 'python-httpx/0.27'];

    $this->get('/.well-known/openid-configuration', $headers)->assertNotFound();
    $this->get('/mcp/nothing-here', $headers)->assertNotFound();

    expect(DB::table('banned_ips')->count())->toBe(0);

    // Only the endpoints a connector really uses are exempt, not everything under /oauth.
    $this->get('/oauth/does-not-exist', ['CF-Connecting-IP' => '203.0.113.52', 'CF-Ray' => 'abc', 'User-Agent' => 'Mozilla/5.0'])->assertForbidden();

    $this->get('/wp-login.php', ['CF-Connecting-IP' => '203.0.113.51', 'CF-Ray' => 'abc', 'User-Agent' => 'Mozilla/5.0'])->assertForbidden();

    expect(DB::table('banned_ips')->pluck('ip')->sort()->values()->all())->toBe(['203.0.113.51', '203.0.113.52']);
});

test('bots are still blocked on the app but not on the connector paths', function () {
    $this->get('/login', ['User-Agent' => 'python-httpx/0.27'])->assertForbidden();
    $this->get('/login', ['User-Agent' => 'Mozilla/5.0'])->assertOk();
    $this->getJson('/.well-known/oauth-authorization-server', ['User-Agent' => 'Claude-User http'])->assertOk();
});

test('dynamic client registration only accepts Claude and local callbacks and creates a public client', function () {
    $client = registerClaude();

    expect($client->redirect_uris)->toBe([CLAUDE_CALLBACK])
        ->and($client->confidential())->toBeFalse();

    $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/callback']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:54545/callback']])->assertCreated();
});

test('client registration is rate limited', function () {
    foreach (range(1, 20) as $i) {
        $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertCreated();
    }

    $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertStatus(429);
});

test('a guest is sent to the login and, after signing in, back to the consent page', function () {
    $user = User::factory()->withoutTwoFactor()->create(['password' => 'secret-password']);
    ['url' => $url] = authorizeUrl(registerClaude());

    $this->get($url, ['User-Agent' => 'Mozilla/5.0'])->assertRedirect(route('login'));

    assertRedirectsBackTo($this->post(route('login.store'), ['email' => $user->email, 'password' => 'secret-password'], ['User-Agent' => 'Mozilla/5.0']), $url);

    $this->get($url, ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertInertia(fn ($page) => $page
        ->component('Auth/OAuthAuthorize')
        ->where('clientName', 'Claude')
        ->where('state', 'xyz')
        ->where('scopes', ['View and change your accounts, transactions and planned payments'])
        ->has('authToken')
        ->has('csrfToken')
        ->where('authorizeUrl', url('/oauth/authorize'))
        ->where('clientId', fn ($id) => Client::whereKey($id)->exists()));
});

test('with two-factor on, the challenge also leads back to the consent page', function () {
    $user = User::factory()->create(['password' => 'secret-password']);
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    ['url' => $url] = authorizeUrl(registerClaude());
    $browser = ['User-Agent' => 'Mozilla/5.0'];

    $this->get($url, $browser)->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secret-password'], $browser)->assertRedirect(route('two-factor.login'));
    assertRedirectsBackTo($this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'], $browser), $url);
});

test('the full OAuth flow ends with a token that can call the MCP server', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Revolut', 'currency' => 'EUR', 'balance' => 10]);
    $client = registerClaude();
    ['url' => $url, 'verifier' => $verifier] = authorizeUrl($client);
    $browser = ['User-Agent' => 'Mozilla/5.0'];

    actingAs($user);
    $props = consentProps($url);

    $approval = $this->post('/oauth/authorize', [
        'state' => 'xyz', 'client_id' => $client->getKey(), 'auth_token' => $props['authToken'], '_token' => $props['csrfToken'],
    ], $browser);

    $approval->assertRedirectContains(CLAUDE_CALLBACK.'?');
    parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['state'])->toBe('xyz');

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => CLAUDE_CALLBACK,
        'code' => $query['code'],
        'code_verifier' => $verifier,
    ], ['User-Agent' => 'python-httpx/0.27'])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

    expect($token->json('expires_in'))->toBeBetween(30 * 86400 - 60, 30 * 86400);

    $this->app['auth']->forgetGuards();

    $headers = mcpHeaders(['Authorization' => 'Bearer '.$token->json('access_token')]);
    $call = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'list_accounts', 'arguments' => []]], $headers);

    $call->assertOk();
    expect($call->getContent())->toContain('Revolut');

    $refreshed = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $client->getKey(), 'refresh_token' => $token->json('refresh_token'),
    ], ['User-Agent' => 'python-httpx/0.27'])->assertOk();

    expect($refreshed->json('access_token'))->not->toBe($token->json('access_token'));
});

test('denying the consent redirects back with access_denied', function () {
    $user = User::factory()->create();
    $client = registerClaude();
    ['url' => $url] = authorizeUrl($client);
    $browser = ['User-Agent' => 'Mozilla/5.0'];

    actingAs($user);
    $props = consentProps($url);

    $this->delete('/oauth/authorize', ['state' => 'xyz', 'client_id' => $client->getKey(), 'auth_token' => $props['authToken'], '_token' => $props['csrfToken']], $browser)
        ->assertRedirectContains('error=access_denied');
});

test('an invalid bearer token gets a 401 on /mcp', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], mcpHeaders(['Authorization' => 'Bearer nonsense']))
        ->assertUnauthorized();
});

test('the MCP server answers initialize and lists the tools for a signed-in user', function () {
    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    $init = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]], mcpHeaders())->assertOk();

    expect($init->getContent())->toContain('"name":"Flusso"')->toContain('primary currency is EUR');

    $list = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['limit' => 100]], mcpHeaders())->assertOk();

    expect($list->getContent())->toContain('create_transaction')->toContain('mark_payment_paid');
});
