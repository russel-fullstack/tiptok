<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;

class LikeController extends Controller
{
   public function toggle(Request $request, Post $post)
    {
        $user = $request->user();

        // toggle() attache le ID s'il n'existe pas, ou le détache s'il existe déjà
        $changes = $user->likedPosts()->toggle($post->id);

        $isLiked = count($changes['attached']) > 0;

        return response()->json([
            'message' => $isLiked ? 'Post aimé' : 'Like retiré',
            'is_liked' => $isLiked,
            'likes_count' => $post->likedByUsers()->count(),
        ]);
    }
}
