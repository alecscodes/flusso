<?php

use App\Mcp\Servers\FlussoServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

// Dynamic client registration is open by design (Claude registers itself); keep it from being spammed.
collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'mcp.oauth.register')?->middleware('throttle:20,1');

Mcp::web('/mcp', FlussoServer::class)->middleware(['auth:api', 'throttle:120,1']);
