<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Blog\Exceptions\BlogException;
use App\Domains\Blog\Models\BlogCategory;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Blog\Services\BlogPostService;
use App\Domains\Blog\Support\BlogHtml;
use App\Domains\Blog\Support\BlogPage;
use App\Domains\Feedback\Models\NewsPost;
use App\Domains\Feedback\Support\AttachedImage;
use App\Domains\Licensing\Models\Propietario;
use App\Domains\Site\Support\Seo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El blog desde el backoffice (CLAUDE.md secc. 41): la lista de artículos, el
 * editor (una pantalla propia: un editor como Word no cabe en un modal,
 * secc. 21), la vista previa y eliminar. Las imágenes se suben aparte
 * (BlogImageController) y las categorías se manejan desde la lista
 * (BlogCategoryController).
 */
class BlogPostController extends Controller
{
    public function __construct(private readonly BlogPostService $posts) {}

    public function index(): Response
    {
        $posts = BlogPost::query()
            ->with(['category:id,name', 'cover', 'news:id,blog_post_id', 'updater:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (BlogPost $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'status' => $post->status,
                'is_published' => $post->isPublished(),
                'published_at' => $post->published_at?->toIso8601String(),
                'updated_at' => $post->updated_at->toIso8601String(),
                'updated_by' => $post->updater?->name,
                'category_id' => $post->category_id,
                'category' => $post->category?->name,
                'cover_url' => $post->cover?->url(),
                'excerpt' => $post->excerpt,
                'seo_title' => $post->seoTitle(),
                'meta_description' => $post->metaDescription(),
                'noindex' => $post->noindex,
                'word_count' => $post->word_count,
                'reading_minutes' => $post->reading_minutes,
                'announce_in_news' => $post->announce_in_news,
                'has_news' => $post->news !== null,
                'url' => $post->isPublished() ? $post->url() : null,
                'preview_url' => route('backoffice.blog.preview', $post),
                'edit_url' => route('backoffice.blog.edit', $post),
            ]);

        return Inertia::render('Backoffice/Blog/Index', [
            'posts' => $posts,
            'categories' => BlogCategory::query()
                ->withCount('posts')
                ->orderBy('name')
                ->get()
                ->map(fn (BlogCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'posts_count' => $category->posts_count,
                    'url' => Seo::route('blog.category', $category->slug),
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->editor(null, $request->user('propietario'));
    }

    public function edit(Request $request, BlogPost $post): Response
    {
        return $this->editor($post, $request->user('propietario'));
    }

    public function store(Request $request): RedirectResponse
    {
        $post = new BlogPost;

        try {
            $this->posts->save($post, $this->validated($request), $request->user('propietario'));
        } catch (BlogException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()->route('backoffice.blog.edit', $post)->with('success', $this->message($post, false));
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $wasPublished = $post->isPublished();

        try {
            $this->posts->save($post, $this->validated($request), $request->user('propietario'));
        } catch (BlogException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', $this->message($post, $wasPublished));
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $this->posts->delete($post);

        return redirect()->route('backoffice.blog.index')->with('success', 'El artículo se eliminó, junto con sus imágenes y su noticia.');
    }

    /** La página del artículo como la vería un visitante, también en borrador. */
    public function preview(BlogPost $post): View
    {
        return view('site.blog.show', BlogPage::article($post, preview: true));
    }

    private function editor(?BlogPost $post, Propietario $by): Response
    {
        $post?->loadMissing(['cover', 'news']);

        return Inertia::render('Backoffice/Blog/Edit', [
            'post' => $post ? [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'excerpt' => $post->excerpt,
                'content' => BlogHtml::forEditor($post->content),
                'status' => $post->status,
                'is_published' => $post->isPublished(),
                'published_at' => $post->published_at?->copy()->setTimezone(BlogPostService::TIMEZONE)->format('Y-m-d\TH:i'),
                'category_id' => $post->category_id,
                'cover' => $post->cover ? [
                    'id' => $post->cover->id,
                    'url' => $post->cover->url(),
                    'width' => $post->cover->width,
                    'height' => $post->cover->height,
                ] : null,
                'cover_alt' => $post->cover_alt,
                'author_name' => $post->author_name,
                'seo_title' => $post->seo_title,
                'meta_description' => $post->meta_description,
                'focus_keyword' => $post->focus_keyword,
                'canonical_url' => $post->canonical_url,
                'noindex' => $post->noindex,
                'announce_in_news' => $post->announce_in_news,
                'news_title' => $post->news_title,
                'news_body' => $post->news_body,
                'has_news' => $post->news !== null,
                'url' => $post->isPublished() ? $post->url() : null,
                'preview_url' => route('backoffice.blog.preview', $post),
                'updated_at' => $post->updated_at->toIso8601String(),
            ] : null,
            'categories' => BlogCategory::query()->orderBy('name')->get(['id', 'name']),
            'authorName' => $by->name,
            'imageRules' => AttachedImage::clientRules(),
            // Cómo se ve la dirección en un buscador: «pivoxcr.com › blog › …».
            'blogUrl' => Seo::route('blog.index'),
            'limits' => [
                'title' => BlogPost::MAX_TITLE,
                'slug' => BlogPost::MAX_SLUG,
                'excerpt' => BlogPost::MAX_EXCERPT,
                'seo_title' => BlogPost::MAX_SEO_TITLE,
                'meta_description' => BlogPost::MAX_META_DESCRIPTION,
                'cover_alt' => BlogPost::MAX_COVER_ALT,
                'news_title' => NewsPost::MAX_TITLE,
                'news_body' => NewsPost::MAX_BODY,
                'title_ideal' => Seo::TITLE_LIMIT,
                'description_ideal' => Seo::DESCRIPTION_LIMIT,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.BlogPost::MAX_TITLE],
            'slug' => ['nullable', 'string', 'max:'.BlogPost::MAX_SLUG],
            'excerpt' => ['nullable', 'string', 'max:'.BlogPost::MAX_EXCERPT],
            'content' => ['present', 'nullable', 'string', 'max:2000000'],
            'category_id' => ['nullable', 'integer', Rule::exists('blog_categories', 'id')],
            'cover_image_id' => ['nullable', 'integer'],
            'cover_alt' => ['nullable', 'required_with:cover_image_id', 'string', 'max:'.BlogPost::MAX_COVER_ALT],
            'author_name' => ['nullable', 'string', 'max:120'],
            'seo_title' => ['nullable', 'string', 'max:'.BlogPost::MAX_SEO_TITLE],
            'meta_description' => ['nullable', 'string', 'max:'.BlogPost::MAX_META_DESCRIPTION],
            'focus_keyword' => ['nullable', 'string', 'max:80'],
            'canonical_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'noindex' => ['boolean'],
            'status' => ['required', Rule::in([BlogPost::STATUS_DRAFT, BlogPost::STATUS_PUBLISHED])],
            'published_at' => ['nullable', 'date'],
            'announce_in_news' => ['boolean'],
            'news_title' => ['nullable', 'required_if_accepted:announce_in_news', 'string', 'max:'.NewsPost::MAX_TITLE],
            'news_body' => ['nullable', 'required_if_accepted:announce_in_news', 'string', 'max:'.NewsPost::MAX_BODY],
        ], [
            'cover_alt.required_with' => 'Describí la portada en pocas palabras: la leen quien no puede verla y los buscadores.',
            'news_title.required_if_accepted' => 'Para avisar en Noticias, escribí el título de la noticia.',
            'news_body.required_if_accepted' => 'Para avisar en Noticias, escribí la descripción de la noticia.',
            'canonical_url.url' => 'La URL canónica tiene que ser una dirección completa (https://…).',
        ], [
            'title' => 'título',
            'slug' => 'dirección',
            'excerpt' => 'resumen',
            'content' => 'texto',
            'category_id' => 'categoría',
            'cover_alt' => 'descripción de la portada',
            'author_name' => 'autor',
            'seo_title' => 'título para buscadores',
            'meta_description' => 'descripción para buscadores',
            'focus_keyword' => 'palabra clave',
            'canonical_url' => 'URL canónica',
            'published_at' => 'fecha de publicación',
            'news_title' => 'título de la noticia',
            'news_body' => 'descripción de la noticia',
        ]);

        // Un artículo recién empezado puede no tener texto todavía.
        $validated['content'] = (string) ($validated['content'] ?? '');

        return $validated;
    }

    private function message(BlogPost $post, bool $wasPublished): string
    {
        return match (true) {
            $post->isPublished() && ! $wasPublished => $post->announce_in_news
                ? 'El artículo quedó publicado, y avisado en Noticias.'
                : 'El artículo quedó publicado.',
            $post->isPublished() => 'Los cambios del artículo quedaron publicados.',
            $wasPublished => 'El artículo volvió a borrador: ya no se ve en el sitio.',
            default => 'El borrador quedó guardado.',
        };
    }
}
