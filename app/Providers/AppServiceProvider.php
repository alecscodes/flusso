<?php

namespace App\Providers;

use App\Models\User;
use App\Services\CurrencyService;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Claude signs in with the authorization code flow; routes are registered in boot().
        Passport::$deviceCodeGrantEnabled = false;

        // One per request, so rates and the primary currency are looked up once.
        $this->app->scoped(CurrencyService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePassport();

        // The first user owns the instance: registration and banned IPs are theirs to manage.
        Gate::define('manage-instance', fn (User $user) => $user->id === User::min('id'));

        RateLimiter::for('oauth-register', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * OAuth for the MCP connector. The keys live in storage/app, the only
     * directory that survives a deploy; the consent screen is an Inertia page.
     */
    protected function configurePassport(): void
    {
        Passport::loadKeysFrom(storage_path('app/oauth'));
        Passport::tokensExpireIn(new DateInterval('P30D'));
        Passport::refreshTokensExpireIn(new DateInterval('P1Y'));
        Passport::tokensCan(['mcp:use' => 'View and change your accounts, transactions and planned payments']);

        Passport::authorizationView(fn (array $parameters) => Inertia::render('Auth/OAuthAuthorize', [
            'clientName' => $parameters['client']->name,
            'redirectHost' => parse_url((string) $parameters['request']->query('redirect_uri'), PHP_URL_HOST) ?: null,
            'scopes' => array_map(fn ($scope) => $scope->description, $parameters['scopes']),
            'authToken' => $parameters['authToken'],
            'clientId' => $parameters['client']->getKey(),
            'state' => $parameters['request']->query('state'),
            'csrfToken' => csrf_token(),
            'authorizeUrl' => route('passport.authorizations.approve'),
        ])->toResponse(request()));
    }
}
