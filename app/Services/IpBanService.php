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
            $cacheKey = "banned_ip_{$ip}";
            $isBanned = Cache::remember($cacheKey, self::CACHE_TTL, fn () => DB::table('banned_ips')->where('ip', $ip)->exists()
            );

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
        $records = [];

        foreach ($allIps as $ip) {
            $records[] = [
                'ip' => $ip,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('banned_ips')->insertOrIgnore($records);

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
            'attempt_count' => $count,
        ]);

        if ($count >= self::MAX_LOGIN_ATTEMPTS) {
            $this->ban($request, 'Failed login attempts');
            Cache::forget($key);

            return true;
        }

        return false;
    }

    public function unban(string $ip): bool
    {
        $deleted = DB::table('banned_ips')->where('ip', $ip)->delete() > 0;

        if ($deleted) {
            Cache::forget("banned_ip_{$ip}");
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
        $allHeaders = [
            'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $request->server('HTTP_CF_CONNECTING_IP'),
            'HTTP_CF_CONNECTING_IPV6' => $_SERVER['HTTP_CF_CONNECTING_IPV6'] ?? $request->server('HTTP_CF_CONNECTING_IPV6'),
            'HTTP_X_REAL_IP' => $_SERVER['HTTP_X_REAL_IP'] ?? $request->server('HTTP_X_REAL_IP'),
            'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $request->server('HTTP_X_FORWARDED_FOR'),
            'HTTP_CF_RAY' => $_SERVER['HTTP_CF_RAY'] ?? $request->server('HTTP_CF_RAY'),
            'HTTP_CF_VISITOR' => $_SERVER['HTTP_CF_VISITOR'] ?? $request->server('HTTP_CF_VISITOR'),
            'HTTP_CF_IPCOUNTRY' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? $request->server('HTTP_CF_IPCOUNTRY'),
            'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? $request->server('REMOTE_ADDR'),
        ];

        // Check if request is behind Cloudflare
        $isCloudflareRequest = ! empty($allHeaders['HTTP_CF_RAY']) || ! empty($allHeaders['HTTP_CF_VISITOR']);

        $allClientIps = [];

        // Collect all IPs from Cloudflare headers (both IPv4 and IPv6)
        if ($isCloudflareRequest) {
            // Check CF-Connecting-IPv6 (contains real IPv6)
            $cfIpv6 = $allHeaders['HTTP_CF_CONNECTING_IPV6'];
            if ($cfIpv6) {
                $ips = $this->extractValidIps($cfIpv6);
                $allClientIps = array_merge($allClientIps, $ips);
            }

            // Check CF-Connecting-IP (contains real IPv4 or IPv6)
            $cfIp = $allHeaders['HTTP_CF_CONNECTING_IP'];
            if ($cfIp) {
                $ips = $this->extractValidIps($cfIp);
                $allClientIps = array_merge($allClientIps, $ips);
            }

            // If we found IPs from Cloudflare headers, return them (both IPv4 and IPv6)
            if (! empty($allClientIps)) {
                return array_values(array_unique($allClientIps));
            }

            // If behind Cloudflare but CF-Connecting-IP is missing, we cannot determine real client IP
            // Do not trust X-Real-IP or X-Forwarded-For as they may contain Cloudflare IPs
            return [];
        }

        // Not behind Cloudflare: use standard headers
        $sources = [
            'HTTP_X_REAL_IP' => $allHeaders['HTTP_X_REAL_IP'],
            'HTTP_X_FORWARDED_FOR' => $allHeaders['HTTP_X_FORWARDED_FOR'],
            'REMOTE_ADDR' => $allHeaders['REMOTE_ADDR'],
        ];

        foreach ($sources as $source) {
            $ips = $this->extractValidIps($source);
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
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE);
    }
}
