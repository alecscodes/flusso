<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UnbanIpRequest;
use App\Services\IpBanService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BannedIpsController extends Controller
{
    /**
     * Display the banned IPs page.
     */
    public function index(): Response
    {
        $bannedIps = DB::table('banned_ips')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($ip) => [
                'ip' => $ip->ip,
                'reason' => $ip->reason,
                'banned_at' => $ip->created_at,
            ]);

        return Inertia::render('Settings/BannedIps', [
            'bannedIps' => $bannedIps,
        ]);
    }

    /**
     * Unban a specific IP address.
     */
    public function destroy(UnbanIpRequest $request, IpBanService $ipBanService): RedirectResponse
    {
        $ip = $request->validated('ip');

        if ($ipBanService->unban($ip)) {
            FlashToast::success("IP address {$ip} has been unbanned.");
        } else {
            FlashToast::error("IP address {$ip} was not found in the banned list.");
        }

        return redirect()->route('settings.banned-ips.index');
    }
}
