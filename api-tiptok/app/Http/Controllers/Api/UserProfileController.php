<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user('sanctum');   // route publique : session facultative

        return response()->json([
            'success' => true,
            'data' => [
                'id'              => $user->id,
                'name'            => $user->name,
                'username'        => $user->username,
                'bio'             => $user->bio,
                'avatar_url'      => $user->avatar_url,
                'followers_count' => $user->followers()->count(),
                'following_count' => $user->following()->count(),
                'is_following'    => $viewer
                    ? $viewer->following()->where('users.id', $user->id)->exists()
                    : false,
            ],
        ]);
    }
}
