<?php

namespace App\Domains\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una categoría del blog (CLAUDE.md secc. 41): agrupa artículos y tiene su
 * propia página, /blog/categoria/{slug}, con su descripción como meta
 * descripción. Una categoría con artículos no se elimina.
 */
class BlogCategory extends Model
{
    public const MAX_NAME = 80;

    public const MAX_DESCRIPTION = 300;

    protected $fillable = ['name', 'slug', 'description'];

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }
}
