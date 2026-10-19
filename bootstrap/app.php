<?php

use App\Http\Middleware\SetApiSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA auth: requests from SANCTUM_STATEFUL_DOMAINS get the
        // session cookie + CSRF protection; all other API calls stay stateless.
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->api(append: [SetApiSecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API errors are always JSON. With APP_DEBUG=false they contain only
        // a generic message: no stack trace, file paths or SQL.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
