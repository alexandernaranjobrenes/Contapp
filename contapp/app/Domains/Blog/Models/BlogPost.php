<?php

namespace App\Domains\Blog\Models;

use App\Domains\Feedback\Models\NewsPost;
use App\Domains\Licensing\Models\Propietario;
use App\Domains\Site\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Un artículo del blog (CLAUDE.md secc. 41). Lo escribe el backoffice; el
 * sitio lo muestra en /blog/{slug} cuando está publicado.
 *
 * - content: HTML ya limpio (BlogHtml::clean). Sus imágenes van como
 *   <img data-image-id>, sin dirección.
 * - Publicado = status «published» y published_at ya llegó.
 * - announce_in_news: al publicarse, avisa en la pestaña Noticias de la
 *   aplicación, con news_title y news_body, la portada como imagen y el
 *   artículo como enlace (NewsService::syncForBlogPost).
 */
class BlogPost extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const MAX_TITLE = 200;

    public const MAX_SLUG = 190;

    public const MAX_EXCERPT = 300;

    public const MAX_SEO_TITLE = 70;

    public const MAX_META_DESCRIPTION = 170;

    public const MAX_COVER_ALT = 200;

    /** Palabras por minuto para el tiempo de lectura. */
    public const WORDS_PER_MINUTE = 200;

    /** Direcciones que ya usa el blog: un artículo no puede llamarse así. */
    public const RESERVED_SLUGS = ['feed', 'categoria', 'pagina'];

    protected $fillable = [
        'category_id', 'title', 'slug', 'excerpt', 'content', 'status', 'published_at', 'cover_image_id', 'cover_alt',
        'author_name', 'seo_title', 'meta_description', 'focus_keyword', 'canonical_url', 'noindex',
        'word_count', 'reading_minutes', 'announce_in_news', 'news_title', 'news_body', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'noindex' => 'boolean',
            'announce_in_news' => 'boolean',
            'word_count' => 'integer',
            'reading_minutes' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(BlogImage::class, 'cover_image_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(BlogImage::class, 'post_id');
    }

    public function previousSlugs(): HasMany
    {
        return $this->hasMany(BlogPostSlug::class, 'post_id');
    }

    /** La noticia que avisa de este artículo, si se publicó una. */
    public function news(): HasOne
    {
        return $this->hasOne(NewsPost::class, 'blog_post_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'updated_by');
    }

    /** Los que ve el sitio. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    /** La dirección absoluta del artículo en el sitio. */
    public function url(): string
    {
        return Seo::route('blog.show', $this->slug);
    }

    /** El título para el buscador: el propio de SEO, o el del artículo. */
    public function seoTitle(): string
    {
        return $this->seo_title ?: $this->title;
    }

    /**
     * La descripción para el buscador y las redes: la propia de SEO, el
     * resumen, o el comienzo del texto.
     */
    public function metaDescription(): string
    {
        $description = $this->meta_description ?: $this->excerpt;

        if ($description) {
            return $description;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace('<', ' <', $this->content))));

        return Str::limit($text, 155, '…');
    }

    /** El nombre que firma el artículo. */
    public function authorName(): string
    {
        return $this->author_name ?: 'Equipo Pivox';
    }
}
