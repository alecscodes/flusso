<?php

use App\Http\Middleware\BlockBots;
use App\Http\Middleware\CheckBannedIp;
use App\Http\Middleware\CloudflareClientIp;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\IpBanService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(CloudflareClientIp::class);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [
            BlockBots::class,
            CheckBannedIp::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            BlockBots::class,
            CheckBannedIp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // MCP clients get a 401 they can act on, never a redirect to the login page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is(IpBanService::JSON_PATHS) || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            $service = app(IpBanService::class);

            // If IP is already banned, return 403 immediately
            if ($service->isBanned($request)) {
                return response('Access denied', 403);
            }

            // Probes end in an HTTP error (404, or 403 from the storage route's signature check); a crash is not a probe.
            if (! ($e instanceof HttpExceptionInterface)) {
                return null;
            }

            $path = $request->path();

            // Handle storage paths - ban if file doesn't exist
            if (str_starts_with($path, 'storage/')) {
                $filePath = storage_path('app/public/'.ltrim(substr($path, 8), '/'));

                if (! file_exists($filePath)) {
                    Log::warning('Suspicious path access attempt', [
                        'category' => 'security',
                        'path' => $path,
                    ]);

                    $service->ban($request, "Non-existent storage file: {$path}");

                    return response('Access denied', 403);
                }

                // File exists - let normal 404 handling proceed
                return null;
            }

            // Only a path that matches no route is probing; a 404 from a matched route
            // (e.g. a payment deleted in another tab) must never ban the owner.
            if (! ($e instanceof NotFoundHttpException) || $request->route() !== null) {
                return null;
            }

            // Check if path should be banned
            if (! $service->shouldBanPath($path)) {
                return null;
            }

            Log::warning('Suspicious path access attempt', [
                'category' => 'security',
                'path' => $path,
            ]);

            $service->ban($request, "Non-existent route: {$path}");

            return response('Access denied', 403);
        });
    })->create();
