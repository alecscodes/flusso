<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Token;

/**
 * The apps (e.g. Claude) the user has authorized through OAuth, with a way to cut them off.
 */
class ConnectedAppsController extends Controller
{
    public function index(Request $request): Response
    {
        $apps = $request->user()->tokens()->with(['client', 'refreshToken'])->get()
            ->filter(fn (Token $token) => $this->isAlive($token))
            ->groupBy('client_id')
            ->map(fn ($tokens) => [
                'id' => $tokens->first()->client_id,
                'name' => $tokens->first()->client->name,
                'connected_at' => $tokens->min('created_at'),
                'last_used_at' => $tokens->max('created_at'),
                'expires_at' => $tokens->max('expires_at'),
            ])
            ->sortBy('name')
            ->values();

        return Inertia::render('Settings/ConnectedApps', ['apps' => $apps]);
    }

    /**
     * Revoke every access and refresh token the user gave this app.
     */
    public function destroy(Request $request, string $client): RedirectResponse
    {
        $tokens = $request->user()->tokens()->where('client_id', $client)->with('refreshToken')->get();

        $tokens->each(function (Token $token) {
            $token->refreshToken?->revoke();
            $token->revoke();
        });

        FlashToast::success('Access revoked.');

        return redirect()->route('connected-apps.index');
    }

    /**
     * Usable now, or renewable with a refresh token that is still good.
     */
    private function isAlive(Token $token): bool
    {
        if ($token->revoked) {
            return false;
        }

        $refresh = $token->refreshToken;

        return $token->expires_at === null
            || $token->expires_at->isFuture()
            || ($refresh !== null && ! $refresh->revoked && $refresh->expires_at->isFuture());
    }
}
