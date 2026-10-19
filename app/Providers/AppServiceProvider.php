<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $isProduction = $this->app->isProduction();

        // Surface lazy loading, silently discarded (mass-assigned) attributes
        // and missing attributes as errors during development.
        Model::shouldBeStrict(! $isProduction);

        // Block migrate:fresh, db:wipe and similar commands in production.
        DB::prohibitDestructiveCommands($isProduction);

        URL::forceHttps($isProduction);

        // Baseline limit for every API route (covers search-as-you-type and
        // admin editing sessions).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Brute-force protection: per email+IP and per IP.
        RateLimiter::for('admin-login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        // Only administrators may manage the catalog. Enforced on every admin
        // route (never by hiding UI).
        Gate::define('manage-catalog', fn (User $user) => $user->isAdmin());
    }
}
