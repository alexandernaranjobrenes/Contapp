<?php

namespace App\Domains\Blog\Services;

use App\Domains\Blog\Exceptions\BlogException;
use App\Domains\Blog\Models\BlogImage;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Blog\Models\BlogPostSlug;
use App\Domains\Blog\Support\BlogHtml;
use App\Domains\Feedback\Services\NewsService;
use App\Domains\Licensing\Models\Propietario;
use App\Domains\Site\Services\SitemapBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guardar, publicar y eliminar artículos del blog (CLAUDE.md secc. 41).
 *
 * Al guardar:
 * - el HTML se limpia (BlogHtml) y solo quedan las imágenes que el artículo
 *   puede usar: las recién subidas y las suyas;
 * - las imágenes que usa pasan a ser del artículo, y las suyas que ya no usa
 *   se borran del bucket (secc. 34: sin basura);
 * - si cambió la dirección de un artículo que ya se publicó, la anterior
 *   queda para redirigir con un 301;
 * - la noticia que avisa del artículo se publica, se actualiza o se quita
 *   (NewsService::syncForBlogPost), y el sitemap se vuelve a armar.
 */
class BlogPostService
{
    /** La zona de las fechas que se escriben en el editor. */
    public const TIMEZONE = 'America/Costa_Rica';

    public function __construct(
        private readonly BlogImageService $images,
        private readonly NewsService $news,
    ) {}

    /**
     * Crea el artículo, o guarda los cambios de uno que ya existe.
     *
     * @param  array{title: string, slug?: ?string, excerpt?: ?string, content: string, category_id?: ?int, cover_image_id?: ?int, cover_alt?: ?string, author_name?: ?string, seo_title?: ?string, meta_description?: ?string, focus_keyword?: ?string, canonical_url?: ?string, noindex?: bool, status: string, published_at?: ?string, announce_in_news?: bool, news_title?: ?string, news_body?: ?string}  $data
     *
     * @throws BlogException
     */
    public function save(BlogPost $post, array $data, Propietario $by): BlogPost
    {
        $publishing = $data['status'] === BlogPost::STATUS_PUBLISHED;
        $cover = empty($data['cover_image_id']) ? null : (int) $data['cover_image_id'];

        // Las imágenes que puede usar: las que todavía no son de nadie y las
        // que ya son de este artículo. La de otro artículo, no.
        $requested = array_values(array_unique(array_filter([...BlogHtml::imageIds($data['content']), $cover])));
        $usable = BlogImage::query()
            ->whereIn('id', $requested)
            ->whereNotNull('path')
            ->where(fn ($query) => $query->whereNull('post_id')->when($post->exists, fn ($query) => $query->orWhere('post_id', $post->id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $content = BlogHtml::clean($data['content'], $usable);

        if ($cover !== null && ! in_array($cover, $usable, true)) {
            throw new BlogException('Esa portada ya no está disponible. Elegí la imagen de nuevo.', 'cover_image_id');
        }

        $words = BlogHtml::wordCount($content);

        if ($publishing && $words === 0) {
            throw new BlogException('Para publicar, el artículo necesita texto.', 'content');
        }

        if ($publishing && $cover === null) {
            throw new BlogException('Para publicar, el artículo necesita una portada: es la imagen de su tarjeta, de las redes sociales y de la noticia.', 'cover_image_id');
        }

        $slug = $this->slug($data, $post);
        $publishedAt = $this->publishedAt($data, $post, $publishing);
        $kept = array_values(array_unique(array_filter([...BlogHtml::imageIds($content), $cover])));
        $removed = new Collection;

        DB::transaction(function () use ($post, $data, $by, $content, $cover, $words, $slug, $publishing, $publishedAt, $kept, &$removed) {
            $previousSlug = $post->slug;
            $wasPublic = $post->exists && $post->published_at !== null;

            $post->fill([
                'category_id' => $data['category_id'] ?? null,
                'title' => trim($data['title']),
                'slug' => $slug,
                'excerpt' => self::nullable($data['excerpt'] ?? null),
                'content' => $content,
                'status' => $publishing ? BlogPost::STATUS_PUBLISHED : BlogPost::STATUS_DRAFT,
                'published_at' => $publishedAt,
                'cover_image_id' => $cover,
                'cover_alt' => $cover === null ? null : self::nullable($data['cover_alt'] ?? null),
                'author_name' => self::nullable($data['author_name'] ?? null),
                'seo_title' => self::nullable($data['seo_title'] ?? null),
                'meta_description' => self::nullable($data['meta_description'] ?? null),
                'focus_keyword' => self::nullable($data['focus_keyword'] ?? null),
                'canonical_url' => self::nullable($data['canonical_url'] ?? null),
                'noindex' => (bool) ($data['noindex'] ?? false),
                'word_count' => $words,
                'reading_minutes' => max(1, (int) ceil($words / BlogPost::WORDS_PER_MINUTE)),
                'announce_in_news' => (bool) ($data['announce_in_news'] ?? false),
                'news_title' => self::nullable($data['news_title'] ?? null),
                'news_body' => self::nullable($data['news_body'] ?? null),
                'updated_by' => $by->id,
            ]);

            if (! $post->exists) {
                $post->created_by = $by->id;
            }

            $post->save();

            // La dirección anterior de un artículo que alguien pudo ver.
            if ($wasPublic && $previousSlug !== null && $previousSlug !== $post->slug) {
                BlogPostSlug::query()->updateOrCreate(['slug' => $previousSlug], ['post_id' => $post->id]);
            }

            // Si vuelve a una dirección que ya tuvo, deja de ser una «anterior».
            BlogPostSlug::query()->where('slug', $post->slug)->delete();

            BlogImage::query()->whereIn('id', $kept)->update(['post_id' => $post->id]);
            $removed = BlogImage::query()->where('post_id', $post->id)->whereNotIn('id', $kept)->get();
        });

        $this->images->delete($removed);
        $this->news->syncForBlogPost($post, $by);
        SitemapBuilder::forget();

        return $post;
    }

    /** Elimina el artículo, sus imágenes del bucket y la noticia que lo avisaba. */
    public function delete(BlogPost $post): void
    {
        $images = $post->images()->get();

        $this->news->deleteForBlogPost($post);

        DB::transaction(fn () => $post->delete());

        $this->images->delete($images);
        SitemapBuilder::forget();
    }

    /**
     * La dirección del artículo. Escrita a mano, tiene que estar libre (se
     * avisa, no se cambia en silencio); sacada del título, se le agrega un
     * número si hace falta.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BlogException
     */
    private function slug(array $data, BlogPost $post): string
    {
        $written = trim((string) ($data['slug'] ?? ''));

        if ($written !== '') {
            $slug = Str::slug($written);

            if (in_array($slug, BlogPost::RESERVED_SLUGS, true)) {
                throw new BlogException("La dirección «{$slug}» la usa el blog. Elegí otra.", 'slug');
            }

            if ($this->taken($slug, $post)) {
                throw new BlogException('Esa dirección ya la usa otro artículo. Elegí otra.', 'slug');
            }

            return $slug;
        }

        $base = rtrim(Str::limit(Str::slug($data['title']), BlogPost::MAX_SLUG, ''), '-') ?: 'articulo';

        if (in_array($base, BlogPost::RESERVED_SLUGS, true)) {
            $base .= '-articulo';
        }

        $slug = $base;

        for ($n = 2; $this->taken($slug, $post); $n++) {
            $slug = rtrim(Str::limit($base, BlogPost::MAX_SLUG - strlen("-{$n}"), ''), '-')."-{$n}";
        }

        return $slug;
    }

    /** ¿La usa otro artículo, ahora o antes? */
    private function taken(string $slug, BlogPost $post): bool
    {
        return BlogPost::query()->where('slug', $slug)->when($post->exists, fn ($query) => $query->whereKeyNot($post->id))->exists()
            || BlogPostSlug::query()->where('slug', $slug)->when($post->exists, fn ($query) => $query->where('post_id', '!=', $post->id))->exists();
    }

    /**
     * La fecha de publicación. Al publicar, la escrita (hora de Costa Rica),
     * la que ya tenía, o ahora. Nunca a futuro: el blog no programa
     * publicaciones. En borrador se conserva la que tenía, por si vuelve a
     * publicarse con su fecha.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BlogException
     */
    private function publishedAt(array $data, BlogPost $post, bool $publishing): ?Carbon
    {
        if (! $publishing) {
            return $post->published_at;
        }

        $written = trim((string) ($data['published_at'] ?? ''));

        if ($written === '') {
            return $post->published_at ?? now();
        }

        $date = Carbon::parse($written, self::TIMEZONE)->setTimezone(config('app.timezone'));

        if ($date->greaterThan(now()->addMinute())) {
            throw new BlogException('La fecha de publicación no puede ser futura.', 'published_at');
        }

        return $date;
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
