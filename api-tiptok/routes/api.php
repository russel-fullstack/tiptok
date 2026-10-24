<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
});



use Illuminate\Http\JsonResponse;

Route::prefix('v1')->group(function (): void {

    Route::get('/health', function (): JsonResponse {
        return response()->json([
            'success' => true,
            'application' => 'TipTok API',
            'version' => '1.0.0',
            'message' => 'L’API TipTok fonctionne correctement.',
        ]);
    });

     Route::middleware('auth:sanctum')->group(function () {
        // Les routes protégées seront ajoutées ici.
    });
});
