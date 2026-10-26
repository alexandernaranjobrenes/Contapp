<?php

namespace App\Domains\Blog\Support;

use App\Domains\Blog\Models\BlogCategory;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Site\Support\Seo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lo que muestra la página de un artículo del blog (CLAUDE.md secc. 41): el
 * HTML con sus imágenes, el índice de títulos, los artículos relacionados y
 * todo lo de SEO (título, descripción, canónica, Open Graph, BlogPosting y
 * migas de pan en JSON-LD). La usan el sitio y la vista previa del
 * backoffice, para que la vista previa sea exactamente la página.
 */
final class BlogPage
{
    /**
     * @return array{seo: Seo, post: BlogPost, html: string, toc: list<array{id: string, text: string, level: int}>, related: Collection<int, BlogPost>, preview: bool}
     */
    public static function article(BlogPost $post, bool $preview = false): array
    {
        $post->loadMissing(['category', 'cover']);

        ['html' => $html, 'toc' => $toc] = BlogHtml::render($post->content);

        $canonical = $post->canonical_url ?: $post->url();
        $cover = $post->cover?->path ? $post->cover : null;

        $crumbs = [['Inicio', Seo::url('/')], ['Blog', Seo::route('blog.index')]];
        if ($post->category) {
            $crumbs[] = [$post->category->name, Seo::route('blog.category', $post->category->slug)];
        }
        $crumbs[] = [$post->title, $post->url()];

        $seo = new Seo(
            title: Seo::title($post->seoTitle()),
            description: $post->metaDescription(),
            canonical: $canonical,
            image: $cover?->url(),
            imageWidth: $cover?->width,
            imageHeight: $cover?->height,
            imageAlt: $post->cover_alt ?: $post->title,
            type: 'article',
            index: ! $preview && ! $post->noindex && $post->isPublished(),
            article: [
                'published_time' => $post->published_at?->toIso8601String(),
                'modified_time' => $post->updated_at->toIso8601String(),
                'section' => $post->category?->name,
                'author' => $post->authorName(),
                'tags' => $post->focus_keyword ? [$post->focus_keyword] : [],
            ],
            schema: [Seo::organization(), self::posting($post, $canonical), Seo::breadcrumbs($crumbs)],
            socialTitle: $post->seoTitle(),
        );

        return [
            'seo' => $seo,
            'post' => $post,
            'html' => $html,
            'toc' => $toc,
            'related' => self::related($post),
            'preview' => $preview,
        ];
    }

    /**
     * El nodo BlogPosting de schema.org.
     *
     * @return array<string, mixed>
     */
    private static function posting(BlogPost $post, string $canonical): array
    {
        $cover = $post->cover?->path ? $post->cover : null;

        return array_filter([
            '@type' => 'BlogPosting',
            '@id' => $canonical.'#articulo',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'url' => $canonical,
            'headline' => Str::limit($post->title, 110, ''),
            'description' => $post->metaDescription(),
            'image' => $cover ? [array_filter([
                '@type' => 'ImageObject',
                'url' => $cover->url(),
                'width' => $cover->width,
                'height' => $cover->height,
            ])] : null,
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at->toIso8601String(),
            // Con un nombre escrito, firma una persona; sin él, Pivox.
            'author' => $post->author_name
                ? ['@type' => 'Person', 'name' => $post->author_name]
                : ['@type' => 'Organization', 'name' => 'Equipo Pivox', 'url' => Seo::url('/')],
            'publisher' => ['@id' => Seo::url('/#organizacion')],
            'articleSection' => $post->category?->name,
            'keywords' => $post->focus_keyword,
            'wordCount' => $post->word_count,
            'inLanguage' => 'es-CR',
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Tres artículos para seguir leyendo: los últimos de la misma categoría
     * y, si no alcanzan, los últimos del blog.
     *
     * @return Collection<int, BlogPost>
     */
    private static function related(BlogPost $post): Collection
    {
        $query = fn () => BlogPost::query()->published()->with(['category', 'cover'])->whereKeyNot($post->id)->orderByDesc('published_at');

        $related = $post->category_id
            ? $query()->where('category_id', $post->category_id)->limit(3)->get()
            : new Collection;

        if ($related->count() < 3) {
            $related = $related->concat($query()->whereNotIn('id', $related->pluck('id'))->limit(3 - $related->count())->get());
        }

        return $related->values();
    }

    /**
     * El nodo Blog de schema.org para el índice o una categoría, con sus
     * artículos de la página.
     *
     * @param  iterable<BlogPost>  $posts
     * @return array<string, mixed>
     */
    public static function blogSchema(iterable $posts, ?BlogCategory $category, string $url): array
    {
        return [
            '@type' => $category ? 'CollectionPage' : 'Blog',
            '@id' => $url.'#blog',
            'url' => $url,
            'name' => $category ? $category->name.' | Blog de Pivox' : 'Blog de Pivox',
            'inLanguage' => 'es-CR',
            'publisher' => ['@id' => Seo::url('/#organizacion')],
            'hasPart' => collect($posts)->map(fn (BlogPost $post) => [
                '@type' => 'BlogPosting',
                'headline' => Str::limit($post->title, 110, ''),
                'url' => $post->url(),
                'datePublished' => $post->published_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** «25 de octubre de 2026», en la hora de Costa Rica. */
    public static function date(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return \Illuminate\Support\Carbon::instance($date)->setTimezone('America/Costa_Rica')->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }
}
