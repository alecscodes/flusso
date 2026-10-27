<?php

use App\Mcp\Servers\FlussoServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// Dynamic client registration is open by design (Claude registers itself); only that POST is throttled,
// the discovery documents are cheap GETs that clients poll.
Mcp::oauthRoutes();
collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'mcp.oauth.register')?->middleware('throttle:oauth-register');

Mcp::web('/mcp', FlussoServer::class)->middleware(['auth:api', CheckToken::using('mcp:use'), 'throttle:120,1']);
