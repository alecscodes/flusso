<?php

use App\Http\Middleware\BlockBots;
use App\Http\Middleware\CheckBannedIp;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\IpBanService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        $exceptions->render(function (Throwable $e, Request $request) {
            $service = app(IpBanService::class);

            // If IP is already banned, return 403 immediately
            if ($service->isBanned($request)) {
                return response('Access denied', 403);
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

            // Handle non-storage paths - only process 404 exceptions
            if (! ($e instanceof NotFoundHttpException)) {
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
