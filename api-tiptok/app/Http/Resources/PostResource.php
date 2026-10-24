<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id' => $this->id,
            'content' => $this->content,
            'style_gradient' => $this->style_gradient,
            'image_url' => $this->image_url,
            'audio_title' => $this->audio_title,
            'created_at' => $this->created_at->diffForHumans(),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar_url' => $this->user->avatar_url,
            ],
            'likes_count' => $this->liked_by_users_count ?? $this->likedByUsers()->count(),
            'comments_count' => $this->comments_count ?? $this->comments()->count(),
            'is_liked' => $request->user() ? $this->likedByUsers->contains($request->user()->id) : false,
            'tags' => $this->tags->map(fn($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ]),
        ];
    }
}
