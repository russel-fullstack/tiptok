<?php

namespace AppActionsUser;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class DeleteUserAvatarAction
{
    /**
     * Supprime définitivement l'avatar physique et réinitialise le profil.
     */
    public function execute(User $user): User
    {
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->forceFill([
            'avatar_path' => null,
        ])->save();
        return $user;
    }
}
