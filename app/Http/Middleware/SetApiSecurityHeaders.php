<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiSecurityHeaders
{
    /**
     * Conservative headers for JSON API responses. HSTS is left to the HTTPS
     * web server / proxy in staging and production.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        return $response;
    }
}
