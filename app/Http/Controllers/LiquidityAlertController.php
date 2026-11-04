<?php

namespace App\Http\Controllers;

use App\Notifications\LiquidityAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LiquidityAlertController extends Controller
{
    /**
     * Mark the user's unread liquidity alerts as read.
     */
    public function markRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->where('type', LiquidityAlert::class)->update(['read_at' => now()]);

        return back();
    }
}
