<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class UpdateUserAvatarAction
{
    /**
     * Traite, optimise et associe un avatar à l'utilisateur.
     */
    public function execute(User $user, UploadedFile $file): User
    {
        // 1. Supprimer l'ancien avatar du disque s'il existe
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        // 2. Générer un nom unique aléatoire avec extension .webp
        $filename = 'avatars/' . Str::uuid() . '.webp';
        // 3. Redimensionner et compresser via Intervention Image v3
        $image = Image::read($file)
            ->cover(400, 400) // Rognage intelligent centré
            ->toWebp(85);
        // Qualité 85% WebP moderne
        // 4. Écrire le flux binaire sur le disque public
        Storage::disk('public')->put($filename, (string) $image);
        // 5. Mettre à jour le modèle utilisateur
        $user->forceFill([
            'avatar_path' => $filename,
        ])->save();
        return $user;
    }
}
