<?php

namespace App\Domains\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una dirección anterior de un artículo publicado: /blog/{slug} redirige con
 * un 301 a la actual, así no se pierde lo que un buscador ya indexó ni los
 * enlaces que alguien compartió.
 */
class BlogPostSlug extends Model
{
    protected $fillable = ['post_id', 'slug'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'post_id');
    }
}
