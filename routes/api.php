<?php

use App\Http\Controllers\Api\UserAvatarController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserTokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // 1. Profil de l'utilisateur connecté (avec rate limit de 60requêtes/minute)
    Route::middleware('throttle:api')->group(function (): void {
        Route::get('/user', [UserController::class, 'show']);
    });
    // 2. Gestion de l'avatar (avec rate limit strict anti-abus uploads de 10/min)
    Route::middleware('throttle:uploads')->group(function (): void {
        Route::post('/user/avatar', [
            UserAvatarController::class,
            'update'
        ]);
        Route::delete('/user/avatar', [
            UserAvatarController::class,
            'destroy'
        ]);
    });
    // 3. Gestion des Jetons d'accès personnels (Sanctum Tokens)
    Route::middleware('throttle:api')->group(function (): void {
        Route::get('/user/tokens', [
            UserTokenController::class,
            'index'
        ]);
        Route::post('/user/tokens', [
            UserTokenController::class,
            'store'
        ]);
        Route::delete(
            '/user/tokens/{token}',
            [UserTokenController::class, 'destroy']
        );
    });
});
