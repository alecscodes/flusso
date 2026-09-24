<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CloudflareClientIp
{
    /**
     * Traefik replaces X-Forwarded-For with the Cloudflare edge IP, so $request->ip()
     * would return Cloudflare. The server firewall only lets Cloudflare reach 80/443,
     * which makes CF-Connecting-IP trustworthy.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($ip = $request->header('CF-Connecting-IP')) {
            $request->headers->set('X-Forwarded-For', $ip);
        }

        return $next($request);
    }
}
