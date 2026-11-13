<?php

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'login',
            'register',
            'logout',
            'two-factor-challenge',
            'user/*',
            'sanctum/csrf-cookie',
            'forgot-password',
            'reset-password',
        ]);
        $middleware->append(SecurityHeadersMiddleware::class);
        // Supprime toute tentative de redirection HTML pour les routes d'API
        $middleware->redirectTo(
            guests: fn(Request $request) => $request->is('api/*') ? null : '/auth/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Force la sérialisation en JSON pour toutes les requêtes api/*
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') ||
                $request->expectsJson(),
        );
        // Capture proprement l'AuthenticationException pour renvoyer un JSON 401
        $exceptions->render(
            fn(
                AuthenticationException $e,
                Request $request
            ) => ($request->is('api/*') || $request->expectsJson())
                ? response()->json(['message' =>
                'Unauthenticated.'], 401)
                : null
        );
    })->create();
