<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePassport();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
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
            'scopes' => array_map(fn ($scope) => $scope->description, $parameters['scopes']),
            'authToken' => $parameters['authToken'],
            'clientId' => $parameters['client']->getKey(),
            'state' => $parameters['request']->query('state'),
            'csrfToken' => csrf_token(),
            'authorizeUrl' => route('passport.authorizations.approve'),
        ]));
    }
}
