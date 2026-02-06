<?php

namespace App\Http\Middleware;

use App\Services\IpBanService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckBannedIp
{
    public function __construct(
        private IpBanService $ipBanService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->ipBanService->isBanned($request)) {
            Log::warning('Banned IP access attempt', [
                'category' => 'security',
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}
