<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\IpBanService;
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
    public function destroy(IpBanService $ipBanService): RedirectResponse
    {
        $ip = request()->input('ip');

        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return redirect()->route('banned-ips.index')
                ->with('error', 'Invalid IP address provided.');
        }

        $unbanned = $ipBanService->unban($ip);

        if ($unbanned) {
            return redirect()->route('banned-ips.index')
                ->with('success', "IP address {$ip} has been unbanned.");
        }

        return redirect()->route('banned-ips.index')
            ->with('error', "IP address {$ip} was not found in the banned list.");
    }
}
