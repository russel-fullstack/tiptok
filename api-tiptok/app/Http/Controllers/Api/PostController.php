<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $posts = Post::with(['user', 'tags', 'likedByUsers'])
            ->withCount(['likedByUsers', 'comments'])
            ->latest()
            ->paginate(10);

        return PostResource::collection($posts);
    }

    // POST /api/posts (Créer une publication)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string|max:500',
            'style_gradient' => 'nullable|string',
            'image_url' => 'nullable|url',
            'audio_title' => 'nullable|string',
        ]);

        $post = $request->user()->posts()->create($validated);

        return new PostResource($post->load(['user', 'tags', 'likedByUsers']));
    }

    // GET /api/posts/{post} (Afficher un post précis)
    public function show(Post $post)
    {
        $post->load(['user', 'tags', 'comments.user', 'likedByUsers'])
             ->loadCount(['likedByUsers', 'comments']);

        return new PostResource($post);
    }
}
