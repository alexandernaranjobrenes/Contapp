<?php

namespace App\Domains\Feedback\Models;

use App\Domains\Blog\Models\BlogPost;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una noticia de Pivox —una actualización, un aviso— que publica el
 * backoffice y ven todas las cuentas en el panel de la barra superior
 * (pestaña «Noticias»). Global, sin compañía.
 *
 * link_url es opcional: una dirección completa (https://…), que se abre en
 * otra pestaña, o una pantalla de Pivox (/dashboard), que se abre en la
 * misma.
 *
 * La noticia de un artículo del blog (blog_post_id, CLAUDE.md secc. 41) no
 * guarda imagen ni enlace: la imagen es la portada del artículo y el enlace,
 * el artículo, que se abre en otra pestaña. Se maneja desde el artículo
 * (NewsService::syncForBlogPost), no desde la pantalla de Noticias.
 */
class NewsPost extends Model
{
    public const MAX_TITLE = 150;

    public const MAX_BODY = 2000;

    /** Caché de la fecha de la última noticia (el punto del botón de la barra). */
    public const LATEST_CACHE_KEY = 'news_posts.latest_at';

    protected $fillable = ['title', 'body', 'link_url', 'image_path', 'created_by', 'updated_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'created_by');
    }

    /** El artículo del blog del que avisa, si es una noticia de un artículo. */
    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    public function isFromBlog(): bool
    {
        return $this->blog_post_id !== null;
    }

    /** ¿El enlace es una pantalla de Pivox y no otro sitio? */
    public function hasInternalLink(): bool
    {
        return ! $this->isFromBlog() && $this->link_url !== null && str_starts_with($this->link_url, '/');
    }

    /** La imagen: la propia, o la portada del artículo del que avisa. */
    public function imageUrl(MediaStorage $media): ?string
    {
        if ($this->isFromBlog()) {
            return $this->blogPost?->cover?->url();
        }

        return $media->versionedUrl($this->image_path, $this->updated_at);
    }

    /** El enlace: el propio, o el artículo del que avisa. */
    public function linkUrl(): ?string
    {
        return $this->isFromBlog() ? $this->blogPost?->url() : $this->link_url;
    }

    /** Qué abre la noticia: 'article', 'internal' (una pantalla), 'external' o null. */
    public function linkKind(): ?string
    {
        return match (true) {
            $this->isFromBlog() => 'article',
            $this->link_url === null => null,
            $this->hasInternalLink() => 'internal',
            default => 'external',
        };
    }
}
