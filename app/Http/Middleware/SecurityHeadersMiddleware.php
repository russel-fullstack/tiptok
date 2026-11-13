<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // 1. Empêche l'inclusion du site dans des iframes externes (Anti-Clickjacking)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // 2. Interdit au navigateur de deviner le type de contenu (Anti-Sniffing)
        $response->headers->set(
            'X-Content-Type-Options',
            'nosniff'
        );
        // 3. Ne transmet pas d'URL sensible complète lors des clics vers l'extérieur
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // 4. Bloque l'accès matériel par défaut aux capteurs
        $response->headers->set('Permissions-Policy', 'camera=(),microphone=(), geolocation=()');
        return $response;
    }
}
