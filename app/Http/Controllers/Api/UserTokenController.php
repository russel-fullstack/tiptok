<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserTokenController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($request->user()->tokens()->latest()->get());
    }
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
        ]);
        $token = $request->user()->createToken(
            $validated['name'],
            $validated['abilities'] ?? ['*']
        );
        return response()->json([
            'token' => new TokenResource($token->accessToken),
            'plainTextToken' => $token->plainTextToken, // Renvoyé UNIQUEMENT à la création
        ], 201);
    }
    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();
        return response()->json([
            'message' => 'Jeton révoqué avec succès.',
        ]);
    }
}
