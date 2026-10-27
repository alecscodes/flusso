<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\FlashToast;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * The apps (e.g. Claude) the user has authorized through OAuth, with a way to cut them off.
 */
class ConnectedAppsController extends Controller
{
    /**
     * `last_used_at` is when the app last got a token (every refresh issues one) and
     * `expires_at` when the connection lapses for good: the refresh token's expiry.
     */
    public function index(Request $request): Response
    {
        $apps = $request->user()->tokens()->where('revoked', false)->whereHas('client')->with(['client', 'refreshToken'])->get()
            ->filter(fn (Token $token) => $this->isAlive($token))
            ->groupBy('client_id')
            ->map(fn ($tokens) => [
                'id' => $tokens->first()->client_id,
                'name' => $tokens->first()->client->name,
                'connected_at' => $tokens->min('created_at'),
                'last_used_at' => $tokens->max('created_at'),
                'expires_at' => $tokens->map(fn (Token $token) => $this->lapsesAt($token))->max(),
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
        $ids = $request->user()->tokens()->where('client_id', $client)->where('revoked', false)->pluck('id');

        DB::transaction(function () use ($ids) {
            RefreshToken::whereIn('access_token_id', $ids)->update(['revoked' => true]);
            Token::whereIn('id', $ids)->update(['revoked' => true]);
        });

        if ($ids->isEmpty()) {
            FlashToast::error('That app is not connected.');
        } else {
            FlashToast::success('Access revoked.');
        }

        return redirect()->route('settings.connected-apps.index');
    }

    /**
     * Usable now, or renewable with a refresh token that is still good.
     */
    private function isAlive(Token $token): bool
    {
        return $this->lapsesAt($token)?->isFuture() ?? true;
    }

    /**
     * When the app can no longer act for the user without asking again; null if never.
     */
    private function lapsesAt(Token $token): ?CarbonInterface
    {
        $refresh = $token->refreshToken;

        return $refresh !== null && ! $refresh->revoked && $token->expires_at !== null
            ? max($token->expires_at, $refresh->expires_at)
            : $token->expires_at;
    }
}
