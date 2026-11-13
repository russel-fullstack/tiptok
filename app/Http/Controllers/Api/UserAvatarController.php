<?php

namespace App\Http\Controllers\Api;

use App\Actions\User\UpdateUserAvatarAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use AppActionsUser\DeleteUserAvatarAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAvatarController extends Controller
{
    public function update(
        Request $request,
        UpdateUserAvatarAction $action
    ): UserResource {
        $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpeg,png,jpg,webp',
                'max:5120'
            ], // Max 5 Mo
        ]);
        $user = $action->execute($request->user(), $request->file('avatar'));
        return new UserResource($user);
    }
    /**
     * Supprime l'avatar existant et réinitialise le profil.
     */
    public function destroy(
        Request $request,
        DeleteUserAvatarAction $action
    ): JsonResponse {
        $action->execute($request->user());
        return response()->json([
            'message' => 'Avatar supprimé avec succès.',
        ]);
    }
}
