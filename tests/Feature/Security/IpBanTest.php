<?php

use App\Http\Middleware\CheckBannedIp;
use App\Models\User;
use App\Services\IpBanService;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Cache::flush();
});

test('middleware blocks banned IPs', function () {
    $request = Request::create('/test', 'GET');
    $request->server->set('REMOTE_ADDR', '203.0.113.100');

    DB::table('banned_ips')->insert([
        'ip' => '203.0.113.100',
        'reason' => 'Test ban',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Cache::flush();

    $middleware = new CheckBannedIp(
        app(IpBanService::class),
        app(LogService::class)
    );

    try {
        $response = $middleware->handle($request, fn ($req) => response('ok'));
        expect($response->getStatusCode())->toBe(403);
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware allows non-banned IPs', function () {
    $request = Request::create('/test', 'GET');
    $request->server->set('REMOTE_ADDR', '203.0.113.200');

    Cache::flush();

    $middleware = new CheckBannedIp(
        app(IpBanService::class),
        app(LogService::class)
    );

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($response->getContent())->toBe('ok');
});

test('service bans IP after 2 failed login attempts', function () {
    $request = Request::create('/login', 'POST');
    $request->server->set('REMOTE_ADDR', '203.0.113.100');

    $service = app(IpBanService::class);

    $service->recordFailedLogin($request);
    expect(DB::table('banned_ips')->where('ip', '203.0.113.100')->exists())->toBeFalse();

    $service->recordFailedLogin($request);
    expect(DB::table('banned_ips')->where('ip', '203.0.113.100')->exists())->toBeTrue();
});

test('service detects real client IP from Cloudflare', function () {
    $request = Request::create('/test', 'GET');
    $request->server->set('HTTP_CF_CONNECTING_IP', '203.0.113.1');
    $request->server->set('HTTP_CF_RAY', 'test-ray-id');
    $request->headers->set('X-Forwarded-For', '198.51.100.1, 198.51.100.2');

    $service = app(IpBanService::class);
    $service->ban($request, 'Test ban');

    expect(DB::table('banned_ips')->where('ip', '203.0.113.1')->exists())->toBeTrue();
    expect(DB::table('banned_ips')->where('ip', '198.51.100.1')->exists())->toBeFalse();
});

test('failed login event triggers IP ban after 2 attempts and returns 403', function () {
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.100']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    expect(DB::table('banned_ips')->where('ip', '203.0.113.100')->exists())->toBeFalse();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertForbidden();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.100')->exists())->toBeTrue();
});

test('non-existent routes ban IPs on GET requests and return 403', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);

    $response = $this->get('/wordpress');

    $response->assertForbidden();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.50')->exists())->toBeTrue();
    expect(DB::table('banned_ips')->where('reason', 'like', '%wordpress%')->exists())->toBeTrue();
});

test('non-existent routes ban IPs on POST requests and return 403', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51']);

    $response = $this->post('/wordpress');

    $response->assertForbidden();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.51')->exists())->toBeTrue();
    expect(DB::table('banned_ips')->where('reason', 'like', '%wordpress%')->exists())->toBeTrue();
});

test('banned IPs cannot access any routes', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.120']);

    DB::table('banned_ips')->insert([
        'ip' => '203.0.113.120',
        'reason' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Cache::flush();

    $response = $this->get('/');

    $response->assertForbidden();
});

test('service bans real client IP from request', function () {
    $request = Request::create('/test', 'GET');
    $request->server->set('HTTP_CF_CONNECTING_IP', '203.0.113.1');
    $request->server->set('HTTP_CF_RAY', 'test-ray-id');
    $request->headers->set('X-Forwarded-For', '198.51.100.1, 203.0.113.1');

    $service = app(IpBanService::class);
    $service->ban($request, 'Test ban');

    expect(DB::table('banned_ips')->where('ip', '203.0.113.1')->exists())->toBeTrue();
    expect(DB::table('banned_ips')->where('ip', '198.51.100.1')->exists())->toBeFalse();
});

test('service caches banned IP checks', function () {
    $request = Request::create('/test', 'GET');
    $request->server->set('REMOTE_ADDR', '203.0.113.100');

    DB::table('banned_ips')->insert([
        'ip' => '203.0.113.100',
        'reason' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(IpBanService::class);

    expect($service->isBanned($request))->toBeTrue();

    DB::table('banned_ips')->where('ip', '203.0.113.100')->delete();

    expect($service->isBanned($request))->toBeTrue();

    Cache::forget('banned_ip_203.0.113.100');
    expect($service->isBanned($request))->toBeFalse();
});

test('service can unban a specific IP', function () {
    $service = app(IpBanService::class);

    DB::table('banned_ips')->insert([
        'ip' => '203.0.113.200',
        'reason' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($service->unban('203.0.113.200'))->toBeTrue();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.200')->exists())->toBeFalse();
});

test('service can unban all IPs', function () {
    $service = app(IpBanService::class);

    DB::table('banned_ips')->insert([
        ['ip' => '203.0.113.1', 'reason' => 'Test', 'created_at' => now(), 'updated_at' => now()],
        ['ip' => '203.0.113.2', 'reason' => 'Test', 'created_at' => now(), 'updated_at' => now()],
        ['ip' => '203.0.113.3', 'reason' => 'Test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $count = $service->unbanAll();

    expect($count)->toBe(3);
    expect(DB::table('banned_ips')->count())->toBe(0);
});

test('artisan command unban specific IP', function () {
    DB::table('banned_ips')->insert([
        'ip' => '203.0.113.100',
        'reason' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('ip:unban', ['ip' => '203.0.113.100'])
        ->expectsOutput('IP address 203.0.113.100 has been unbanned.')
        ->assertSuccessful();

    expect(DB::table('banned_ips')->where('ip', '203.0.113.100')->exists())->toBeFalse();
});

test('artisan command unban all IPs', function () {
    DB::table('banned_ips')->insert([
        ['ip' => '203.0.113.1', 'reason' => 'Test', 'created_at' => now(), 'updated_at' => now()],
        ['ip' => '203.0.113.2', 'reason' => 'Test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('ip:unban', ['--all' => true])
        ->expectsOutput('Unbanned 2 IP address(es).')
        ->assertSuccessful();

    expect(DB::table('banned_ips')->count())->toBe(0);
});

test('artisan command validates IP address', function () {
    $this->artisan('ip:unban', ['ip' => 'invalid-ip'])
        ->expectsOutput('Invalid IP address: invalid-ip')
        ->assertFailed();
});

test('artisan command warns when IP not found', function () {
    $this->artisan('ip:unban', ['ip' => '203.0.113.1'])
        ->expectsOutput('IP address 203.0.113.1 was not found in the banned list.')
        ->assertSuccessful();
});

test('asset paths do not trigger IP bans', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60']);

    $this->get('/assets/index-CLpmX6V6.js');
    expect(DB::table('banned_ips')->where('ip', '203.0.113.60')->exists())->toBeFalse();

    $this->get('/assets/style.css');
    expect(DB::table('banned_ips')->where('ip', '203.0.113.60')->exists())->toBeFalse();

    $this->get('/build/app.js');
    expect(DB::table('banned_ips')->where('ip', '203.0.113.60')->exists())->toBeFalse();
});

test('non-asset 404s still trigger IP bans and return 403', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61']);

    $response = $this->get('/wordpress');
    $response->assertForbidden();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.61')->exists())->toBeTrue();
});

test('storage paths trigger IP bans when file does not exist and return 403', function () {
    DB::table('banned_ips')->whereIn('ip', ['203.0.113.62', '203.0.113.63'])->delete();
    Cache::flush();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62']);
    $response = $this->get('/storage/config.php');
    $response->assertForbidden();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.62')->exists())->toBeTrue();

    Cache::flush();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.63']);
    $response = $this->get('/storage/secret.txt');
    $response->assertForbidden();
    expect(DB::table('banned_ips')->where('ip', '203.0.113.63')->exists())->toBeTrue();
});

test('storage paths with existing files do not trigger IP bans', function () {
    $testFile = storage_path('app/public/test-file.txt');
    $testDir = dirname($testFile);
    if (! is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }
    file_put_contents($testFile, 'test content');

    DB::table('banned_ips')->where('ip', '203.0.113.65')->delete();
    Cache::flush();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.65']);

    try {
        $this->get('/storage/test-file.txt');
        expect(DB::table('banned_ips')->where('ip', '203.0.113.65')->exists())->toBeFalse();
    } finally {
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }
});
