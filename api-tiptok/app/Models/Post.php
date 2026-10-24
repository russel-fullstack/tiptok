<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'content',
        'style_gradient',
        'image_url',
        'audio_title',
    ];

    // Auteur du post
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Commentaires associés au post
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // Utilisateurs ayant aimé le post
    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'likes')->withTimestamps();
    }

    // Hashtags associés au post
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
