<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IpBanService
{
    private const int CACHE_TTL = 3600;

    private const int MAX_LOGIN_ATTEMPTS = 2;

    public function isBanned(Request $request): bool
    {
        $allIps = $this->getAllRealClientIps($request);

        if (empty($allIps)) {
            return false;
        }

        foreach ($allIps as $ip) {
            $isBanned = Cache::remember("banned_ip_{$ip}", self::CACHE_TTL, fn () => DB::table('banned_ips')->where('ip', $ip)->exists());

            if ($isBanned) {
                return true;
            }
        }

        return false;
    }

    public function ban(Request $request, ?string $reason = null): void
    {
        $allIps = $this->getAllRealClientIps($request);

        if (empty($allIps)) {
            return;
        }

        $now = now();

        DB::table('banned_ips')->insertOrIgnore(
            array_map(fn ($ip) => ['ip' => $ip, 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now], $allIps)
        );

        foreach ($allIps as $ip) {
            Cache::forget("banned_ip_{$ip}");
        }

        Log::warning('IP banned', [
            'category' => 'security',
            'ips' => $allIps,
            'reason' => $reason,
            'path' => $request->path(),
        ]);
    }

    public function recordFailedLogin(Request $request): bool
    {
        $allIps = $this->getAllRealClientIps($request);

        if (empty($allIps)) {
            return false;
        }

        $primaryIp = $allIps[0];
        $key = "failed_login_{$primaryIp}";

        Cache::add($key, 0, self::CACHE_TTL);
        $count = Cache::increment($key);

        Log::warning('Failed login attempt', [
            'category' => 'security',
            'ip' => $primaryIp,
            'attempts' => $count,
            'email' => $request->input('email'),
        ]);

        if ($count >= self::MAX_LOGIN_ATTEMPTS) {
            $this->ban($request, 'Failed login attempts');
            Cache::forget($key);

            Log::warning('IP banned due to failed login attempts', [
                'category' => 'security',
                'ip' => $primaryIp,
                'attempts' => $count,
            ]);

            return true;
        }

        return false;
    }

    public function unban(string $ip): bool
    {
        $deleted = DB::table('banned_ips')->where('ip', $ip)->delete() > 0;

        if ($deleted) {
            Cache::forget("banned_ip_{$ip}");
            Log::info('IP unbanned', [
                'category' => 'security',
                'ip' => $ip,
            ]);
        }

        return $deleted;
    }

    public function unbanAll(): int
    {
        $ips = DB::table('banned_ips')->pluck('ip');
        $count = DB::table('banned_ips')->delete();

        foreach ($ips as $ip) {
            Cache::forget("banned_ip_{$ip}");
        }

        return $count;
    }

    public function shouldBanPath(string $path): bool
    {
        $excludedPaths = ['assets', 'build'];
        $excludedExtensions = ['.js', '.css', '.map', '.ico', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.woff', '.woff2', '.ttf', '.eot'];

        foreach ($excludedPaths as $excludedPath) {
            if (str_starts_with($path, $excludedPath)) {
                return false;
            }
        }

        foreach ($excludedExtensions as $extension) {
            if (str_ends_with($path, $extension)) {
                return false;
            }
        }

        return true;
    }

    private function getAllRealClientIps(Request $request): array
    {
        $server = fn (string $key) => $_SERVER[$key] ?? $request->server($key);

        $cfRay = $server('HTTP_CF_RAY');
        $cfVisitor = $server('HTTP_CF_VISITOR');
        $isCloudflare = ! empty($cfRay) || ! empty($cfVisitor);

        if ($isCloudflare) {
            $ips = array_merge(
                $this->extractValidIps($server('HTTP_CF_CONNECTING_IPV6')),
                $this->extractValidIps($server('HTTP_CF_CONNECTING_IP')),
            );

            // Behind Cloudflare but no CF-Connecting-IP — cannot trust other headers
            return array_values(array_unique($ips));
        }

        // Not behind Cloudflare: try headers in priority order
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            $ips = $this->extractValidIps($server($header));
            if (! empty($ips)) {
                return $ips;
            }
        }

        return [];
    }

    private function extractValidIps(?string $source): array
    {
        if (! $source) {
            return [];
        }

        $validIps = [];

        foreach (explode(',', $source) as $ip) {
            $ip = trim($ip);

            if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            if ($this->isPrivateIp($ip)) {
                continue;
            }

            $validIps[] = $ip;
        }

        return array_values(array_unique($validIps));
    }

    private function isPrivateIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
