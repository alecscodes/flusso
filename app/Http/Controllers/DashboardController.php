<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\InsightsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
        private InsightsService $insightsService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            ...$this->dashboardService->getDashboardData($user),
            'insights' => Inertia::defer(fn () => $this->insightsService->cardFor($user)),
        ]);
    }
}
