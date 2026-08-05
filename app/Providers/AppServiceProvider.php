<?php

namespace App\Providers;

use App\Mcp\OAuth\McpAccessToken;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::useAccessTokenEntity(McpAccessToken::class);
        Passport::tokensCan(config('shift_mcp.scopes'));
        Passport::defaultScopes(array_keys(config('shift_mcp.scopes')));
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));
        Passport::authorizationView(fn (array $parameters) => Inertia::render('auth/OAuth/Authorize', [
            'client' => [
                'name' => $parameters['client']->name,
            ],
            'scopes' => collect($parameters['scopes'])
                ->map(fn (Scope $scope): array => $scope->toArray())
                ->values()
                ->all(),
            'authToken' => $parameters['authToken'],
            'csrfToken' => csrf_token(),
            'approveUrl' => route('passport.authorizations.approve'),
            'denyUrl' => route('passport.authorizations.deny'),
        ]));

        RateLimiter::for('oauth-client-registration', function (Request $request): Limit {
            return Limit::perHour(10)->by('oauth-client-registration:'.$request->ip());
        });

        RateLimiter::for('ai-rewrite', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(20)->by('ai-rewrite:'.$key);
        });

        RateLimiter::for('ai-email-import', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(10)->by('ai-email-import:'.$key);
        });

        URL::forceScheme('https');
    }
}
