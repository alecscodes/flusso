<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    public function index(Request $request): Response
    {
        $dashboardData = $this->dashboardService->getDashboardData($request->user());

        return Inertia::render('Dashboard', [
            'accounts' => $dashboardData['accounts'],
            'period' => $dashboardData['period'],
            'summary' => $dashboardData['summary'],
            'upcomingPayments' => $dashboardData['upcomingPayments'],
            'overduePayments' => $dashboardData['overduePayments'],
            'categorySpending' => $dashboardData['categorySpending'],
            'paymentSummary' => $dashboardData['paymentSummary'],
        ]);
    }
}
