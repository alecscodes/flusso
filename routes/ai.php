<?php

use App\Mcp\Servers\FlussoServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Dynamic client registration is open by design (Claude registers itself); keep it from being spammed.
// These routes are only the discovery documents and the registration endpoint.
Route::middleware('throttle:20,1')->group(fn () => Mcp::oauthRoutes());

Mcp::web('/mcp', FlussoServer::class)->middleware(['auth:api', 'throttle:120,1']);
