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

        // Customer accounts. Per-email limits do the real work: behind the
        // storefront proxy (Render demo) every visitor shares one IP, so the
        // per-IP limits are deliberately looser (DEPLOYMENT.md, Limitations).
        $email = fn (Request $request) => mb_strtolower(trim((string) $request->input('email')));
        RateLimiter::for('account-register', fn (Request $request) => [
            Limit::perMinute(10)->by('register|'.$request->ip()),
            Limit::perHour(60)->by('register-hour|'.$request->ip()),
        ]);
        RateLimiter::for('account-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login|'.$email($request).'|'.$request->ip()),
            Limit::perMinute(30)->by('login|'.$request->ip()),
        ]);
        RateLimiter::for('account-password-email', fn (Request $request) => [
            Limit::perMinute(3)->by('forgot|'.$email($request).'|'.$request->ip()),
            Limit::perHour(30)->by('forgot|'.$request->ip()),
        ]);
        // Signed links and reset tokens: guessing is hopeless, but keep it slow.
        RateLimiter::for('account-links', fn (Request $request) => Limit::perMinute(20)->by('links|'.$request->ip()));
        RateLimiter::for('account-verification', fn (Request $request) => [
            Limit::perMinute(2)->by('verify|'.$request->user()?->id),
            Limit::perHour(6)->by('verify-hour|'.$request->user()?->id),
        ]);
        // Endpoints that check the current password.
        RateLimiter::for('account-sensitive', fn (Request $request) => [
            Limit::perMinute(6)->by('sensitive|'.$request->user()?->id),
            Limit::perHour(30)->by('sensitive-hour|'.$request->user()?->id),
        ]);

        // Checkout and orders (same shared-IP caveat as above).
        $visitor = fn (Request $request) => $request->hasSession() ? $request->session()->getId() : $request->ip();
        RateLimiter::for('checkout-quote', fn (Request $request) => Limit::perMinute(60)->by('quote|'.$visitor($request)));
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by('checkout|'.$visitor($request)),
            Limit::perMinute(60)->by('checkout-ip|'.$request->ip()),
        ]);
        RateLimiter::for('order-lookup', fn (Request $request) => [
            Limit::perMinute(3)->by('lookup|'.mb_strtoupper(trim((string) $request->input('reference')))),
            Limit::perHour(30)->by('lookup-ip|'.$request->ip()),
        ]);

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        // Only administrators may manage the catalog. Enforced on every admin
        // route (never by hiding UI).
        Gate::define('manage-catalog', fn (User $user) => $user->isAdmin());

        // The storefront account area is for customers only; administrators
        // keep a separate sign-in at /admin.
        Gate::define('use-customer-account', fn (User $user) => $user->isCustomer());
    }
}
