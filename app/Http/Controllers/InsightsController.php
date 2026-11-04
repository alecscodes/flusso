<?php

namespace App\Http\Controllers;

use App\Http\Requests\InsightsRequest;
use App\Services\InsightsNarrationService;
use App\Services\InsightsService;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController extends Controller
{
    public function __construct(
        private InsightsService $insightsService,
        private InsightsNarrationService $narrationService,
    ) {}

    public function index(InsightsRequest $request): Response
    {
        $user = $request->user();
        $insights = $this->insightsService->forPeriod($user, $request->anchor());
        $locale = $request->getPreferredLanguage(['ro', 'it', 'en']) ?? 'ro';

        return Inertia::render('Insights/Index', [
            'insights' => $insights,
            'narrative' => Inertia::defer(fn () => $this->narrationService->narrate($user, $insights, $locale)),
        ]);
    }
}
