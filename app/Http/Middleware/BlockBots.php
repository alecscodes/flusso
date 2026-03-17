<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockBots
{
    private const array BOT_PATTERNS = [
        'bot', 'crawler', 'spider', 'scraper', 'crawl',
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
        'yandexbot', 'sogou', 'exabot', 'facebot', 'ia_archiver',
        'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'blexbot',
        'petalbot', 'applebot', 'facebookexternalhit', 'twitterbot',
        'rogerbot', 'linkedinbot', 'embedly', 'quora', 'pinterest',
        'slackbot', 'redditbot', 'applebot', 'whatsapp', 'flipboard',
        'tumblr', 'bitlybot', 'skypeuripreview', 'nuzzel', 'discordbot',
        'qwantify', 'pinterestbot', 'bitrix', 'wget', 'curl',
        'python', 'java', 'php', 'ruby', 'go-http', 'scrapy',
        'okhttp', 'http', 'libwww', 'lwp-trivial', 'wget',
        'python-requests', 'apache-httpclient', 'rest-client',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $userAgent = strtolower($request->userAgent() ?? '');

        // Block requests without User-Agent (many bots don't send one)
        if (empty($userAgent)) {
            abort(403, 'Access denied');
        }

        // Block known bot patterns
        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                abort(403, 'Access denied');
            }
        }

        $response = $next($request);

        // Add X-Robots-Tag header to all responses
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');

        return $response;
    }
}
