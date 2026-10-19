<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers of every response (global middleware, error pages too; ADR-0031): the browser does not guess the type of a
 * served file (project files), pages are not framed by other sites, and the address of a page is not sent elsewhere.
 * HSTS and the policy of sources come with HTTPS on the production server (D-01).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
