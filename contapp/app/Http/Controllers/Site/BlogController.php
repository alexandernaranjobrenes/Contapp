<?php

namespace App\Http\Controllers\Site;

use App\Domains\Blog\Models\BlogCategory;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Blog\Models\BlogPostSlug;
use App\Domains\Blog\Support\BlogPage;
use App\Domains\Site\Support\Seo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * El blog del sitio público (CLAUDE.md secc. 41): el índice, las páginas de
 * cada categoría, cada artículo y el feed RSS. Solo lo publicado.
 */
class BlogController extends Controller
{
    public const PER_PAGE = 9;

    public function index(Request $request): View
    {
        return $this->listing($request, null);
    }

    public function category(Request $request, string $slug): View
    {
        return $this->listing($request, BlogCategory::query()->where('slug', $slug)->firstOrFail());
    }

    /**
     * Un artículo publicado. Por una dirección que tuvo antes, un 301 a la de
     * ahora: así no se pierde lo que un buscador ya indexó.
     */
    public function show(string $slug): View|RedirectResponse
    {
        $post = BlogPost::query()->published()->where('slug', $slug)->first();

        if ($post === null) {
            $previous = BlogPostSlug::query()->with('post')->where('slug', $slug)->first();

            abort_unless($previous?->post?->isPublished(), 404);

            return redirect()->route('blog.show', $previous->post->slug, 301);
        }

        return view('site.blog.show', BlogPage::article($post));
    }

    public function feed(): Response
    {
        $posts = BlogPost::query()
            ->published()
            ->where('noindex', false)
            ->with(['category', 'cover'])
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return response()
            ->view('site.blog.feed', ['posts' => $posts], 200)
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    private function listing(Request $request, ?BlogCategory $category): View
    {
        $posts = BlogPost::query()
            ->published()
            ->with(['category', 'cover'])
            ->when($category, fn ($query) => $query->where('category_id', $category->id))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: 'pagina');

        // Una página después de la última no existe.
        abort_if($posts->currentPage() > 1 && $posts->isEmpty(), 404);

        $base = $category ? Seo::route('blog.category', $category->slug) : Seo::route('blog.index');
        $pageUrl = fn (int $page) => $page <= 1 ? $base : $base.'?pagina='.$page;
        $page = $posts->currentPage();

        $title = $category
            ? $category->name.' | Blog de Pivox'
            : 'Blog de Pivox: contabilidad e impuestos en Costa Rica';

        $crumbs = [['Inicio', Seo::url('/')], ['Blog', Seo::route('blog.index')]];
        if ($category) {
            $crumbs[] = [$category->name, $base];
        }

        $seo = new Seo(
            title: Seo::title($page > 1 ? "{$title} (página {$page})" : $title),
            description: $category?->description
                ?: ($category
                    ? "Artículos sobre {$category->name} en el blog de Pivox."
                    : 'Artículos sobre contabilidad, impuestos, facturación electrónica y planillas en Costa Rica, y las novedades de Pivox.'),
            canonical: $pageUrl($page),
            schema: [Seo::organization(), BlogPage::blogSchema($posts->items(), $category, $base), Seo::breadcrumbs($crumbs)],
            prev: $page > 1 ? $pageUrl($page - 1) : null,
            next: $posts->hasMorePages() ? $pageUrl($page + 1) : null,
        );

        $categories = BlogCategory::query()
            ->whereHas('posts', fn ($query) => $query->published())
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderBy('name')
            ->get();

        return view('site.blog.index', [
            'seo' => $seo,
            'posts' => $posts,
            'category' => $category,
            'categories' => $categories,
        ]);
    }
}
