<?php

namespace App\Domains\Site\Services;

use App\Domains\Blog\Models\BlogCategory;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Site\Support\Seo;
use Illuminate\Support\Facades\Cache;

/**
 * El sitemap.xml del sitio público (CLAUDE.md secc. 40): la landing, los
 * planes, el blog, sus categorías con artículos y cada artículo publicado
 * (con su portada, para la búsqueda de imágenes). Uno marcado «no indexar»
 * no va. Queda en caché una hora; el blog la borra al publicar, editar o
 * eliminar un artículo o una categoría.
 */
class SitemapBuilder
{
    public const CACHE_KEY = 'site.sitemap';

    public function xml(): string
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn () => view('site.sitemap', [
            'entries' => $this->entries(),
        ])->render());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<array{loc: string, lastmod: ?string, images: list<string>}>
     */
    protected function entries(): array
    {
        $posts = BlogPost::query()
            ->published()
            ->where('noindex', false)
            ->with('cover')
            ->orderByDesc('published_at')
            ->get();

        $entries = [
            ['loc' => Seo::url('/'), 'lastmod' => null, 'images' => []],
            ['loc' => Seo::route('pricing.index'), 'lastmod' => null, 'images' => []],
            ['loc' => Seo::route('blog.index'), 'lastmod' => $posts->max('updated_at')?->toAtomString(), 'images' => []],
        ];

        $categories = BlogCategory::query()
            ->whereHas('posts', fn ($query) => $query->published())
            ->orderBy('name')
            ->get();

        foreach ($categories as $category) {
            $entries[] = ['loc' => Seo::route('blog.category', $category->slug), 'lastmod' => null, 'images' => []];
        }

        foreach ($posts as $post) {
            // Uno con canónica propia (publicado primero en otro sitio) no va:
            // el sitemap lista solo lo que es de este sitio.
            if ($post->canonical_url && $post->canonical_url !== $post->url()) {
                continue;
            }

            $entries[] = [
                'loc' => $post->url(),
                'lastmod' => $post->updated_at->toAtomString(),
                'images' => array_filter([$post->cover?->url()]),
            ];
        }

        return $entries;
    }
}
